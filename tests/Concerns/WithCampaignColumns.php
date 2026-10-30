<?php

namespace Tests\Concerns;

use App\Models\Campaign;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SQLite fixtures built by hand: the campaigns table (CD with WhatsApp enabled) and the campaign_id columns of
// 2026_10_30_000001, with every existing row in "Centro Democrático" as in production.
trait WithCampaignColumns
{
    protected function addCampaignColumns(): void
    {
        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name');
                $t->string('slug')->unique();
                $t->uuid('parent_id')->nullable();
                $t->uuid('candidate_person_id')->nullable();
                $t->boolean('whatsapp_enabled')->default(false);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
            DB::table('campaigns')->insert(['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'whatsapp_enabled' => true]);
        }

        foreach (['users', 'lideres', 'nexos_familiares', 'whatsapp_templates', 'whatsapp_sends'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'campaign_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID));
            }
        }

        // A cédula is unique per campaign, not globally.
        if (Schema::hasColumn('lideres', 'cedula')) {
            DB::statement('DROP INDEX IF EXISTS lideres_cedula_unique');
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS lideres_campaign_id_cedula_unique ON lideres (campaign_id, cedula) WHERE cedula IS NOT NULL AND cedula <> ''");
        }
    }
}
