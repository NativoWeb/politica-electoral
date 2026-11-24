<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// The goals written for one municipio, comuna or corregimiento of an electoral campaign (null = not written).
class ElectoralCampaignGoal extends Model
{
    use HasUuids;

    public const CAMPOS = ['votos_meta', 'lideres_meta', 'votantes_meta'];

    protected $fillable = ['electoral_campaign_id', 'geographic_unit_id', 'votos_meta', 'lideres_meta', 'votantes_meta'];

    protected $casts = ['votos_meta' => 'integer', 'lideres_meta' => 'integer', 'votantes_meta' => 'integer'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ElectoralCampaign::class, 'electoral_campaign_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'geographic_unit_id');
    }
}
