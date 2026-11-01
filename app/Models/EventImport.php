<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// An Excel/CSV file of attendees uploaded to an event: subido (columns pending) → revisado (preview) → confirmado.
class EventImport extends Model
{
    use HasUuids;

    public const STATUSES = ['subido', 'revisado', 'confirmado'];

    protected $guarded = ['*'];

    protected $casts = [
        'headers' => 'array',
        'column_mapping' => 'array',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
