<?php

namespace App\Services\Geo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Keeps comuna_id / comuna_source of a ficha (lideres), an event attendee or an organization in line with its signals,
// strongest first (docs/geografia.md): manual > auto (barrio) > texto ("COMUNA 5" written in barrio or dirección) >
// direccion (phase B, not active) > puesto (the polling station, fichas only). The best signal present wins and replaces
// a weaker automatic one; when it disappears the next one takes over. 'manual' is never overwritten while it still
// belongs to the municipio; a municipio without comunas leaves the comuna empty.
class ComunaAssigner
{
    // table => SQL expression of the row's municipio (an attendee is where its event is).
    public const TABLES = [
        'lideres' => 'geographic_unit_id',
        'event_attendees' => '(SELECT e.municipio_id FROM events e WHERE e.id = event_attendees.event_id)',
        'organizaciones' => 'municipio_id',
    ];

    // Columns besides barrio that feed the cascade, when the table has them.
    public const SIGNAL_COLUMNS = ['direccion', 'puesto_votacion', 'municipio_votacion'];

    private array $columns = [];

    private array $codes = [];

    public function __construct(
        private readonly ComunaCatalog $catalog,
        private readonly BarrioMatcher $matcher,
        private readonly PuestoMatcher $puestos,
    ) {}

    // null = nothing to change; otherwise ['comuna_id' => ?string, 'comuna_source' => ?string].
    // $signals: direccion, puesto_votacion, municipio_votacion (missing keys = no signal).
    public function changesFor(?string $municipioId, ?string $campaignId, ?string $barrio, ?string $comunaId, ?string $source, array $signals = []): ?array
    {
        if (! $this->catalog->hasComunas($municipioId)) {
            return $comunaId !== null ? ['comuna_id' => null, 'comuna_source' => null] : null;
        }

        if ($source === 'manual' && ($comunaId === null || $this->catalog->belongsTo($comunaId, $municipioId))) {
            return null;
        }

        $best = $this->resolve($municipioId, $campaignId, $barrio, $signals);

        return $best['comuna_id'] === $comunaId && $best['comuna_source'] === $source
            ? null
            : ['comuna_id' => $best['comuna_id'], 'comuna_source' => $best['comuna_source']];
    }

    // The automatic comuna of a record of a municipio with comunas, ignoring what it has now:
    // ['comuna_id', 'comuna_source', 'barrio' => BarrioMatcher result, 'puesto' => PuestoMatcher result|null,
    //  'puesto_motivo' => why the puesto was not looked at (null when it was)].
    public function resolve(string $municipioId, ?string $campaignId, ?string $barrio, array $signals = []): array
    {
        $match = $this->matcher->match($municipioId, $campaignId, $barrio);
        $out = ['comuna_id' => null, 'comuna_source' => null, 'barrio' => $match, 'puesto' => null, 'puesto_motivo' => null];

        if ($match['status'] === BarrioMatcher::ASIGNADO) {
            return ['comuna_id' => $match['comuna_id'], 'comuna_source' => 'auto'] + $out;
        }

        $texto = $this->comunaFromText($municipioId, [$barrio, $signals['direccion'] ?? null]);
        if ($texto !== null) {
            return ['comuna_id' => $texto, 'comuna_source' => 'texto'] + $out;
        }

        $puesto = trim((string) ($signals['puesto_votacion'] ?? ''));
        if ($puesto === '' || PuestoNormalizer::normalize($puesto) === '') {
            return ['puesto_motivo' => 'sin_puesto'] + $out;
        }
        if (! $this->votesHere($municipioId, $signals['municipio_votacion'] ?? null)) {
            return ['puesto_motivo' => 'vota_en_otro_municipio'] + $out;
        }
        $p = $this->puestos->match($municipioId, $puesto);
        if ($p['status'] === PuestoMatcher::ASIGNADO) {
            return ['comuna_id' => $p['comuna_id'], 'comuna_source' => 'puesto', 'puesto' => $p] + $out;
        }

        return ['puesto' => $p] + $out;
    }

