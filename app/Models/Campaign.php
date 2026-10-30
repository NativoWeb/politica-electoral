<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A campaign owns its users, lideres, nexos and WhatsApp data. A candidate's campaign may be a
// subcampaign of another one (parent_id).
class Campaign extends Model
{
    use HasUuids;

    // "Centro Democrático": every row that existed before campaigns belongs to it, and it is the
    // database default of every campaign_id column.
    public const DEFAULT_ID = '3b83a83b-81f4-4fb2-894a-2888dc919070';

    protected $fillable = [
        'name', 'slug', 'parent_id', 'candidate_person_id', 'whatsapp_enabled', 'is_active',
    ];

    protected $casts = [
        'whatsapp_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Campaign::class, 'parent_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'candidate_person_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function lideres(): HasMany
    {
        return $this->hasMany(Lider::class);
    }
}
