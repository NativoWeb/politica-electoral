<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Data processing authorization text (Ley 1581). campaign_id null = the provisional text used by campaigns
// that have none yet; only an approved text lets an event open its public QR.
class ConsentText extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'vigente_desde' => 'datetime',
        'is_active' => 'boolean',
        'approved' => 'boolean',
    ];

    public static function currentFor(string $campaignId): ?self
    {
        return static::where('campaign_id', $campaignId)->where('is_active', true)
            ->orderByDesc('vigente_desde')->first()
            ?? static::whereNull('campaign_id')->where('is_active', true)->orderByDesc('vigente_desde')->first();
    }

    public function toProps(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'cuerpo' => $this->cuerpo, 'approved' => $this->approved];
    }
}