    // "COMUNA 5" (also "COMUNA NO. 5", "COMUNA #5", "COMUNA 05") in any of the texts, when they name exactly one comuna
    // number that exists in the municipio and the text that names it does not mention another municipio
    // ("COMUNA 5 FLORIDABLANCA").
    public function comunaFromText(string $municipioId, array $texts): ?string
    {
        $numbers = [];
        foreach ($texts as $text) {
            $s = trim(preg_replace('/[^A-Z0-9]+/', ' ', BarrioNormalizer::plain($text)));
            if (! preg_match_all('/(?:^| )COMUNA ?(?:NO |NRO |NUM |NUMERO |N )?0*(\d{1,2})(?= |$)/', $s, $m)) {
                continue;
            }
            foreach ($this->catalog->otherMunicipioNames($municipioId) as $other) {
                if (BarrioNormalizer::containsPhrase($s, $other)) {
                    continue 2;
                }
            }
            foreach ($m[1] as $n) {
                $numbers[(int) $n] = true;
            }
        }
        if (count($numbers) !== 1) {
            return null;
        }
        $n = (int) array_key_first($numbers);
        foreach ($this->catalog->comunasOf($municipioId) as $c) {
            if ($c['tipo'] === 'comuna' && preg_match('/-C0*'.$n.'$/', (string) $c['codigo'])) {
                return $c['id'];
            }
        }

        return null;
    }

    // Called while saving a model (observer): only when it is new or one of its signals or its municipio changed.
    public function applyToModel(Model $model, ?string $municipioId, array $relevant): void
    {
        if ($model->exists && ! $model->isDirty($relevant) && ! $this->staleManual($model, $municipioId)) {
            return;
        }

        $changes = $this->changesFor(
            $municipioId,
            $model->getAttribute('campaign_id'),
            $model->getAttribute('barrio'),
            $model->getAttribute('comuna_id'),
            $model->getAttribute('comuna_source'),
            array_map(fn ($c) => $model->getAttribute($c), array_combine(self::SIGNAL_COLUMNS, self::SIGNAL_COLUMNS)),
        );
        if ($changes !== null) {
            $model->forceFill($changes);
        }
    }

    // For writes that go through the query builder (the Mapa's ficha): re-reads the row and updates it if needed.
    public function refreshRow(string $table, string $id): ?array
    {
        if (! $this->hasColumns($table)) {
            return null;
        }
        $signals = $this->signalColumns($table);
        $row = DB::table($table)->where('id', $id)
            ->first(['campaign_id', 'barrio', 'comuna_id', 'comuna_source', ...$signals, DB::raw(self::TABLES[$table].' AS municipio_id')]);
        if (! $row) {
            return null;
        }

        $changes = $this->changesFor($row->municipio_id, $row->campaign_id, $row->barrio, $row->comuna_id, $row->comuna_source,
            self::signalsOf($row, $signals));
        if ($changes !== null) {
            DB::table($table)->where('id', $id)->update($changes);
        }

        return $changes;
    }

    // The SIGNAL_COLUMNS the table has (attendees and organizations have no polling station).
    public function signalColumns(string $table): array
    {
        return $this->columns[$table.':signals'] ??= array_values(array_filter(self::SIGNAL_COLUMNS, fn ($c) => Schema::hasColumn($table, $c)));
    }

    public static function signalsOf(object $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = $row->{$c} ?? null;
        }

        return $out;
    }

    // The ficha votes where it lives: municipio_votacion is the municipio's name (or one of its usual spellings) or its
    // DANE code. Empty = unknown, and then the polling station is not used.
    private function votesHere(string $municipioId, ?string $municipioVotacion): bool
    {
        $norm = BarrioNormalizer::normalize($municipioVotacion);
        if ($norm === '') {
            return false;
        }

        return in_array($norm, $this->catalog->ownMunicipioStrips($municipioId), true)
            || $norm === ($this->codes[$municipioId] ??= (string) DB::table('geographic_units')->where('id', $municipioId)->value('official_code'));
    }

    // Tables built by hand in older tests may lack the comuna columns; the real schema always has them.
    private function hasColumns(string $table): bool
    {
        return $this->columns[$table] ??= Schema::hasColumn($table, 'comuna_id');
    }

    private function staleManual(Model $model, ?string $municipioId): bool
    {
        return $model->getAttribute('comuna_id') !== null && ! $this->catalog->belongsTo($model->getAttribute('comuna_id'), $municipioId);
    }
}
