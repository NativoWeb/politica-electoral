<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A compromiso of an acta. Vencido = fecha_limite before today (Bogotá) and still pendiente or en_curso.
class EventCompromiso extends Model
{
    use HasUuids;

    public const ESTADOS = ['pendiente', 'en_curso', 'cumplido', 'no_cumplido'];

    public const ABIERTOS = ['pendiente', 'en_curso'];

    public const LABELS = ['pendiente' => 'Pendiente', 'en_curso' => 'En curso', 'cumplido' => 'Cumplido', 'no_cumplido' => 'No cumplido'];

    protected $table = 'event_compromisos';

    protected $guarded = ['*'];

    protected $casts = ['fecha_limite' => 'date', 'recordar_el' => 'date'];

    public function acta(): BelongsTo
    {
        return $this->belongsTo(EventActa::class, 'acta_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CampaignEvent::class, 'event_id');
    }

    public function responsables(): HasMany
    {
        return $this->hasMany(EventCompromisoResponsable::class, 'compromiso_id')->orderBy('orden');
    }

    public function avances(): HasMany
    {
        return $this->hasMany(EventCompromisoAvance::class, 'compromiso_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public static function today(): string
    {
        return now('America/Bogota')->toDateString();
    }

    public function vencido(): bool
    {
        return $this->fecha_limite !== null && in_array($this->estado, self::ABIERTOS, true)
            && $this->fecha_limite->toDateString() < self::today();
    }
}
