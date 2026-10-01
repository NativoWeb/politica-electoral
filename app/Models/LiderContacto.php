<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiderContacto extends Model
{
    use HasUuids;

    protected $table = 'lider_contactos';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lider_id', 'tipo', 'valor', 'principal', 'estado',
    ];

    protected $casts = [
        'principal' => 'boolean',
    ];

    public function lider(): BelongsTo
    {
        return $this->belongsTo(Lider::class);
    }
}
