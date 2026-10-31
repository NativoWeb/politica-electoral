<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

// "Evento especial" of a campaign (table events). campaign_id, status and public_token are set by the server.
class CampaignEvent extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUSES = ['borrador', 'abierto', 'cerrado', 'cancelado'];

    protected $table = 'events';

    protected $fillable = [
        'event_type_id', 'nombre', 'descripcion', 'starts_at', 'ends_at', 'sitio_encuentro',
        'provincia_id', 'municipio_id', 'registration_closes_at',
    ];

    protected $hidden = ['public_token'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'municipio_id');
    }

    public function provincia(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'provincia_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function barrios(): HasMany
    {
        return $this->hasMany(EventBarrio::class, 'event_id')->orderBy('nombre');
    }

    public function organizers(): HasMany
    {
        return $this->hasMany(EventOrganizer::class, 'event_id')->orderBy('created_at');
    }

    public function figures(): HasMany
    {
        return $this->hasMany(EventFigure::class, 'event_id')->orderBy('created_at');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class, 'event_id');
    }

    // When the public QR stops accepting registrations if registration_closes_at is not set.
    public function publicClosesAt(): Carbon
    {
        return $this->registration_closes_at
            ?? ($this->ends_at ? $this->ends_at->copy()->addHours(6) : $this->starts_at->copy()->addDay());
    }

    public function acceptsOperatorRegistrations(): bool
    {
        return ! $this->trashed() && in_array($this->status, ['borrador', 'abierto'], true);
    }
}
