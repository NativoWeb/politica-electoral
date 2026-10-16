<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiderMunicipioInfluencia extends Model
{
    use HasUuids;

    protected $table = 'lider_municipios_influencia';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lider_id', 'geographic_unit_id', 'municipio_name', 'provincia_name', 'nota',
    ];

    public function lider(): BelongsTo
    {
        return $this->belongsTo(Lider::class);
    }

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }
}
