<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\AuditLog;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Services\Electo\Normalizer;
use App\Support\MigracionFlags;
use Illuminate\Support\Facades\DB;

// "Diferente en Electo" of a ficha's notice: keep our value ('aqui') or write Electo's ('electo', through the same
// rules as saving the ficha), and the difference stops showing. A difference already resolved answers the same.
class ResolveElectoDiferencia
{
    use ScopesTerritory;

    public const ACCIONES = ['aqui', 'electo'];

    public const VALOR_INVALIDO = 'El valor de Electo no es válido para este campo.';

    // Set by handle(): a rename that could not reach the linked electoral person.
    public ?string $warning = null;

    public function __construct(private readonly UpdateVotante $updateVotante) {}

    // [whether the difference was there, the value written (only for 'electo')].
    public function handle(string $liderId, string $campo, string $accion): array
    {
        $this->warning = null;
        if (! isset(MigracionFlags::DIFERENCIAS[$campo])) {
            throw new BusinessRuleException('Este dato no tiene diferencias con Electo que se puedan resolver aquí.');
        }

        return DB::transaction(function () use ($liderId, $campo, $accion) {
            $row = DB::table('lideres')->where('id', $liderId)->lockForUpdate()->first();
            if (! $row) {
                throw new BusinessRuleException('No encontrado.', 404);
            }
            $this->authorizeLiderRow($row, true);

            $flags = MigracionFlags::hasColumn() ? MigracionFlags::decode($row->migracion_flags) : [];
            $contradicciones = is_array($flags['contradicciones'] ?? null) ? $flags['contradicciones'] : [];
            if (! array_key_exists($campo, $contradicciones)) {
                return [false, null];
            }

            $valor = null;
            if ($accion === 'electo') {
                if (in_array($campo, MigracionFlags::SOLO_EDITANDO, true)) {
                    throw new BusinessRuleException('Este dato solo se cambia editando la ficha.');
                }
                $valor = MigracionFlags::valorElecto($campo, $contradicciones[$campo]);
                if ($valor === null) {
                    throw new BusinessRuleException(self::VALOR_INVALIDO);
                }
                $this->keepLocalContact($row, $campo);
                $this->updateVotante->handle($liderId, [$campo => $valor]);
                $this->warning = $this->updateVotante->warning;
            }
            MigracionFlags::quitarDiferencia($liderId, $campo);

            AuditLog::create([
                'user_id' => request()->user()?->id,
                'action' => 'ELECTO_DIFERENCIA_RESUELTA',
                'entity_type' => 'lider',
                'entity_id' => $liderId,
                'metadata' => ['campo' => $campo, 'accion' => $accion],
                // What Electo's value replaced, to undo it (AuditLog masks personal fields per config/audit.php).
                'before_state' => $accion === 'electo' ? $this->updateVotante->before ?: null : null,
                'after_state' => $accion === 'electo' ? $this->updateVotante->after ?: null : null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return [true, $valor];
        });
    }

    // Electo's telefono/email replaces ours on the ficha: ours stays as a contact (not principal) unless it already is one.
    private function keepLocalContact(object $row, string $campo): void
    {
        $actual = trim((string) ($row->{$campo} ?? ''));
        if (! in_array($campo, ['telefono', 'email'], true) || $actual === '') {
            return;
        }
        $key = fn (?string $v) => $campo === 'email' ? mb_strtolower(trim((string) $v)) : preg_replace('/\D/', '', (string) $v);
        $tipos = $campo === 'email' ? ['email'] : MigracionFlags::PHONE_CONTACTS;
        $yaEsta = DB::table('lider_contactos')->where('lider_id', $row->id)->whereIn('tipo', $tipos)->pluck('valor')
            ->contains(fn ($valor) => $key($valor) === $key($actual));
        if ($yaEsta) {
            return;
        }
        $tipo = $campo === 'email' ? 'email' : (Normalizer::isMobile($key($actual)) ? 'celular' : 'fijo');
        LiderContacto::create(['lider_id' => $row->id, 'tipo' => $tipo, 'valor' => $actual, 'principal' => false, 'estado' => 'activo']);
    }

    // The ficha's notice after the change (null when nothing is left to show).
    public function aviso(string $liderId): ?array
    {
        $lider = Lider::find($liderId);

        return $lider ? MigracionFlags::forFicha($lider) : null;
    }
}
