<?php

namespace App\Services\Agent;

use App\Http\Controllers\EventoCompromisoController;
use App\Http\Controllers\EventoIntegracionController;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Controllers\OrganizacionController;
use App\Models\LiderSeguimiento;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\EventScope;
use App\Support\ContactDataPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

// The ficha of ONE person for the WhatsApp agent: what the web panel shows (MapaPoliticoController::persona and the
// eventos, empresas and compromisos panels, each with its own permission), with the person's own contact data even
// without autoriza_datos. Other persons listed in it (referidos, responsables) keep theirs only if they authorized.
class AgentFicha
{
    public const SEGUIMIENTOS = 10;

    private const OWN_CONTACT_FIELDS = ['cedula', 'telefono', 'email', 'direccion', 'observacion'];

    // [the ficha, what it costs in the daily contact quota]: 1 for the person's own data or a nexo's, plus 1 for each
    // other person (referido, responsable) whose contact data it hands out.
    public function build(Request $request, string $id): array
    {
        $user = $request->user();
        // An electoral person is only on the map: Votantes alone never reaches one.
        if (! $user->hasPermission('view.mapa') && DB::table('persons')->where('id', $id)->exists()) {
            abort(403, 'No tienes permiso para esta consulta.');
        }

        $data = app(MapaPoliticoController::class)->persona($id)->getData(true);
        unset($data['partidos'], $data['foto']);

        $liderId = $data['source'] === 'lider' ? $data['id'] : null;
        $data['datos_autorizados'] = $liderId !== null && ContactDataPolicy::authorized((object) ['autoriza_datos' => $data['autoriza_datos'] ?? null]);
        $data['coincidencia_por_nombre'] = $data['source'] === 'persona' && self::ownData($data);
        $data['cumple_mm_dd'] = self::birthday($data['fecha_nacimiento'] ?? null);

        // Other persons whose contact data goes out, by id: each counts once in the quota, the ficha's own person never.
        $otros = [];
        if (isset($data['referidos'])) {
            $authorized = AuthorizedContacts::of(array_column($data['referidos'], 'id'));
            $data['referidos'] = array_map(function (array $r) use ($authorized, &$otros) {
                $r = AuthorizedContacts::filter($r, $authorized, ['cedula', 'telefono'], 'id', $delivered);
                if ($delivered) {
                    $otros[$r['id']] = true;
                }

                return $r;
            }, $data['referidos']);
        }

        $data['seguimientos'] = $liderId ? $this->seguimientos($liderId) : null;
        $data['eventos'] = $liderId && EventScope::canSeeAttendees($user)
            ? app(EventoIntegracionController::class)->persona($liderId)->getData(true)['data']
            : null;
        $data['organizaciones'] = $liderId && OrganizacionScope::canView($user)
            ? app(OrganizacionController::class)->persona($liderId)->getData(true)['data']
            : null;
        $data['compromisos'] = $liderId && (EventScope::canSeeAttendees($user) || OrganizacionScope::canView($user))
            ? $this->compromisos($liderId, $otros)
            : null;
        unset($otros[$data['id']]);

        return [$data, (self::ownData($data) || self::nexoData($data) ? 1 : 0) + count($otros)];
    }

    // Whether the ficha carries contact data of the person itself.
    private static function ownData(array $data): bool
    {
        foreach (self::OWN_CONTACT_FIELDS as $field) {
            if (filled($data[$field] ?? null)) {
                return true;
            }
        }

        return ! empty($data['contactos']);
    }

    private static function nexoData(array $data): bool
    {
        foreach ($data['nexos'] ?? [] as $nexo) {
            if (filled($nexo['cedula'] ?? null) || filled($nexo['telefono'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    // The sections beyond the map's ficha that came with it (for the audit: names only).
    public static function secciones(array $data): array
    {
        return array_values(array_filter(['seguimientos', 'eventos', 'organizaciones', 'compromisos'], fn (string $s) => $data[$s] !== null));
    }

    private static function birthday(mixed $fecha): ?string
    {
        if (! is_string($fecha) || $fecha === '') {
            return null;
        }
        try {
            return Carbon::parse(substr($fecha, 0, 10))->format('m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function seguimientos(string $liderId): array
    {
        return [
            'total' => LiderSeguimiento::where('lider_id', $liderId)->count(),
            'items' => LiderSeguimiento::recientes($liderId, self::SEGUIMIENTOS)->get()
                ->map(fn (LiderSeguimiento $s) => [
                    'tipo' => $s->tipo,
                    'nota' => $s->nota,
                    'fecha' => $s->fecha?->toIso8601String(),
                    'user' => $s->user?->name,
                ])->all(),
        ];
    }

    // The web's compromisos of the ficha, read-only: without "editable" and with each responsable's phone only if
    // that responsable is a lider who authorized their data, or the ficha's own person (already delivered above).
    private function compromisos(string $liderId, array &$otros): array
    {
        $items = app(EventoCompromisoController::class)->persona($liderId)->getData(true)['data'];
        $authorized = AuthorizedContacts::of(array_merge([], ...array_map(fn (array $c) => array_column($c['responsables'], 'lider_id'), $items)));
        $authorized[$liderId] = true;

        return array_map(function (array $c) use ($authorized, &$otros) {
            unset($c['editable']);
            $c['responsables'] = array_map(function (array $r) use ($authorized, &$otros) {
                $r = AuthorizedContacts::filter($r, $authorized, ['telefono'], 'lider_id', $delivered);
                if ($delivered) {
                    $otros[$r['lider_id']] = true;
                }

                return $r;
            }, $c['responsables']);

            return $c;
        }, $items);
    }
}
