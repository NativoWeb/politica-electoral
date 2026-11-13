<?php

namespace App\Services\Empresarios;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Organizacion;
use App\Models\OrganizacionMiembro;
use App\Models\User;
use App\Services\Eventos\Actas\ActaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Writes of Empresarios y gremios (docs/empresarios.md): the organization with its gremios and its representative,
// and its members. Everything in the user's OWN campaign; fichas linked or created always in the organization's.
class OrganizacionService
{
    use ScopesTerritory;

    public const FIELDS = ['tipo', 'nombre', 'nit', 'nit_dv', 'representante_nombre', 'representante_cedula', 'representante_celular',
        'telefono', 'correo', 'sitio_web', 'sector_economico_id', 'sector_id', 'departamento', 'municipio_id', 'direccion', 'barrio',
        'empleados', 'empleados_rango', 'observaciones', 'is_active'];

    public function __construct(private ActaService $actas) {}

    // $data comes validated by OrganizacionRequest (nit already split into nit + nit_dv). Returns the organization
    // and the names of the fields that changed (for the audit, never the values).
    public function save(?Organizacion $org, array $data, User $user): array
    {
        $campaignId = $org?->campaign_id ?? $this->campaign()->ownId();
        $this->assertNitFree($campaignId, $data['nit'] ?? null, $org?->id);
        $this->assertCatalog($campaignId, $data['sector_economico_id'] ?? null, 'sector_economico', $org?->sector_economico_id);
        $this->assertCatalog($campaignId, $data['sector_id'] ?? null, 'sector', $org?->sector_id);
        $this->assertMunicipio($data['municipio_id'] ?? null);
        $tipo = $data['tipo'];
        $gremioIds = $tipo === 'empresa' ? $this->gremios($campaignId, $data['gremio_ids'] ?? [], $org?->id) : [];
        // The representative already linked is kept as it is (no territory re-check) unless the request changes it.
        $representante = array_key_exists('representante_lider_id', $data) && $data['representante_lider_id'] !== $org?->representante_lider_id
            ? $this->representante($campaignId, $data['representante_lider_id'])
            : $org?->representante_lider_id;

        try {
            return DB::transaction(function () use ($org, $data, $user, $campaignId, $tipo, $gremioIds, $representante) {
                $org ??= (new Organizacion)->forceFill(['campaign_id' => $campaignId, 'created_by' => $user->id]);
                $before = $org->exists ? $org->only(self::FIELDS) + ['representante_lider_id' => $org->representante_lider_id] : [];
                $previousRep = $org->representante_lider_id;

                $values = [];
                foreach (self::FIELDS as $field) {
                    $values[$field] = array_key_exists($field, $data) ? self::clean($data[$field]) : ($org->exists ? $org->{$field} : null);
                }
                $values['tipo'] = $tipo;
                $values['is_active'] = (bool) ($data['is_active'] ?? ($org->exists ? $org->is_active : true));
                $values['departamento'] ??= 'Santander';
                $org->forceFill($values + ['representante_lider_id' => $representante, 'updated_by' => $user->id])->save();

                if ($tipo === 'empresa' && array_key_exists('gremio_ids', $data)) {
                    $current = DB::table('organizacion_gremios')->where('empresa_id', $org->id)->pluck('gremio_id')->all();
                    DB::table('organizacion_gremios')->where('empresa_id', $org->id)->whereNotIn('gremio_id', $gremioIds)->delete();
                    foreach (array_diff($gremioIds, $current) as $gremioId) {
                        DB::table('organizacion_gremios')->insert(['id' => (string) Str::uuid(), 'empresa_id' => $org->id, 'gremio_id' => $gremioId, 'created_at' => now()]);
                    }
                }
                if ($tipo === 'gremio') {
                    // A gremio is not affiliated to gremios.
                    DB::table('organizacion_gremios')->where('empresa_id', $org->id)->delete();
                } else {
                    // An empresa (also a former gremio) has no affiliated empresas.
                    DB::table('organizacion_gremios')->where('gremio_id', $org->id)->delete();
                }
                if ($previousRep !== $representante) {
                    if ($previousRep) {
                        OrganizacionMiembro::where('organizacion_id', $org->id)->where('lider_id', $previousRep)->where('rol', 'representante_legal')->delete();
                    }
                    if ($representante) {
                        $this->ensureMember($org, $representante, 'representante_legal', null, $user);
                    }
                }

                $after = $org->only(self::FIELDS) + ['representante_lider_id' => $org->representante_lider_id];
                $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));

                return [$org, $changed];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nit' => 'Ya existe una organización con ese NIT en el grupo político.']);
        }
    }

    // POST member: an existing ficha (lider_id, of the organization's campaign, active, in the user's territory) or a
    // person typed by hand (the same cédula in the campaign is linked; otherwise a new ficha is created).
    // Returns [member, ficha created].
    public function addMember(Organizacion $org, array $data, User $user): array
    {
        $created = false;
        if (! empty($data['lider_id'])) {
            $liderId = $this->allowedLider($org->campaign_id, $data['lider_id']);
            if (! $liderId) {
                throw ValidationException::withMessages(['lider_id' => 'Esa persona no es un líder activo del grupo político en su territorio.']);
            }
        } else {
            $municipio = $data['municipio'] ?? null;
            [$liderId, $created] = DB::transaction(fn () => $this->actas->findOrCreateFicha($org, [
                'nombre' => trim(preg_replace('/\s+/u', ' ', (string) $data['nombre'])),
                'telefono' => $data['telefono'] ?? null,
                'cedula' => $data['cedula'] ?? null,
                'municipio' => $municipio,
            ]));
        }
        if (OrganizacionMiembro::where('organizacion_id', $org->id)->where('lider_id', $liderId)->where('rol', $data['rol'])->exists()) {
            throw ValidationException::withMessages(['rol' => 'Esa persona ya es miembro con ese rol.']);
        }

        return [$this->ensureMember($org, $liderId, $data['rol'], $data['nota'] ?? null, $user), $created];
    }

