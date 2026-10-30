<?php

namespace App\Console\Commands;

use App\Support\CurrentCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Until there is a UI for it, campaigns are created by a superadmin or ops from the console.
class CreateCampaign extends Command
{
    protected $signature = 'campaign:create {name : Nombre visible de la campaña}
        {--slug= : Identificador único (por defecto, derivado del nombre)}
        {--parent= : Slug o id de la campaña madre, para crear una subcampaña}
        {--whatsapp : Habilita WhatsApp masivo y el agente para la campaña}';

    protected $description = 'Create a campaign (or a subcampaign with --parent)';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $slug = Str::slug((string) ($this->option('slug') ?: $name));
        if ($name === '' || $slug === '') {
            $this->error('El nombre y el slug no pueden quedar vacíos.');

            return self::FAILURE;
        }

        if (DB::table('campaigns')->where('slug', $slug)->exists()) {
            $this->error("Ya existe una campaña con el slug '{$slug}'.");

            return self::FAILURE;
        }

        $parentId = null;
        if ($this->option('parent') !== null) {
            $parentId = CurrentCampaign::resolveForCommand($this->option('parent'));
            if (! $parentId || $this->option('parent') === '') {
                $this->error('Campaña madre no encontrada: '.$this->option('parent'));

                return self::FAILURE;
            }
        }

        $id = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parentId,
            'whatsapp_enabled' => (bool) $this->option('whatsapp'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $parent = $parentId ? DB::table('campaigns')->where('id', $parentId)->value('name') : null;
        $this->info("Campaña creada: {$name} ({$slug}) id={$id}");
        $this->line('Campaña madre: '.($parent ?? 'ninguna (raíz)').' | WhatsApp: '.($this->option('whatsapp') ? 'sí' : 'no'));

        return self::SUCCESS;
    }
}
