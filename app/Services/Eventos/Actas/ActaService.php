<?php

namespace App\Services\Eventos\Actas;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\CampaignEvent;
use App\Models\EventActa;
use App\Models\EventActaParticipante;
use App\Models\EventAttendee;
use App\Models\EventCompromiso;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Models\Organizacion;
use App\Models\OrganizacionMiembro;
use App\Models\User;
use App\Services\Eventos\AttendeeMatcher;
use App\Services\Eventos\ImportColumns;
use App\Support\Cedula;
use App\Support\MigracionFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Saves an acta (docs/eventos-api.md §14) of an event or of an organization (docs/empresarios.md). Every participant
// ends up with a ficha in Votantes of the OWNER's campaign (a confirmed suggestion, the attendee's ficha, the same
// cédula, or a new ficha) and, for an event, as an attendee (source 'acta', never twice); for an organization, as a
// member with rol 'otro' when it was not a member yet. Responsables are linked only to a ficha somebody confirmed;
// otherwise they stay as text.
class ActaService
{
    use ScopesTerritory;

    private const CONNECTORS = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'a', 'en', 'el'];

    public const CEDULA_AJENA = 'Esa cédula ya pertenece a otra persona en Votantes: corrija la cédula o deje la que tenía.';

    // Municipios (see loadPlaces), read once per request.
    private ?array $places = null;

    public function __construct(private AttendeeMatcher $matcher) {}

    // ['participantes' => [index => lider], 'responsables' => ["c.r" => lider]]; lider = {id, nombre, municipio,
    // cargo, por: 'cedula'|'telefono'}. Only fichas of the event's campaign inside the user's territory.
    // $municipioActa: the acta's municipio (text), the fallback for a name-only row when neither the row nor the owner has one.
    public function suggest(CampaignEvent|Organizacion $owner, array $participantes, array $responsables, ?string $municipioActa = null): array
    {
        $geo = $this->allowedGeoIds() ?? DB::table('geographic_units')->where('type', 'municipality')->pluck('id')->all();
        $out = ['participantes' => [], 'responsables' => []];

        $rows = [];
        $byName = [];
        $places = null;
        foreach ($participantes as $i => $p) {
            $cedula = Cedula::normalize($p['cedula'] ?? null) ?: null;
            if ($cedula && ($id = $this->liderByCedula($owner, $cedula, true))) {
                $out['participantes'][$i] = ['id' => $id, 'por' => 'cedula'];

                continue;
            }
            // Without cédula nor phone only the name is left: same name in the same municipio (docs §14 "Datos incompletos").
            if (! $cedula && AttendeeMatcher::phones($p['telefono'] ?? '') === []) {
                $places ??= $this->places();
                // As confirming places the new ficha: the row's municipio, else the owner's; the acta's when there is none.
                $place = $this->municipioOf($p['municipio'] ?? null, $places['ids']) ?? $owner->municipio_id ?? $this->municipioOf($municipioActa, $places['ids']);
                $byName[$i] = ['name' => AttendeeMatcher::nameKey($p['nombre'] ?? ''), 'geo' => in_array($place, $geo, true) ? $place : null];

                continue;
            }
            $rows["p{$i}"] = $this->matchRow($p['nombre'] ?? '', $p['telefono'] ?? '', $geo, $cedula);
        }
        foreach ($this->matcher->byName($owner->campaign_id, $byName) as $i => $ids) {
            // Two fichas with the same name: suggesting one would be a guess.
            if (count($ids) === 1) {
                $out['participantes'][$i] = ['id' => $ids[0], 'por' => 'nombre'];
            }
        }
        foreach ($responsables as $key => $r) {
            $rows["r{$key}"] = $this->matchRow($r['nombre'] ?? '', $r['telefono'] ?? '', $geo, null);
        }
        foreach ($this->matcher->suggest($owner->campaign_id, $rows) as $key => $liderId) {
            $index = substr((string) $key, 1);
            $out[$key[0] === 'p' ? 'participantes' : 'responsables'][$index] = ['id' => $liderId, 'por' => 'telefono'];
        }

        $ids = collect($out)->flatten(1)->pluck('id')->unique()->values()->all();
        $info = $ids === [] ? collect() : DB::table('lideres')->whereIn('id', $ids)->get(['id', 'nombre', 'municipio', 'cargo'])->keyBy('id');
        foreach ($out as $group => $items) {
            foreach ($items as $k => $item) {
                $l = $info[$item['id']] ?? null;
                $out[$group][$k] = $item + ['motivo' => $item['por'], 'nombre' => $l?->nombre, 'municipio' => $l?->municipio, 'cargo' => $l?->cargo];
            }
        }

        return $out;
    }

    // Creates ($acta null) or updates a BORRADOR: only its data is stored (the chosen fichas as pending choices);
    // nothing is written to Votantes nor to the attendees until confirm(). Returns the acta.
    public function save(CampaignEvent|Organizacion $owner, ?EventActa $acta, array $data, User $user): EventActa
    {
        return DB::transaction(function () use ($owner, $acta, $data, $user) {
            if ($acta) {
                $acta = EventActa::whereKey($acta->id)->lockForUpdate()->firstOrFail();
                self::assertDraft($acta);
            }
            $acta ??= (new EventActa)->forceFill([
                self::ownerKey($owner) => $owner->id, 'campaign_id' => $owner->campaign_id, 'created_by' => $user->id, 'estado' => EventActa::BORRADOR,
            ]);
            $acta->forceFill([...self::actaFields($data), 'updated_by' => $user->id])->save();

            $this->saveParticipantes($owner, $acta, $data['participantes'] ?? []);
            $this->saveCompromisos($owner, $acta, $data['compromisos'] ?? []);

            return $acta;
        });
    }

    // The acta's own data (header, orden del día, desarrollo, cierre) as it is stored.
    public static function actaFields(array $data): array
    {
        return [
            'titulo' => trim($data['titulo']),
            'fecha' => $data['fecha'] ?? null,
            'municipio' => self::text($data['municipio'] ?? null),
            'sitio' => self::text($data['sitio'] ?? null),
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fin' => $data['hora_fin'] ?? null,
            'introduccion' => self::text($data['introduccion'] ?? null),
            'orden_dia' => array_values(array_filter(array_map(fn ($o) => trim((string) $o), $data['orden_dia'] ?? []), 'strlen')),
            'desarrollo' => array_values(array_filter(array_map(fn ($d) => [
                'titulo' => trim((string) ($d['titulo'] ?? '')),
                'texto' => trim((string) ($d['texto'] ?? '')),
            ], $data['desarrollo'] ?? []), fn ($d) => $d['titulo'] !== '' || $d['texto'] !== '')),
            'cierre' => self::text($data['cierre'] ?? null),
        ];
    }

    // Confirms a borrador: every participant gets its ficha (the pending choice, the attendee's ficha, the same cédula
    // or a new one) and its attendance (source 'acta', never twice) or, for an organization, its membership (rol
    // 'otro' when it had none); responsables keep only a still valid choice.
    // A suggestion nobody answered counts as "no es esta persona". Returns the counts.
    public function confirm(CampaignEvent|Organizacion $owner, EventActa $acta, User $user): array
    {
        $counts = ['participantes' => 0, 'vinculados' => 0, 'fichas_creadas' => 0, 'asistentes_nuevos' => 0, 'compromisos' => 0, 'responsables_vinculados' => 0]
            + ($owner instanceof Organizacion ? ['miembros_nuevos' => 0] : []);

        DB::transaction(function () use ($owner, $acta, $user, &$counts) {
            $acta = EventActa::whereKey($acta->id)->lockForUpdate()->firstOrFail();
            self::assertDraft($acta);

            $rows = EventActaParticipante::where('acta_id', $acta->id)->orderBy('orden')->get();
            $this->linkParticipantes($owner, $rows, $user, $counts);
            $of = self::ownerLabel($owner);

            $compromisos = EventCompromiso::where('acta_id', $acta->id)->with('responsables')->orderBy('orden')->get();
            $responsables = $compromisos->flatMap->responsables;
            $valid = $this->allowedLideres($owner, $responsables->pluck('lider_id')->all(), 'compromisos');
            foreach ($compromisos as $i => $c) {
                $counts['compromisos']++;
                foreach ($c->responsables as $r => $responsable) {
                    if ($responsable->lider_id && ! isset($valid[$responsable->lider_id])) {
                        throw ValidationException::withMessages(["compromisos.{$i}.responsables.{$r}.lider_id" => "La ficha elegida para «{$responsable->nombre}» ya no es un líder activo del grupo político {$of} en su territorio."]);
                    }
                    $counts['responsables_vinculados'] += $responsable->lider_id ? 1 : 0;
                }
            }

            $acta->forceFill(['estado' => EventActa::CONFIRMADA, 'confirmed_at' => now(), 'confirmed_by' => $user->id])->save();
        });

        return $counts;
    }

    // Gives each participant row its ficha (the pending choice, the attendee's ficha, the same cédula or a new one) and
    // its attendance (source 'acta', never twice) or, for an organization, its membership (rol 'otro' when it had none),
    // recording what it created (created_*): that is what deleting the confirmed acta undoes. Used by confirm() and, for
    // the participants a correction adds, by EditConfirmedActa. $rows is keyed by the index errors name
    // ("participantes.N"). Adds to $counts.
    public function linkParticipantes(CampaignEvent|Organizacion $owner, Collection $rows, User $user, array &$counts): void
    {
        $confirmed = $this->allowedLideres($owner, $rows->pluck('lider_id')->all(), 'participantes');
        $isEvent = $owner instanceof CampaignEvent;
        $attendees = $isEvent
            ? EventAttendee::where('event_id', $owner->id)->whereIn('id', $rows->pluck('attendee_id')->filter()->all())->get()->keyBy('id')
            : collect();
        $place = $this->places();
        $of = self::ownerLabel($owner);
        $similar = $this->similarByName($owner, $rows, $place);

        foreach ($rows as $i => $row) {
            $counts['participantes']++;
            $fields = $row->only(['nombre', 'perfil', 'municipio', 'telefono', 'cedula']);
            $attendee = $row->attendee_id ? $attendees->get($row->attendee_id) : null;
            if ($isEvent && $row->attendee_id && ! $attendee) {
                throw ValidationException::withMessages(["participantes.{$i}.attendee_id" => "«{$row->nombre}» ya no es asistente de este evento: quítelo de la lista o escríbalo de nuevo."]);
            }
            if ($row->lider_id && ! isset($confirmed[$row->lider_id])) {
                throw ValidationException::withMessages(["participantes.{$i}.lider_id" => "La ficha elegida para «{$row->nombre}» ya no es un líder activo del grupo político {$of} en su territorio."]);
            }

            $liderId = $row->lider_id ?: $attendee?->lider_id;
            $cedula = $row->cedula ?? $attendee?->cedula;
            // The cédula is looked up in the whole campaign, not only in the user's territory: it is unique per
            // campaign, so creating another ficha would fail, and linking only stores the id (the acta shows what
            // the user typed; the ficha itself keeps its territory checks).
            $liderId ??= $cedula ? $this->liderByCedula($owner, $cedula) : null;
            $created = false;
            if (! $liderId) {
                [$liderId, $created] = $this->createLider($owner, $fields, $place, $similar[$i] ?? []);
            }
            $counts[$created ? 'fichas_creadas' : 'vinculados']++;

            // What this confirmation created is what deleting the confirmed acta undoes.
            if (! $isEvent) {
                $createdMiembro = $this->ensureMember($owner, $liderId, $user);
                $counts['miembros_nuevos'] += $createdMiembro ? 1 : 0;
                $row->forceFill(['lider_id' => $liderId, 'attendee_id' => null, 'created_lider' => $created, 'created_miembro' => $createdMiembro])->save();

                continue;
            }

            $attendee ??= $this->attendeeOf($owner, $liderId, $row->cedula);
            $createdAttendee = false;
            if (! $attendee) {
                $attendee = $this->createAttendee($owner, $fields, $liderId, $created, $user);
                $createdAttendee = true;
                $counts['asistentes_nuevos']++;
            } elseif (! $attendee->lider_id) {
                $attendee->forceFill(['lider_id' => $liderId, 'link_status' => $created ? 'aprobado' : 'vinculado'])->save();
            }
            $row->forceFill(['lider_id' => $liderId, 'attendee_id' => $attendee->id, 'created_lider' => $created, 'created_attendee' => $createdAttendee])->save();
        }
    }

    // Correcting a participant that already has its ficha (EditConfirmedActa): what the ficha does not have yet (cédula,
    // teléfono, municipio) is taken from the acta; nothing is overwritten. A cédula that is another ficha's of the
    // campaign → 422, the fichas are never merged. $createdHere: the ficha was created by this acta, so completing it
    // does not count as later activity (deleting the acta still undoes it). Returns whether the ficha changed.
    public function completeFicha(EventActaParticipante $row, int $i, bool $createdHere): bool
    {
        $ficha = $row->lider_id ? DB::table('lideres')->where('id', $row->lider_id)->first(['id', 'campaign_id', 'cedula', 'telefono', 'geographic_unit_id']) : null;
        if (! $ficha) {
            return false;
        }
        $set = [];
        if ($row->cedula && trim((string) $ficha->cedula) === '') {
            if (DB::table('lideres')->where('campaign_id', $ficha->campaign_id)->where('cedula', $row->cedula)->where('id', '!=', $ficha->id)->exists()) {
                throw ValidationException::withMessages(["participantes.{$i}.cedula" => self::CEDULA_AJENA]);
            }
            $set['cedula'] = $row->cedula;
        }
        $phones = AttendeeMatcher::phones($row->telefono);
        if (trim((string) $ficha->telefono) === '' && ($phones !== [] || $row->telefono)) {
            $set['telefono'] = $phones[0] ?? mb_substr((string) $row->telefono, 0, 30);
        }
        if (! $ficha->geographic_unit_id && ($geo = $this->municipioOf($row->municipio, $this->places()['ids']))) {
            $set += ['geographic_unit_id' => $geo, 'municipio' => $this->places()['names'][$geo]['municipio'], 'provincia' => $this->places()['names'][$geo]['provincia']];
        }
        if ($set === []) {
            return false;
        }
        if (! $createdHere) {
            $set['updated_at'] = now();
        }
        try {
            DB::transaction(fn () => DB::table('lideres')->where('id', $ficha->id)->update($set));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(["participantes.{$i}.cedula" => self::CEDULA_AJENA]);
        }
        MigracionFlags::clearCompleted($ficha->id);

        return true;
    }

    // Deletes a borrador with its participants, compromisos and responsables (cascade) and its file.
    public function delete(EventActa $acta): void
    {
        $path = DB::transaction(function () use ($acta) {
            $acta = EventActa::whereKey($acta->id)->lockForUpdate()->firstOrFail();
            self::assertDraft($acta);
            $path = $acta->archivo_path;
            $acta->delete();

            return $path;
        });
        ActaFiles::delete($path);
    }

    public static function assertDraft(EventActa $acta): void
    {
        if (! $acta->isDraft()) {
            throw ValidationException::withMessages(['acta' => 'El acta ya está confirmada: no se puede editar ni borrar.']);
        }
    }

    // A borrador keeps its rows as they come: the chosen ficha (a pending choice, checked here and again on confirm),
    // the attendee picked from the list and the rejected suggestion, so it is not asked again.
    private function saveParticipantes(CampaignEvent|Organizacion $owner, EventActa $acta, array $items): void
    {
        EventActaParticipante::where('acta_id', $acta->id)->delete();
        $this->writeParticipantes($owner, $acta, array_values($items));
    }

    // Writes a row for each item ([index => item]; the index is its position in the acta and what errors name), checking
    // the chosen ficha and the attendee picked from the list. Returns the rows keyed by index.
    public function writeParticipantes(CampaignEvent|Organizacion $owner, EventActa $acta, array $items): Collection
    {
        $chosen = $this->allowedLideres($owner, array_column($items, 'lider_id'), 'participantes');
        $isEvent = $owner instanceof CampaignEvent;
        $attendeeIds = $isEvent ? array_values(array_filter(array_column($items, 'attendee_id'))) : [];
        $attendees = $attendeeIds === [] ? [] : EventAttendee::where('event_id', $owner->id)->whereIn('id', $attendeeIds)->pluck('id')->all();

        $rows = collect();
        foreach ($items as $i => $item) {
            $nombre = trim(preg_replace('/\s+/u', ' ', (string) $item['nombre']));
            if (! $isEvent) {
                $item['attendee_id'] = null;
            }
            if (! empty($item['attendee_id']) && ! in_array($item['attendee_id'], $attendees, true)) {
                throw ValidationException::withMessages(["participantes.{$i}.attendee_id" => 'Un participante elegido ya no es asistente de este evento.']);
            }
            if (! empty($item['lider_id']) && ! isset($chosen[$item['lider_id']])) {
                throw ValidationException::withMessages(["participantes.{$i}.lider_id" => 'La ficha elegida para «'.$nombre.'» no es un líder activo del grupo político '.self::ownerLabel($owner).' en su territorio.']);
            }
            $row = (new EventActaParticipante)->forceFill([
                'acta_id' => $acta->id,
                'orden' => $i,
                ...self::participanteText($item),
                'lider_id' => $item['lider_id'] ?? null,
                'attendee_id' => $item['attendee_id'] ?? null,
                'sugerencia_rechazada' => self::uuid($item['sugerencia_rechazada'] ?? null),
            ]);
            $row->save();
            $rows[$i] = $row;
        }

        return $rows;
    }

    // What a participant row stores of what the user typed.
    public static function participanteText(array $item): array
    {
        return [
            'nombre' => trim(preg_replace('/\s+/u', ' ', (string) $item['nombre'])),
            'perfil' => self::text($item['perfil'] ?? null),
            'municipio' => self::text($item['municipio'] ?? null),
            'telefono' => self::text($item['telefono'] ?? null),
            'cedula' => Cedula::normalize($item['cedula'] ?? null) ?: null,
        ];
    }

    // A borrador has no avances, so its compromisos are written again on every save.
    private function saveCompromisos(CampaignEvent|Organizacion $owner, EventActa $acta, array $items): void
    {
        $chosen = $this->chosenResponsables($owner, $items);

        EventCompromiso::where('acta_id', $acta->id)->delete();
        foreach (array_values($items) as $i => $item) {
            $this->writeCompromiso($owner, $acta, null, $i, $item, $chosen);
        }
    }

    // [id => id] of the fichas chosen for the responsables of $items that are valid (allowedLideres).
    public function chosenResponsables(CampaignEvent|Organizacion $owner, array $items): array
    {
        return $this->allowedLideres($owner, collect($items)->flatMap(fn ($c) => array_column($c['responsables'] ?? [], 'lider_id'))->all(), 'compromisos');
    }

    // Writes compromiso $i of the acta: a new one (pendiente) or, in a correction, $compromiso with its estado and avances
    // kept. Its responsables are written again. $chosen: chosenResponsables().
    public function writeCompromiso(CampaignEvent|Organizacion $owner, EventActa $acta, ?EventCompromiso $compromiso, int $i, array $item, array $chosen): EventCompromiso
    {
        $compromiso ??= (new EventCompromiso)->forceFill([
            'acta_id' => $acta->id, self::ownerKey($owner) => $owner->id, 'campaign_id' => $owner->campaign_id, 'estado' => 'pendiente',
        ]);
        $compromiso->forceFill([
            'orden' => $i,
            'descripcion' => trim((string) $item['descripcion']),
            'fecha_limite' => $item['fecha_limite'] ?? null,
            'recordar_el' => $item['recordar_el'] ?? null,
        ]);
        $compromiso->save();
        if ($compromiso->wasRecentlyCreated === false) {
            $compromiso->responsables()->delete();
        }

        foreach (array_values($item['responsables'] ?? []) as $r => $responsable) {
            $nombre = trim(preg_replace('/\s+/u', ' ', (string) $responsable['nombre']));
            $liderId = $responsable['lider_id'] ?? null;
            if ($liderId && ! isset($chosen[$liderId])) {
                throw ValidationException::withMessages([
                    "compromisos.{$i}.responsables.{$r}.lider_id" => 'La ficha elegida para «'.$nombre.'» no es un líder activo del grupo político '.self::ownerLabel($owner).' en su territorio.',
                ]);
            }
            $compromiso->responsables()->create([
                'orden' => $r, 'nombre' => $nombre, 'telefono' => self::text($responsable['telefono'] ?? null), 'lider_id' => $liderId,
                'sugerencia_rechazada' => self::uuid($responsable['sugerencia_rechazada'] ?? null),
            ]);
        }

        return $compromiso;
    }

    // The ficha of a person typed by hand (an organization's member): the same cédula in the owner's campaign, or a
    // new ficha created as the acta's confirmation creates it. $fields: nombre, perfil, municipio, telefono, cedula.
    // Returns [lider id, created].
    public function findOrCreateFicha(CampaignEvent|Organizacion $owner, array $fields): array
    {
        $fields = ['perfil' => null, 'municipio' => null, 'telefono' => null, 'cedula' => null, ...$fields];
        $fields['cedula'] = Cedula::normalize($fields['cedula']) ?: null;
        $existing = $fields['cedula'] ? $this->liderByCedula($owner, $fields['cedula']) : null;

        return $existing ? [$existing, false] : $this->createLider($owner, $fields, $this->places());
    }

    public static function ownerKey(CampaignEvent|Organizacion $owner): string
    {
        return $owner instanceof CampaignEvent ? 'event_id' : 'organizacion_id';
    }

    private static function ownerLabel(CampaignEvent|Organizacion $owner): string
    {
        return $owner instanceof CampaignEvent ? 'del evento' : 'de la organización';
    }

    // An organization's acta: the participant becomes a member (rol 'otro') unless it already is one with any role.
    // Returns whether the membership was created here.
    private function ensureMember(Organizacion $owner, string $liderId, User $user): bool
    {
        if (OrganizacionMiembro::where('organizacion_id', $owner->id)->where('lider_id', $liderId)->exists()) {
            return false;
        }
        (new OrganizacionMiembro)->forceFill([
            'organizacion_id' => $owner->id, 'lider_id' => $liderId, 'rol' => 'otro', 'created_by' => $user->id,
        ])->save();

        return true;
    }

    private static function uuid($value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    // [id => id] of the requested fichas that are active lideres of the event's campaign inside the user's territory.
    private function allowedLideres(CampaignEvent|Organizacion $owner, array $ids, string $field): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id))));
        if ($ids === []) {
            return [];
        }
        $found = $this->scopeToTerritory(DB::table('lideres')->where('campaign_id', $owner->campaign_id)->where('is_active', true)
            ->whereIn('id', $ids))->pluck('id')->all();

        return array_combine($found, $found);
    }

    private function liderByCedula(CampaignEvent|Organizacion $owner, string $cedula, bool $inTerritory = false): ?string
    {
        $query = DB::table('lideres')->where('campaign_id', $owner->campaign_id)->where('cedula', $cedula);

        return ($inTerritory ? $this->scopeToTerritory($query) : $query)->value('id');
    }

    private function attendeeOf(CampaignEvent|Organizacion $owner, string $liderId, ?string $cedula): ?EventAttendee
    {
        $cedula ??= DB::table('lideres')->where('id', $liderId)->value('cedula');

        return EventAttendee::where('event_id', $owner->id)
            ->where(fn ($q) => $q->where('lider_id', $liderId)->when($cedula, fn ($w) => $w->orWhere('cedula', $cedula)))
            ->orderByRaw('CASE WHEN lider_id = ? THEN 0 ELSE 1 END', [$liderId])
            ->first();
    }

    // [id, created]. $similar not null: a participant of an acta, whose ficha records what is missing (por_completar)
    // and, when it came without cédula nor phone, the fichas of the same name and municipio it may be ($similar,
    // posible_duplicado).
    private function createLider(CampaignEvent|Organizacion $owner, array $p, array $places, ?array $similar = null): array
    {
        $geo = $this->municipioOf($p['municipio'], $places['ids']) ?? $owner->municipio_id;
        $phones = AttendeeMatcher::phones($p['telefono']);
        $flags = $similar !== null && MigracionFlags::hasColumn() ? $this->actaFlags($p, $phones, $geo, $similar) : [];
        $lider = new Lider([
            'nombre' => $p['nombre'],
            'municipio' => $places['names'][$geo]['municipio'] ?? '',
            'provincia' => $places['names'][$geo]['provincia'] ?? '',
            'geographic_unit_id' => $geo,
            'cargo' => self::cargo($p['perfil']),
            'tipo' => 'directorio',
            'telefono' => $phones[0] ?? ($p['telefono'] ? mb_substr($p['telefono'], 0, 30) : null),
            'cedula' => $p['cedula'],
            'observacion' => null,
            'is_active' => true,
            'destacado' => false,
            'votos' => 0,
            'nivel_confianza' => 'sin_llamar',
            'autoriza_datos' => false,
        ]);
        $lider->forceFill(['campaign_id' => $owner->campaign_id]);
        if ($flags !== []) {
            $lider->forceFill(['migracion_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE)]);
        }
        try {
            DB::transaction(fn () => $lider->save());
        } catch (UniqueConstraintViolationException $e) {
            // The same cédula was saved in the campaign meanwhile: link to it.
            return [$this->liderByCedula($owner, (string) $p['cedula']) ?? throw $e, false];
        }
        foreach (array_slice($phones, 1) as $other) {
            LiderContacto::create(['lider_id' => $lider->id, 'tipo' => 'celular', 'valor' => $other, 'principal' => false, 'estado' => 'activo']);
        }

        return [$lider->id, true];
    }

    // migracion_flags of a ficha an acta creates (docs/eventos-api.md §14 "Datos incompletos").
    private function actaFlags(array $p, array $phones, ?string $geo, array $similar): array
    {
        $telefono = $phones !== [] || trim((string) $p['telefono']) !== '';
        $pending = array_keys(array_filter(['cedula' => ! $p['cedula'], 'telefono' => ! $telefono, 'municipio' => ! $geo]));
        $flags = $pending === [] ? [] : ['por_completar' => $pending];
        if (! $p['cedula'] && ! $telefono && $similar !== []) {
            $flags['posible_duplicado'] = array_slice($similar, 0, 5);
        }

        return $flags;
    }

    // [index => lider ids] of the same name and municipio for the rows without cédula nor phone, in one query for the
    // whole acta (AttendeeMatcher::byName). Fichas created by this same acta are not among them.
    private function similarByName(CampaignEvent|Organizacion $owner, Collection $rows, array $places): array
    {
        if (! MigracionFlags::hasColumn()) {
            return [];
        }
        $byName = [];
        foreach ($rows as $i => $row) {
            if (! $row->cedula && trim((string) $row->telefono) === '' && ! $row->lider_id) {
                $byName[$i] = ['name' => AttendeeMatcher::nameKey($row->nombre), 'geo' => $this->municipioOf($row->municipio, $places['ids']) ?? $owner->municipio_id];
            }
        }

        return $this->matcher->byName($owner->campaign_id, $byName);
    }

    private function createAttendee(CampaignEvent|Organizacion $owner, array $p, string $liderId, bool $created, User $user): EventAttendee
    {
        $phones = AttendeeMatcher::phones($p['telefono']);
        $attendee = new EventAttendee;
        $attendee->forceFill([
            'event_id' => $owner->id,
            'campaign_id' => $owner->campaign_id,
            'lider_id' => $liderId,
            'nombres' => mb_substr($p['nombre'], 0, 100),
            'apellidos' => '',
            'cedula' => $p['cedula'],
            'celular' => isset($phones[0]) ? mb_substr($phones[0], 0, 20) : null,
            'nota' => $p['perfil'] ? mb_substr($p['perfil'], 0, 255) : null,
            'autoriza_datos' => false,
            'source' => 'acta',
            'link_status' => $created ? 'aprobado' : 'vinculado',
            'registered_by' => $user->id,
            'client_uuid' => (string) Str::uuid(),
        ])->save();

        return $attendee;
    }

    // Municipio names to ids, and ids to the names lideres store (as ApproveAttendees stores them).
    private function places(): array
    {
        return $this->places ??= $this->loadPlaces();
    }

    private function loadPlaces(): array
    {
        $rows = DB::table('geographic_units as g')->leftJoin('geographic_units as prov', 'g.parent_id', '=', 'prov.id')
            ->where('g.type', 'municipality')->get(['g.id', 'g.canonical_name as municipio', 'prov.canonical_name as provincia']);
        $ids = [];
        $names = [];
        foreach ($rows as $r) {
            $ids[ImportColumns::normalize($r->municipio)] ??= $r->id;
            $names[$r->id] = ['municipio' => $r->municipio, 'provincia' => $r->provincia ?? ''];
        }

        return ['ids' => $ids, 'names' => $names];
    }

    // The participant's municipio when it is a known one inside the user's territory; otherwise the event's.
    private function municipioOf(?string $name, array $ids): ?string
    {
        $id = $name ? ($ids[ImportColumns::normalize($name)] ?? null) : null;
        $allowed = $this->allowedGeoIds();

        return $id && ($allowed === null || in_array($id, $allowed, true)) ? $id : null;
    }

    private function matchRow(string $nombre, string $telefono, array $geo, ?string $cedula): array
    {
        return ['phones' => AttendeeMatcher::phones($telefono), 'tokens' => AttendeeMatcher::nameTokens($nombre), 'geo' => $geo, 'cedula' => $cedula];
    }

    // "SENADOR DE LA REPUBLICA" → "Senador de la Republica"; empty or "LIDER …" → "Líder".
    public static function cargo(?string $perfil): string
    {
        $perfil = trim(preg_replace('/\s+/u', ' ', (string) $perfil));
        if ($perfil === '' || preg_match('/^l[ií]der\b/iu', $perfil)) {
            return 'Líder';
        }
        $words = explode(' ', mb_strtolower($perfil));
        foreach ($words as $i => $w) {
            $words[$i] = $i > 0 && in_array($w, self::CONNECTORS, true) ? $w : mb_convert_case($w, MB_CASE_TITLE);
        }

        return mb_substr(implode(' ', $words), 0, 100);
    }

    private static function text($value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
