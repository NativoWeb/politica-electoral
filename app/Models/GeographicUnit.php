<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeographicUnit extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'parent_id', 'type', 'canonical_name', 'official_code',
        'level', 'valid_from', 'valid_to', 'is_active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
        'level' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(GeographicUnit::class, 'parent_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(GeographicAlias::class);
    }

    public function contests(): HasMany
    {
        return $this->hasMany(Contest::class);
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
