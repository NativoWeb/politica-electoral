<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// History of a compromiso: a note, a change of estado (written automatically) and an optional photo or PDF.
class EventCompromisoAvance extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'event_compromiso_avances';

    protected $guarded = ['*'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
