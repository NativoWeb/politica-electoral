<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One photographed page of a sign-in sheet in an OCR load (docs/eventos-api.md §13): the private file (a photo, or one
// page of a PDF), what the QR or the printed text said about its event and page, and what reading it with the AI
// cost. Never holds the extracted personal data (that lives in event_import_rows).
// status: pendiente → procesada | rechazada (another event, not a sheet, daily limit) | error (can be retried).
class EventImportPage extends Model
{
    use HasUuids;

    public const STATUSES = ['pendiente', 'procesada', 'rechazada', 'error'];

    protected $guarded = ['*'];

    protected $casts = [
        'sequence' => 'integer',
        'page_in_file' => 'integer',
        'file_pages' => 'integer',
        'printed_page' => 'integer',
        'rows_found' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cost_usd' => 'float',
        'duration_ms' => 'integer',
        'attempts' => 'integer',
        'api_calls' => 'integer',
        'last_call_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(EventImport::class, 'import_id');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
