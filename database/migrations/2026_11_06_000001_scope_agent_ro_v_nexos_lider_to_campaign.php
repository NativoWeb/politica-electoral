<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// agent_ro.v_nexos (2026_10_30_000001) gave lider_id whenever the nexo's person_id was a lider of ANY campaign, so a
// nexo of campaign A pointing to a lider of campaign B exposed B's lider id. The lider must be in the nexo's campaign.
// Same columns, so CREATE OR REPLACE keeps the view's grants; SELECT is granted again only if the agent_ro role exists.
return new class extends Migration
{
    public function up(): void
    {
        $this->replace('AND l.campaign_id = n.campaign_id');
    }

    public function down(): void
    {
        $this->replace('');
    }

    private function replace(string $sameCampaign): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! DB::selectOne("SELECT 1 AS ok FROM pg_namespace WHERE nspname = 'agent_ro'")) {
            return;
        }

        DB::statement("
            CREATE OR REPLACE VIEW agent_ro.v_nexos AS
            SELECT n.id, n.person_id,
                   CASE WHEN EXISTS (SELECT 1 FROM public.lideres l WHERE l.id = n.person_id {$sameCampaign}) THEN n.person_id END AS lider_id,
                   n.nombre, n.parentesco, n.cargo, n.created_at, n.updated_at, n.campaign_id AS campaign_id
            FROM public.nexos_familiares n");

        DB::statement("
            DO \$\$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'agent_ro') THEN
                    GRANT SELECT ON agent_ro.v_nexos TO agent_ro;
                END IF;
            END \$\$");
    }
};
