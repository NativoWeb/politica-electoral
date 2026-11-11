<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// "Acta de reunión" of an event (docs/eventos-api.md §14). campaign_id is the event's, set by the server. A borrador is
// edited and deleted freely; confirming it writes Votantes and the attendees, and from then on it is read-only.
class EventActa extends Model
{
    use HasUuids;

    public const BORRADOR = 'borrador';

    public const CONFIRMADA = 'confirmada';

    protected $guarded = ['*'];

    protected $casts = [
        'fecha' => 'date',
        'confirmed_at' => 'datetime',
        'orden_dia' => 'array',
        'desarrollo' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CampaignEvent::class, 'event_id');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(EventActaParticipante::class, 'acta_id')->orderBy('orden');
    }

    public function compromisos(): HasMany
    {
        return $this->hasMany(EventCompromiso::class, 'acta_id')->orderBy('orden');
    }

    public function isDraft(): bool
    {
        return $this->estado !== self::CONFIRMADA;
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
