<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectoralEvent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'event_type', 'election_date', 'political_period',
        'authority', 'scope', 'status', 'notes',
    ];

    protected $casts = [
        'election_date' => 'date',
    ];

    public function contests(): HasMany
    {
        return $this->hasMany(Contest::class);
    }

    public function coalitions(): HasMany
    {
        return $this->hasMany(Coalition::class);
    }

    public function turnoutMetrics(): HasMany
    {
        return $this->hasMany(TurnoutMetric::class);
    }
}
