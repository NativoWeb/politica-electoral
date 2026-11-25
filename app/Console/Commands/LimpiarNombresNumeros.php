<?php

namespace App\Console\Commands;

use App\Actions\Votantes\UpdateVotante;
use App\Models\AuditLog;
use App\Models\Lider;
use App\Services\Electo\Normalizer;
use App\Support\MigracionFlags;
use App\Support\NombreFicha;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Cleans the fichas whose name carries a number at its start or end: the ficha's own cédula is removed from the name;
// a valid celular is removed and kept (in telefono when the ficha has none, else as a contact). Anything else (short
// digits, another number, digits in the middle, a name that would be left empty) is not touched and is listed by id and
// shape only. The name is saved through UpdateVotante (same normalization, rename of the linked electoral person,
// clearCompleted). Every change goes to limpieza_cambios under one batch (lote) that --revertir undoes.
class LimpiarNombresNumeros extends Command
{
    protected $signature = 'data:limpiar-nombres-numeros
        {--dry-run : Solo muestra lo que haría, no guarda nada}
        {--revertir= : Lote que imprimió una corrida anterior: lo deshace}';

    protected $description = 'Remove the cédula or celular typed into a ficha name (reversible by batch)';

    public const PAGE = 500;

    public const MIN_DIGITOS = 7;

    private const LABEL = '(?:C\.?\s?C\.?|C[EÉ]D(?:ULA)?\.?|CEL(?:ULAR)?\.?|TEL(?:[EÉ]FONO)?\.?)';

    private const NUM = '\d(?:[\d.\s\'\-]*\d)?';

    private const ACCIONES = ['cedula_quitada', 'celular_a_telefono', 'celular_a_contactos', 'celular_ya_registrado'];

    public const MOTIVOS = [
        'digitos_cortos' => 'Dígitos cortos (error de digitación)',
        'otro_numero' => 'Número que no es su cédula ni un celular',
        'numero_en_medio' => 'Número largo en medio del nombre',
        'quedan_digitos' => 'Quedarían dígitos en el nombre',
        'quedaria_vacio' => 'El nombre quedaría vacío',
        'solo_numeros' => 'El nombre son solo números',
    ];

    private ?string $lote = null;

