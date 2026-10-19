<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Read-only surface for the WhatsApp agent. Views run with the owner's privileges, so the
// agent_ro role (deploy/sql/agent_ro_role.sql) only needs SELECT on this schema.
// They do NOT apply territory_scope: the agent must filter with the user's whoami territory.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS agent_ro');

        foreach ($this->views() as $name => $sql) {
            DB::statement("CREATE OR REPLACE VIEW agent_ro.{$name} AS {$sql}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP SCHEMA IF EXISTS agent_ro CASCADE');
    }

    private function views(): array
    {
        $digits = fn (string $col) => "right(regexp_replace(coalesce({$col}, ''), '[^0-9]', '', 'g'), 4)";

        return [
            'v_lideres' => "
                SELECT l.id, l.nombre, l.municipio, l.provincia, l.geographic_unit_id, l.cargo, l.tipo,
                       l.partido, l.profesion, l.nivel_confianza,
                       l.destacado, l.gran_elector, l.militante, l.autoriza_datos, l.verificado, l.fallecido,
                       l.empresario, l.reservista, l.funcionario, l.exfuncionario,
                       l.votos, l.is_active, l.puesto_votacion,
                       ref.id AS referente_id,
                       to_char(l.fecha_nacimiento, 'MM-DD') AS cumple_mm_dd,
                       l.created_at, l.updated_at,
                       nullif({$digits('l.cedula')}, '') AS cedula_last4,
                       CASE WHEN l.autoriza_datos IS TRUE THEN nullif({$digits('l.telefono')}, '') END AS telefono_last4
                FROM public.lideres l
                LEFT JOIN LATERAL (
                    SELECT r.id FROM public.lideres r
                    WHERE l.referente_documento IS NOT NULL AND l.referente_documento <> ''
                      AND r.cedula = l.referente_documento
                    LIMIT 1
                ) ref ON true",

            'v_lider_contactos' => '
                SELECT c.id, c.lider_id, c.tipo, c.principal, c.estado,
                       CASE WHEN l.autoriza_datos IS TRUE THEN right(c.valor, 4) END AS valor_last4,
                       c.created_at, c.updated_at
                FROM public.lider_contactos c
                JOIN public.lideres l ON l.id = c.lider_id',

            'v_lider_info_politica' => '
                SELECT id, lider_id, cargo_politico, departamento, municipio, partido, aliado, votos, estado,
                       created_at, updated_at
                FROM public.lider_info_politica',

            'v_nexos' => '
                SELECT n.id, n.person_id,
                       CASE WHEN EXISTS (SELECT 1 FROM public.lideres l WHERE l.id = n.person_id) THEN n.person_id END AS lider_id,
                       n.nombre, n.parentesco, n.cargo, n.created_at, n.updated_at
                FROM public.nexos_familiares n',

            'v_geo' => '
                SELECT g.id, g.canonical_name, g.type, g.level, g.parent_id, g.official_code, g.is_active,
                       ST_Y(ST_Centroid(coalesce(g.geom_simplified, g.geom))) AS lat,
                       ST_X(ST_Centroid(coalesce(g.geom_simplified, g.geom))) AS lng
                FROM public.geographic_units g',

            'v_persons' => '
                SELECT id, first_name, last_name, full_name, normalized_name, gender, document_type,
                       identity_status, merged_into_id, data_classification, created_at, updated_at
                FROM public.persons
                WHERE deleted_at IS NULL',

            'v_candidacies' => '
                SELECT id, person_id, contest_id, electoral_list_id, list_position, outcome, outcome_source,
                       outcome_date, created_at, updated_at
                FROM public.candidacies',

            'v_contests' => '
                SELECT id, electoral_event_id, office_id, corporation_id, electoral_district_id, geographic_unit_id,
                       name, seats, list_type, status, created_at, updated_at
                FROM public.contests',

            'v_events' => '
                SELECT id, name, event_type, election_date, political_period, authority, scope, status,
                       created_at, updated_at
                FROM public.electoral_events',

            'v_results' => '
                SELECT id, contest_id, candidacy_id, electoral_list_id, organization_id, geographic_unit_id,
                       grain, metric_type, value, percentage, ranking, status, created_at, updated_at
                FROM public.electoral_results',

            'v_turnout' => '
                SELECT id, contest_id, electoral_event_id, geographic_unit_id, grain, electoral_potential,
                       total_voters, valid_votes, blank_votes, null_votes, unmarked_votes, participation_pct,
                       created_at, updated_at
                FROM public.turnout_metrics',

            'v_orgs' => '
                SELECT id, type, canonical_name, acronym, color_hex, status, valid_from, valid_to, merged_into_id,
                       created_at, updated_at
                FROM public.political_organizations',

            'v_offices' => '
                SELECT id, name, type, is_uninominal FROM public.offices',

            'v_corporations' => '
                SELECT id, name, scope, default_seats FROM public.corporations',

            'v_users_min' => '
                SELECT u.id, u.name, r.code AS role_code
                FROM public.users u
                LEFT JOIN public.roles r ON r.id = u.role_id',
        ];
    }
};
