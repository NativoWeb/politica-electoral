<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'type', 'title', 'description', 'occurred_at', 'ended_at',
        'date_precision', 'geographic_unit_id', 'data_classification',
        'source_id', 'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }
}
