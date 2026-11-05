<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One data row of an import. raw = the cells as read (by column index), mapped = the attendee fields after the
// column mapping and the corrections made in the preview (both emptied by eventos:limpiar-importaciones 30 days after the
// confirmation). declared/confirmed_by/confirmed_at: the confirmation that imported this row. OCR rows also keep their
// page (page_id, page_row) and the AI's confidence per field (1 once a person corrected the cell). A row of a list
// without authorization (sin_autorizacion) may carry the lider suggested by phone + name and what the operator said.
class EventImportRow extends Model
{
    use HasUuids;

    public const STATUSES = ['pendiente', 'valida', 'error', 'no_autorizo', 'duplicada', 'en_evento', 'sugerida', 'sin_ficha'];

    protected $guarded = ['*'];

    protected $casts = [
        'raw' => 'array',
        'mapped' => 'array',
        'errors' => 'array',
        'confidence' => 'array',
        'edited' => 'boolean',
        'declared' => 'boolean',
        'sin_autorizacion' => 'boolean',
        'confirmed_at' => 'datetime',
        'row_number' => 'integer',
        'page_row' => 'integer',
    ];
}
