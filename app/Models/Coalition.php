<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coalition extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'purpose', 'electoral_event_id', 'contest_id',
        'valid_from', 'valid_to',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function electoralEvent(): BelongsTo
    {
        return $this->belongsTo(ElectoralEvent::class);
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CoalitionMember::class);
    }
}
