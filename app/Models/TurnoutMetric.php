<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TurnoutMetric extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contest_id', 'electoral_event_id', 'geographic_unit_id', 'grain',
        'electoral_potential', 'total_voters', 'valid_votes', 'blank_votes',
        'null_votes', 'unmarked_votes', 'participation_pct', 'raw_potential', 'source_id',
    ];

    protected $casts = [
        'electoral_potential' => 'integer',
        'total_voters' => 'integer',
        'valid_votes' => 'integer',
        'blank_votes' => 'integer',
        'null_votes' => 'integer',
        'unmarked_votes' => 'integer',
        'participation_pct' => 'decimal:4',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function electoralEvent(): BelongsTo
    {
        return $this->belongsTo(ElectoralEvent::class);
    }

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }
}
