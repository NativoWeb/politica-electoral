<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the follow-ups to the agent's read-only schema (2026_10_19_200000 is not edited once deployed).
// The free-text nota is NOT exposed (views ignore territory); it is read through GET /votantes/{id}/seguimientos.
// deploy/sql/agent_ro_role.sql sets ALTER DEFAULT PRIVILEGES only for views created by the role that ran
// the script; if it ran as another role (e.g. postgres) this view would not be readable, so SELECT is
// granted here too whenever the agent_ro role already exists. Re-running the script is also safe.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE OR REPLACE VIEW agent_ro.v_lider_seguimientos AS
            SELECT s.id, s.lider_id, s.user_id, u.name AS user_name, s.tipo, s.fecha,
                   s.created_at, s.updated_at
            FROM public.lider_seguimientos s
            LEFT JOIN public.users u ON u.id = s.user_id');

        DB::statement("
            DO \$\$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'agent_ro') THEN
                    GRANT SELECT ON agent_ro.v_lider_seguimientos TO agent_ro;
                END IF;
            END \$\$");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS agent_ro.v_lider_seguimientos');
    }
};
