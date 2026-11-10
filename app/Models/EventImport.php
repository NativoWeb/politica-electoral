<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Attendees uploaded to an event, kind 'excel' (an Excel/CSV file: subido = columns pending) or 'ocr' (photos of the
// printed sheet read with AI, §13: subido = pages pending reading, file_path = the folder of its pages)
// → revisado (preview) → confirmado.
class EventImport extends Model
{
    use HasUuids;

    public const STATUSES = ['subido', 'revisado', 'confirmado'];

    public const KINDS = ['excel', 'ocr'];

    protected $guarded = ['*'];

    protected $casts = [
        'headers' => 'array',
        'column_mapping' => 'array',
        'sheets' => 'array',
        'counts' => 'array',
        'consent_declared' => 'boolean',
        'confirmed_at' => 'datetime',
        'staging_purged_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CampaignEvent::class, 'event_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EventImportRow::class, 'import_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class, 'import_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(EventImportPage::class, 'import_id');
    }

    public function isOcr(): bool
    {
        return $this->kind === 'ocr';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
