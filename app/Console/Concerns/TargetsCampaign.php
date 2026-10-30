<?php

namespace App\Console\Concerns;

use App\Support\CurrentCampaign;
use Illuminate\Support\Facades\DB;

// ETL commands over lideres work on ONE campaign: --campaign=<slug|id>, "Centro Democrático" by default.
// Every read, update, delete and insert of lideres goes through lideres() / $campaignId.
trait TargetsCampaign
{
    protected ?string $campaignId = null;

    protected function resolveCampaign(): bool
    {
        $this->campaignId = CurrentCampaign::resolveForCommand($this->option('campaign'));
        if (! $this->campaignId) {
            $this->error('Campaña no encontrada: '.$this->option('campaign'));

            return false;
        }
        $this->info('Campaña: '.DB::table('campaigns')->where('id', $this->campaignId)->value('name'));

        return true;
    }

    protected function lideres(?string $alias = null)
    {
        $table = $alias ? "lideres as {$alias}" : 'lideres';

        return DB::table($table)->where(($alias ?? 'lideres').'.campaign_id', $this->campaignId);
    }
}
