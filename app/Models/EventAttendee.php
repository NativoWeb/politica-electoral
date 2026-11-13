<?php

namespace App\Models;

use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\ComunaCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Person registered at an event. It only becomes a lider (Votantes) when someone approves it. sin_autorizacion: legacy
// status (lists loaded before 2026-11-07, when authorization was still asked); never written again, and the deploy SQL
// of docs/eventos-api.md §11 turns those attendees into pendiente.
class EventAttendee extends Model
{
    use HasUuids;

    public const LINK_STATUSES = ['vinculado', 'pendiente', 'sin_cedula', 'aprobado', 'descartado', 'sin_autorizacion'];

    protected $guarded = ['*'];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'autoriza_datos' => 'boolean',
    ];

    // The attendee's comuna is looked up in its event's municipio.
    protected static function booted(): void
    {
        static::saving(function (EventAttendee $attendee) {
            if ($attendee->exists && ! $attendee->isDirty(['barrio', 'event_id']) && $attendee->comuna_id === null) {
                return;
            }
            $municipioId = app(ComunaCatalog::class)->municipioOfEvent($attendee->event_id) ?: null;
            app(ComunaAssigner::class)->applyToModel($attendee, $municipioId, ['barrio', 'event_id']);
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CampaignEvent::class, 'event_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