    public function handle(UpdateVotante $updater): int
    {
        $revertir = $this->option('revertir');
        if ($revertir !== null) {
            return $this->revertir((string) $revertir);
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->lote = null;
        $counts = array_fill_keys(['revisadas', 'limpiadas', ...self::ACCIONES, 'persona_renombrada', 'persona_ambigua', ...array_keys(self::MOTIVOS)], 0);
        $revisar = [];

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            NombreFicha::whereConNumeros(DB::table('lideres'))
                ->select(['id', 'nombre', 'cedula', 'telefono', 'campaign_id', 'geographic_unit_id'])
                ->chunkById(self::PAGE, function ($rows) use ($dryRun, $updater, &$counts, &$revisar) {
                    foreach ($rows as $row) {
                        $counts['revisadas']++;
                        $plan = self::clasificar((string) $row->nombre, $row->cedula);
                        if ($plan['motivo'] !== null) {
                            $counts[$plan['motivo']]++;
                            $revisar[] = [$row->id, self::MOTIVOS[$plan['motivo']], NombreFicha::patron($row->nombre)];

                            continue;
                        }
                        $counts['limpiadas']++;
                        $this->aplicar($row, $plan, $dryRun, $updater, $counts);
                    }
                });

            if ($dryRun) {
                DB::rollBack();
            } else {
                if ($this->lote) {
                    AuditLog::create([
                        'user_id' => null,
                        'action' => 'data.limpiar_nombres_numeros',
                        'entity_type' => 'limpieza_lote',
                        'entity_id' => $this->lote,
                        'metadata' => ['command' => 'data:limpiar-nombres-numeros', 'lote' => $this->lote, 'resumen' => $counts],
                    ]);
                }
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->reporte($counts, $revisar);
        if ($dryRun) {
            $this->warn('DRY-RUN: no se guardó nada.');
        } elseif ($this->lote) {
            $this->info("Nombres limpiados. Lote: {$this->lote} (data:limpiar-nombres-numeros --revertir={$this->lote} lo deshace).");
        } else {
            $this->info('Sin cambios: no se creó lote.');
        }

        return self::SUCCESS;
    }

    // ['nombre' => clean name, 'numeros' => [['tipo' => cedula|celular, 'valor' => digits]], 'motivo' => null] when the
    // name can be cleaned; otherwise 'motivo' says why it is left as it is.
    public static function clasificar(string $nombre, ?string $cedula): array
    {
        $resto = UpdateVotante::normalizeName($nombre);
        $tokens = [];
        if (preg_match('/^[\s(]*(?:'.self::LABEL.'[\s.:#\-]*)?\+?('.self::NUM.')(.*)$/iu', $resto, $m)) {
            $tokens[] = $m[1];
            $resto = $m[2];
        }
        if ($resto !== '' && preg_match('/^(.*?)[\s,;:\/(\-]*(?:(?<![^\s,;:\/(\-])'.self::LABEL.'[\s.:#\-]*)?\+?('.self::NUM.')[\s).]*$/iu', $resto, $m)) {
            $tokens[] = $m[2];
            $resto = $m[1];
        }
        $resto = UpdateVotante::normalizeName(trim($resto, " \t,;:/()-."));
        $sinLetras = preg_match('/\p{L}/u', $nombre) !== 1;
        $revisar = fn (string $motivo) => ['nombre' => null, 'numeros' => [], 'motivo' => $sinLetras && $motivo !== 'quedaria_vacio' ? 'solo_numeros' : $motivo];

        if ($tokens === []) {
            preg_match_all('/[0-9]+/', $nombre, $runs);

            return $revisar(max(array_map('strlen', $runs[0]) ?: [0]) >= self::MIN_DIGITOS ? 'numero_en_medio' : 'digitos_cortos');
        }

        $cedulaKey = Normalizer::cedulaKey($cedula);
        $numeros = [];
        foreach ($tokens as $token) {
            $digits = preg_replace('/\D/', '', $token);
            if (strlen($digits) < self::MIN_DIGITOS) {
                return $revisar('digitos_cortos');
            }
            $phone = Normalizer::phone($digits);
            if ($cedulaKey !== null && ltrim($digits, '0') === $cedulaKey) {
                $numeros[] = ['tipo' => 'cedula', 'valor' => $digits];
            } elseif ($phone !== null && Normalizer::isMobile($phone)) {
                $numeros[] = ['tipo' => 'celular', 'valor' => $phone];
            } else {
                return $revisar('otro_numero');
            }
        }
        if (NombreFicha::tieneNumeros($resto)) {
            return $revisar('quedan_digitos');
        }
        if ($resto === '') {
            return $revisar('quedaria_vacio');
        }

        return ['nombre' => $resto, 'numeros' => $numeros, 'motivo' => null];
    }

    private function aplicar(object $row, array $plan, bool $dryRun, UpdateVotante $updater, array &$counts): void
    {
        $data = ['nombre' => $plan['nombre']];
        $telefono = $row->telefono !== null && trim($row->telefono) !== '' ? $row->telefono : null;
        $telefonoKey = Normalizer::phone($telefono);
        $contactos = [];
        foreach ($plan['numeros'] as $n) {
            if ($n['tipo'] === 'cedula') {
                $counts['cedula_quitada']++;
            } elseif ($telefono === null && ! isset($data['telefono'])) {
                $data['telefono'] = $n['valor'];
                $counts['celular_a_telefono']++;
            } elseif ($n['valor'] === $telefonoKey || ($data['telefono'] ?? null) === $n['valor'] || $this->tieneContacto($row->id, $n['valor'])) {
                $counts['celular_ya_registrado']++;
            } else {
                $contactos[$n['valor']] = true;
                $counts['celular_a_contactos']++;
            }
        }
        if ($dryRun) {
            // What the real run would do with the linked electoral person (UpdateVotante renames only a single one).
            $vinculadas = count($updater->linkedPersonIds($row));
            $counts[$vinculadas === 1 ? 'persona_renombrada' : 'persona_ambigua'] += $vinculadas > 0 ? 1 : 0;

            return;
        }

        $this->lote ??= (string) Str::uuid();
        $lider = Lider::query()->whereKey($row->id)->lockForUpdate()->first();
        $flagsAntes = MigracionFlags::hasColumn() ? DB::table('lideres')->where('id', $row->id)->value('migracion_flags') : null;
        $persona = null;
        if (count($personas = $updater->linkedPersonIds($lider)) === 1) {
            $persona = DB::table('persons')->where('id', $personas->first())->first(['id', 'full_name']);
        }

        foreach (array_keys($contactos) as $valor) {
            $contacto = $lider->contactos()->create(['tipo' => 'celular', 'valor' => $valor, 'principal' => false, 'estado' => 'activo']);
            $this->registrar('lider_contactos', $contacto->id, $row->id, 'contacto', null, $valor);
        }
        $updater->handle($row->id, $data);
        if ($updater->warning !== null) {
            $counts['persona_ambigua']++;
        }

        foreach ($updater->before as $campo => $antes) {
            if (in_array($campo, ['nombre', 'telefono'], true)) {
                $this->registrar('lideres', $row->id, $row->id, $campo, $antes, $updater->after[$campo] ?? null);
            }
        }
        if ($persona) {
            $ahora = DB::table('persons')->where('id', $persona->id)->value('full_name');
            if ($ahora !== $persona->full_name) {
                $counts['persona_renombrada']++;
                $this->registrar('persons', $persona->id, $row->id, 'full_name', $persona->full_name, $ahora);
            }
        }
        if (MigracionFlags::hasColumn()) {
            $flagsDespues = DB::table('lideres')->where('id', $row->id)->value('migracion_flags');
            if ($flagsDespues !== $flagsAntes) {
                $this->registrar('lideres', $row->id, $row->id, 'migracion_flags', $flagsAntes, $flagsDespues);
            }
        }
    }

    private function tieneContacto(string $liderId, string $phone): bool
    {
        return DB::table('lider_contactos')->where('lider_id', $liderId)->whereIn('tipo', MigracionFlags::PHONE_CONTACTS)
            ->pluck('valor')->contains(fn ($v) => Normalizer::phone($v) === $phone);
    }

    private function registrar(string $tabla, string $registroId, string $liderId, string $campo, ?string $antes, ?string $despues): void
    {
        DB::table('limpieza_cambios')->insert([
            'lote' => $this->lote, 'tabla' => $tabla, 'registro_id' => $registroId, 'lider_id' => $liderId,
            'campo' => $campo, 'antes' => $antes, 'despues' => $despues, 'created_at' => now(),
        ]);
    }

    // Each change goes back to its previous value only while the current value is still the one written; a value that
    // changed afterwards is left as it is and counted.
    private function revertir(string $lote): int
    {
        if (! Str::isUuid($lote)) {
            $this->error('--revertir debe ser el lote (UUID) que imprimió data:limpiar-nombres-numeros.');

            return self::FAILURE;
        }
        if (! DB::table('limpieza_cambios')->where('lote', $lote)->exists()) {
            $this->error("No existe el lote {$lote}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['restaurados' => 0, 'omitidos' => 0, 'ya_restaurados' => 0, 'no_existen' => 0];

        DB::transaction(function () use ($lote, $dryRun, &$counts) {
            $cambios = DB::table('limpieza_cambios')->where('lote', $lote)->orderByDesc('id')->get();
            foreach ($cambios->chunk(self::PAGE) as $pagina) {
                foreach ($pagina as $c) {
                    $estado = $this->revertirCambio($c, $dryRun);
                    $counts[$estado]++;
                }
            }
            if (! $dryRun) {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'data.revertir_limpieza_nombres',
                    'entity_type' => 'limpieza_lote',
                    'entity_id' => $lote,
                    'metadata' => ['command' => 'data:limpiar-nombres-numeros --revertir', 'lote' => $lote, 'resumen' => $counts],
                ]);
            }
        });

        $this->table(['Restaurados', 'Omitidos (cambiaron después)', 'Ya estaban como antes', 'Ya no existen'],
            [[$counts['restaurados'], $counts['omitidos'], $counts['ya_restaurados'], $counts['no_existen']]]);
        $dryRun ? $this->warn('DRY-RUN: no se guardó nada.') : $this->info("Lote {$lote} revertido.");

        return self::SUCCESS;
    }

    private function revertirCambio(object $c, bool $dryRun): string
    {
        if ($c->tabla === 'lider_contactos') {
            $valor = DB::table('lider_contactos')->where('id', $c->registro_id)->value('valor');
            if ($valor === null) {
                return 'ya_restaurados';
            }
            if ($valor !== $c->despues) {
                return 'omitidos';
            }
            if (! $dryRun) {
                DB::table('lider_contactos')->where('id', $c->registro_id)->where('valor', $c->despues)->delete();
            }

            return 'restaurados';
        }

        $columna = $c->tabla === 'persons' ? 'full_name' : $c->campo;
        $fila = DB::table($c->tabla)->where('id', $c->registro_id)->first(['id', $columna]);
        if (! $fila) {
            return 'no_existen';
        }
        if ($fila->{$columna} === $c->antes) {
            return 'ya_restaurados';
        }
        if ($fila->{$columna} !== $c->despues) {
            return 'omitidos';
        }
        if (! $dryRun) {
            if ($c->tabla === 'persons') {
                UpdateVotante::renamePerson($c->registro_id, (string) $c->antes);
            } else {
                DB::table($c->tabla)->where('id', $c->registro_id)->where($columna, $c->despues)
                    ->update([$columna => $c->antes, 'updated_at' => now()]);
            }
        }

        return 'restaurados';
    }

    private function reporte(array $counts, array $revisar): void
    {
        $this->info("Fichas con números en el nombre: {$counts['revisadas']}. Limpiadas: {$counts['limpiadas']}.");
        $this->table(['Regla', 'Fichas'], [
            ['Cédula propia quitada del nombre', $counts['cedula_quitada']],
            ['Celular pasado a Teléfono', $counts['celular_a_telefono']],
            ['Celular agregado a contactos', $counts['celular_a_contactos']],
            ['Celular que ya estaba registrado (solo se quitó del nombre)', $counts['celular_ya_registrado']],
            ['Persona electoral vinculada renombrada', $counts['persona_renombrada']],
            ['Persona electoral no renombrada (varias con ese nombre)', $counts['persona_ambigua']],
            ...array_map(fn ($m, $label) => ["No se tocó: {$label}", $counts[$m]], array_keys(self::MOTIVOS), self::MOTIVOS),
        ]);
        if ($revisar !== []) {
            $this->line('Para revisar a mano (sin datos personales: A = letras, 9 = dígitos):');
            $this->table(['Ficha (id)', 'Motivo', 'Patrón'], $revisar);
        }
    }
}
