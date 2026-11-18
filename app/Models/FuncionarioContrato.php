<?php

namespace App\Models;

use App\Models\Concerns\SuperadminOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Contract of a ficha in a public entity (imported from Electo). The salary is never stored.
class FuncionarioContrato extends Model
{
    use HasUuids, SuperadminOnly;

    protected $table = 'funcionario_contratos';

    protected $guarded = ['*'];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_final' => 'date',
        'fecha_terminacion' => 'date',
        'otrosi_fecha_final' => 'date',
        'otrosi' => 'boolean',
    ];

    public function lider(): BelongsTo
    {
        return $this->belongsTo(Lider::class);
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(FuncionarioEntidad::class, 'entidad_id');
    }
}