    public function updateMember(OrganizacionMiembro $miembro, array $data): OrganizacionMiembro
    {
        if ($data['rol'] !== $miembro->rol && OrganizacionMiembro::where('organizacion_id', $miembro->organizacion_id)
            ->where('lider_id', $miembro->lider_id)->where('rol', $data['rol'])->exists()) {
            throw ValidationException::withMessages(['rol' => 'Esa persona ya es miembro con ese rol.']);
        }
        $miembro->forceFill(['rol' => $data['rol'], 'nota' => self::clean($data['nota'] ?? null)])->save();

        return $miembro;
    }

    public function ensureMember(Organizacion $org, string $liderId, string $rol, ?string $nota, User $user): OrganizacionMiembro
    {
        return OrganizacionMiembro::where('organizacion_id', $org->id)->where('lider_id', $liderId)->where('rol', $rol)->first()
            ?? tap((new OrganizacionMiembro)->forceFill([
                'organizacion_id' => $org->id, 'lider_id' => $liderId, 'rol' => $rol, 'nota' => self::clean($nota), 'created_by' => $user->id,
            ]))->save();
    }

    // The organization with that NIT in the campaign (not deleted), for the Excel load's duplicates.
    public static function byNit(string $campaignId, string $nit, ?string $exceptId = null): ?object
    {
        return DB::table('organizaciones')->where('campaign_id', $campaignId)->where('nit', $nit)->whereNull('deleted_at')
            ->when($exceptId, fn ($q, $v) => $q->where('id', '!=', $v))->first(['id', 'nombre', 'municipio_id']);
    }

    private function assertNitFree(string $campaignId, ?string $nit, ?string $exceptId): void
    {
        if (! $nit || ! ($other = self::byNit($campaignId, $nit, $exceptId))) {
            return;
        }
        $allowed = $this->allowedGeoIds();
        $message = $allowed !== null && ! in_array($other->municipio_id, $allowed, true)
            ? 'Ya existe una organización con ese NIT en otro territorio del grupo político.'
            : "Ya existe una organización con ese NIT en el grupo político: {$other->nombre}.";

        throw ValidationException::withMessages(['nit' => $message]);
    }

    // A catalog value of the right kind that the campaign may use: active, shared or its own. The value the
    // organization already had is kept even if it was deactivated meanwhile.
    private function assertCatalog(string $campaignId, ?string $id, string $tipo, ?string $current): void
    {
        if (! $id || $id === $current) {
            return;
        }
        $ok = DB::table('org_catalogos')->where('id', $id)->where('tipo', $tipo)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('campaign_id')->orWhere('campaign_id', $campaignId))->exists();
        if (! $ok) {
            throw ValidationException::withMessages([$tipo.'_id' => 'Elija un valor de la lista.']);
        }
    }

    private function assertMunicipio(?string $id): void
    {
        $allowed = $this->allowedGeoIds();
        if (! $id) {
            if ($allowed !== null) {
                throw ValidationException::withMessages(['municipio_id' => 'Elija el municipio (debe estar dentro de su territorio).']);
            }

            return;
        }
        if (! DB::table('geographic_units')->where('id', $id)->where('type', 'municipality')->exists()) {
            throw ValidationException::withMessages(['municipio_id' => 'Elija un municipio de la lista.']);
        }
        if ($allowed !== null && ! in_array($id, $allowed, true)) {
            throw ValidationException::withMessages(['municipio_id' => 'Ese municipio está fuera de su territorio.']);
        }
    }

    // Gremio ids of the same campaign (not deleted, tipo gremio).
    private function gremios(string $campaignId, array $ids, ?string $selfId): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id) && $id !== $selfId)));
        if ($ids === []) {
            return [];
        }
        $found = DB::table('organizaciones')->where('campaign_id', $campaignId)->where('tipo', 'gremio')->whereNull('deleted_at')
            ->whereIn('id', $ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['gremio_ids' => 'Uno de los gremios elegidos ya no existe en el grupo político.']);
        }

        return $found;
    }

    private function representante(string $campaignId, ?string $liderId): ?string
    {
        if (! $liderId) {
            return null;
        }
        $id = $this->allowedLider($campaignId, $liderId);
        if (! $id) {
            throw ValidationException::withMessages(['representante_lider_id' => 'Esa persona no es un líder activo del grupo político en su territorio.']);
        }

        return $id;
    }

    private function allowedLider(string $campaignId, string $liderId): ?string
    {
        if (! Str::isUuid($liderId)) {
            return null;
        }

        return $this->scopeToTerritory(DB::table('lideres')->where('campaign_id', $campaignId)->where('is_active', true)->where('id', $liderId))->value('id');
    }

    private static function clean($value)
    {
        if (is_string($value)) {
            $value = trim(preg_replace('/[ \t]+/u', ' ', $value));

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
