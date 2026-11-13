<?php

namespace App\Services\Geo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Keeps comuna_id / comuna_source of a ficha (lideres), an event attendee or an organization in line with its barrio
// and municipio: 'manual' is never overwritten while it still belongs to the municipio; a municipio without comunas
// leaves the comuna empty.
class ComunaAssigner
{
    // table => SQL expression of the row's municipio (an attendee is where its event is).
    public const TABLES = [
        'lideres' => 'geographic_unit_id',
        'event_attendees' => '(SELECT e.municipio_id FROM events e WHERE e.id = event_attendees.event_id)',
        'organizaciones' => 'municipio_id',
    ];

    private array $columns = [];

    public function __construct(private readonly ComunaCatalog $catalog, private readonly BarrioMatcher $matcher) {}

    // null = nothing to change; otherwise ['comuna_id' => ?string, 'comuna_source' => ?string].
    public function changesFor(?string $municipioId, ?string $campaignId, ?string $barrio, ?string $comunaId, ?string $source): ?array
    {
        if (! $this->catalog->hasComunas($municipioId)) {
            return $comunaId !== null ? ['comuna_id' => null, 'comuna_source' => null] : null;
        }

        if ($source === 'manual' && ($comunaId === null || $this->catalog->belongsTo($comunaId, $municipioId))) {
            return null;
        }

        $match = $this->matcher->match($municipioId, $campaignId, $barrio);
        $new = $match['status'] === BarrioMatcher::ASIGNADO ? $match['comuna_id'] : null;
        $newSource = $new ? 'auto' : null;

        return $new === $comunaId && $newSource === $source ? null : ['comuna_id' => $new, 'comuna_source' => $newSource];
    }

    // Called while saving a model (observer): only when it is new or its barrio or municipio changed.
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
        $row = DB::table($table)->where('id', $id)
            ->first(['campaign_id', 'barrio', 'comuna_id', 'comuna_source', DB::raw(self::TABLES[$table].' AS municipio_id')]);
        if (! $row) {
            return null;
        }

        $changes = $this->changesFor($row->municipio_id, $row->campaign_id, $row->barrio, $row->comuna_id, $row->comuna_source);
        if ($changes !== null) {
            DB::table($table)->where('id', $id)->update($changes);
        }

        return $changes;
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
