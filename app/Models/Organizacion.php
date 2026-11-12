<?php

namespace App\Models;

use App\Support\Nit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// An empresa or a gremio of a campaign (docs/empresarios.md). campaign_id is set by the server, never by the request.
class Organizacion extends Model
{
    use HasUuids, SoftDeletes;

    public const TIPOS = ['empresa' => 'Empresa', 'gremio' => 'Gremio'];

    public const RANGOS = ['1-10', '11-50', '51-200', '201-500', '500+'];

    protected $table = 'organizaciones';

    protected $guarded = ['*'];

    protected $casts = ['is_active' => 'boolean', 'empleados' => 'integer'];

    public function miembros(): HasMany
    {
        return $this->hasMany(OrganizacionMiembro::class, 'organizacion_id');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'municipio_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function nitFormatted(): ?string
    {
        return Nit::format($this->nit, $this->nit_dv);
    }
}
