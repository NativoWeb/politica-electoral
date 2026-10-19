<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiderSeguimiento extends Model
{
    use HasUuids;

    public const TIPOS = ['llamada', 'visita', 'reunion', 'mensaje', 'otro'];

    protected $table = 'lider_seguimientos';

    protected $fillable = ['lider_id', 'user_id', 'tipo', 'nota', 'fecha'];

    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function lider(): BelongsTo
    {
        return $this->belongsTo(Lider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
