<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Person registered at an event. It only becomes a lider (Votantes) when someone approves it. sin_autorizacion: came in
// a list without authorization and matched nobody; it stays only in the event's list and is never approvable.
class EventAttendee extends Model
{
    use HasUuids;

    public const LINK_STATUSES = ['vinculado', 'pendiente', 'sin_cedula', 'aprobado', 'descartado', 'sin_autorizacion'];

    protected $guarded = ['*'];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'autoriza_datos' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CampaignEvent::class, 'event_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
