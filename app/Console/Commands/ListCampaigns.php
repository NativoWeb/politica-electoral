<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListCampaigns extends Command
{
    protected $signature = 'campaign:list';

    protected $description = 'List campaigns with their parent, WhatsApp flag and number of users and lideres';

    public function handle(): int
    {
        $users = DB::table('users')->selectRaw('campaign_id, count(*) as total')->groupBy('campaign_id')->pluck('total', 'campaign_id');
        $lideres = DB::table('lideres')->selectRaw('campaign_id, count(*) as total')->groupBy('campaign_id')->pluck('total', 'campaign_id');
        $campaigns = DB::table('campaigns')->orderBy('created_at')->orderBy('name')->get();
        $names = $campaigns->pluck('name', 'id');

        $this->table(
            ['id', 'nombre', 'slug', 'madre', 'whatsapp', 'activa', 'usuarios', 'líderes'],
            $campaigns->map(fn ($c) => [
                $c->id,
                $c->name,
                $c->slug,
                $c->parent_id ? ($names[$c->parent_id] ?? $c->parent_id) : '-',
                $c->whatsapp_enabled ? 'sí' : 'no',
                $c->is_active ? 'sí' : 'no',
                $users[$c->id] ?? 0,
                $lideres[$c->id] ?? 0,
            ])->all()
        );

        return self::SUCCESS;
    }
}
