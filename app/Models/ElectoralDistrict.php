<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectoralDistrict extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'type', 'geographic_unit_id', 'seats', 'valid_from', 'valid_to',
    ];

    protected $casts = [
        'seats' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }

    public function contests(): HasMany
    {
        return $this->hasMany(Contest::class);
    }
}
