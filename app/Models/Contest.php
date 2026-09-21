<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'electoral_event_id', 'office_id', 'corporation_id',
        'electoral_district_id', 'geographic_unit_id',
        'name', 'seats', 'list_type', 'status',
    ];

    protected $casts = ['seats' => 'integer'];

    public function electoralEvent(): BelongsTo
    {
        return $this->belongsTo(ElectoralEvent::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function electoralDistrict(): BelongsTo
    {
        return $this->belongsTo(ElectoralDistrict::class);
    }

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }

    public function candidacies(): HasMany
    {
        return $this->hasMany(Candidacy::class);
    }

    public function lists(): HasMany
    {
        return $this->hasMany(ElectoralList::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ElectoralResult::class);
    }

    public function turnoutMetrics(): HasMany
    {
        return $this->hasMany(TurnoutMetric::class);
    }
}
