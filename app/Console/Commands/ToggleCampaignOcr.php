<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Eventos\Ocr\OcrSettings;
use App\Support\CurrentCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Per-campaign switch of the AI reading of printed sheets (docs/eventos-api.md §13). Only from the console (R01 /
// devops): turning it on sends the campaign's photos to a foreign provider, so it needs the DPA/ZDR and an
// authorization text that mentions it. Without --activar/--desactivar it only shows the state.
class ToggleCampaignOcr extends Command
{
    protected $signature = 'eventos:ocr-campana
        {campaign : Slug o id de la campaña}
        {--activar : Habilita la lectura de planillas con IA para la campaña}
        {--desactivar : La deshabilita}';

    protected $description = 'Turn the AI reading of printed sign-in sheets on or off for a campaign';

    public function handle(): int
    {
        $campaignId = CurrentCampaign::resolveForCommand($this->argument('campaign'));
        if (! $campaignId) {
            $this->error('Campaña no encontrada.');

            return self::FAILURE;
        }
        if ($this->option('activar') && $this->option('desactivar')) {
            $this->error('Use --activar o --desactivar, no los dos.');

            return self::FAILURE;
        }

        if ($this->option('activar') || $this->option('desactivar')) {
            $on = (bool) $this->option('activar');
            DB::table('campaigns')->where('id', $campaignId)->update(['ocr_enabled' => $on, 'updated_at' => now()]);
            AuditLog::create([
                'user_id' => null,
                'action' => $on ? 'EVENTO_OCR_CAMPANA_ACTIVADA' : 'EVENTO_OCR_CAMPANA_DESACTIVADA',
                'entity_type' => 'campana',
                'entity_id' => $campaignId,
                'metadata' => ['command' => 'eventos:ocr-campana'],
            ]);
        }

        $campaign = DB::table('campaigns')->where('id', $campaignId)->first(['name', 'ocr_enabled']);
        $this->info("Campaña {$campaign->name}: lectura de planillas con IA ".($campaign->ocr_enabled ? 'ACTIVADA' : 'desactivada').'.');
        if ($campaign->ocr_enabled && ! OcrSettings::enabled()) {
            $this->warn('El interruptor global EVENTOS_OCR_ENABLED está apagado: nadie la ve hasta encenderlo.');
        }
        if ($campaign->ocr_enabled && ! OcrSettings::hasKey()) {
            $this->warn('Falta ANTHROPIC_API_KEY en el .env: la pantalla avisará que no está lista.');
        }

        return self::SUCCESS;
    }
}
