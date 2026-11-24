<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A unit an electoral campaign covers: the departamento, a municipio or a comuna/corregimiento.
class ElectoralCampaignTerritory extends Model
{
    use HasUuids;

    protected $fillable = ['electoral_campaign_id', 'geographic_unit_id'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ElectoralCampaign::class, 'electoral_campaign_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'geographic_unit_id');
    }
}
