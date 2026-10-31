<?php

namespace App\Console\Commands;

use App\Models\ConsentText;
use App\Support\CurrentCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Loads a campaign's data processing authorization text (Ley 1581) as a new active version. Only an approved
// text lets the campaign's events open their public QR; the previous versions stay stored (data_consents
// points to them) but inactive.
class PublishConsentText extends Command
{
    protected $signature = 'eventos:consent-text
        {campaign : Slug o id de la campaña}
        {file : Archivo de texto UTF-8 con la autorización}
        {--text-version= : Etiqueta de la versión (por defecto la fecha y hora)}
        {--approve : El texto ya fue aprobado por la campaña (habilita el QR público)}';

    protected $description = 'Publish a new version of a campaign\'s data authorization text for event registration';

    public function handle(): int
    {
        $campaignId = CurrentCampaign::resolveForCommand($this->argument('campaign'));
        if (! $campaignId) {
            $this->error('Campaña no encontrada.');

            return self::FAILURE;
        }

        $path = $this->argument('file');
        $body = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if ($body === '' || ! mb_check_encoding($body, 'UTF-8')) {
            $this->error('El archivo no existe, está vacío o no es UTF-8.');

            return self::FAILURE;
        }

        $version = $this->option('text-version') ?: now()->format('Y-m-d_His');
        $text = DB::transaction(function () use ($campaignId, $body, $version) {
            ConsentText::where('campaign_id', $campaignId)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);

            $text = (new ConsentText)->forceFill([
                'campaign_id' => $campaignId,
                'version' => $version,
                'cuerpo' => $body,
                'sha256' => hash('sha256', $body),
                'vigente_desde' => now(),
                'is_active' => true,
                'approved' => (bool) $this->option('approve'),
            ]);
            $text->save();

            return $text;
        });

        $this->info("Texto {$text->version} publicado (sha256 {$text->sha256}). "
            .($text->approved ? 'Aprobado: los eventos de la campaña ya pueden abrir el QR.' : 'Sin aprobar: solo el operador puede registrar.'));

        return self::SUCCESS;
    }
}
