<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One change of the Centro de configuración (an option of a list or an ajuste): who, when, before and after.
class ConfigCambio extends Model
{
    use HasUuids;

    public const TIPO_CATALOGO = 'catalogo';

    public const TIPO_AJUSTE = 'ajuste';

    public $timestamps = false;

    protected $table = 'config_cambios';

    protected $fillable = ['tipo', 'catalogo', 'referencia', 'accion', 'antes', 'despues', 'user_id', 'revierte_id', 'revertido_por_id', 'created_at'];

    protected $casts = [
        'antes' => 'array',
        'despues' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
