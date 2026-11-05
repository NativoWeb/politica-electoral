<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiderInfoPolitica extends Model
{
    use HasUuids;

    protected $table = 'lider_info_politica';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lider_id', 'cargo_politico', 'departamento', 'municipio',
        'partido', 'aliado', 'votos', 'observacion', 'estado',
    ];

    protected $casts = [
        'votos' => 'integer',
    ];

    protected function partido(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PoliticalOrganization::upperName($value));
    }

    public function lider(): BelongsTo
    {
        return $this->belongsTo(Lider::class);
    }
}
