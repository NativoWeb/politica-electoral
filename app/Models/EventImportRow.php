<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One data row of an import. raw = the cells as read (by column index), mapped = the attendee fields after the
// column mapping and the corrections made in the preview (both emptied by eventos:limpiar-importaciones 30 days after the
// confirmation). declared/confirmed_by/confirmed_at: the confirmation that imported this row.
class EventImportRow extends Model
{
    use HasUuids;

    public const STATUSES = ['pendiente', 'valida', 'error', 'no_autorizo', 'duplicada', 'en_evento'];

    protected $guarded = ['*'];

    protected $casts = [
        'raw' => 'array',
        'mapped' => 'array',
        'errors' => 'array',
        'edited' => 'boolean',
        'declared' => 'boolean',
        'confirmed_at' => 'datetime',
        'row_number' => 'integer',
    ];
}
