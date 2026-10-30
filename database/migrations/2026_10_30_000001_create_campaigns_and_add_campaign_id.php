<?php

use App\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Step 1 of multi-campaign: every existing row goes to "Centro Democrático" (Campaign::DEFAULT_ID), which is
// also the column default, so code that does not know about campaigns keeps working unchanged.
// On PostgreSQL raw SQL is used on purpose: production was altered by hand and the agent_ro views depend on
// lideres, so ->change() is not an option. The agent_ro views gain campaign_id as their LAST column.
return new class extends Migration
{
    private const TABLES = ['users', 'lideres', 'nexos_familiares', 'whatsapp_templates', 'whatsapp_sends'];

    private const CAMPAIGN_VIEWS = ['v_lideres', 'v_lider_contactos', 'v_lider_info_politica', 'v_nexos', 'v_lider_seguimientos'];

    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->uuid('parent_id')->nullable()->index();
            $table->uuid('candidate_person_id')->nullable()->index();
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('candidate_person_id')->references('id')->on('persons');
        });
        // Separate statement: on PostgreSQL a self-reference needs the primary key to exist first.
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('campaigns');
        });

        DB::table('campaigns')->insert([
            'id' => Campaign::DEFAULT_ID,
            'name' => 'Centro Democrático',
            'slug' => 'centro-democratico',
            'whatsapp_enabled' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            $this->upPgsql();
        } else {
            foreach (self::TABLES as $table) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->uuid('campaign_id')->nullable()->default(Campaign::DEFAULT_ID);
                    $t->index($table === 'lideres' ? ['campaign_id', 'geographic_unit_id'] : ['campaign_id']);
                });
            }
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS lideres_campaign_id_cedula_unique ON lideres (campaign_id, cedula) WHERE cedula IS NOT NULL AND cedula <> ''");
        DB::statement('DROP INDEX IF EXISTS lideres_cedula_unique');

        if (DB::getDriverName() === 'pgsql' && $this->agentSchemaExists()) {
            $this->replaceViews(true);
        }
    }

    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        // CREATE OR REPLACE cannot drop a column: the old definitions are created again, then re-granted.
        if ($pgsql && $this->agentSchemaExists()) {
            foreach (self::CAMPAIGN_VIEWS as $name) {
                DB::statement("DROP VIEW IF EXISTS agent_ro.{$name}");
            }
            $this->replaceViews(false);
        }

        DB::statement('DROP INDEX IF EXISTS lideres_campaign_id_cedula_unique');
        // Fails if the same cédula now exists in two campaigns: those rows must be resolved by hand first.
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS lideres_cedula_unique ON lideres(cedula) WHERE cedula IS NOT NULL AND cedula != ''");

        foreach (self::TABLES as $table) {
            if ($pgsql) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS campaign_id");

                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table === 'lideres' ? ['campaign_id', 'geographic_unit_id'] : ['campaign_id']);
                $t->dropColumn('campaign_id');
            });
        }

        Schema::dropIfExists('campaigns');
    }

    private function upPgsql(): void
    {
        $default = Campaign::DEFAULT_ID;

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS campaign_id uuid");
            DB::update("UPDATE {$table} SET campaign_id = ? WHERE campaign_id IS NULL", [$default]);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN campaign_id SET DEFAULT '{$default}'");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN campaign_id SET NOT NULL");

            $fk = "{$table}_campaign_id_foreign";
            $exists = DB::selectOne('SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$fk, $table]);
            if (! $exists) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$fk} FOREIGN KEY (campaign_id) REFERENCES campaigns(id)");
            }

            if ($table === 'lideres') {
                DB::statement('CREATE INDEX IF NOT EXISTS lideres_campaign_id_geographic_unit_id_index ON lideres (campaign_id, geographic_unit_id)');
            } else {
                DB::statement("CREATE INDEX IF NOT EXISTS {$table}_campaign_id_index ON {$table} (campaign_id)");
            }
        }
    }

    private function agentSchemaExists(): bool
    {
        return (bool) DB::selectOne("SELECT 1 AS ok FROM pg_namespace WHERE nspname = 'agent_ro'");
    }

    private function replaceViews(bool $withCampaign): void
    {
        foreach ($this->views($withCampaign) as $name => $sql) {
            DB::statement("CREATE OR REPLACE VIEW agent_ro.{$name} AS {$sql}");
        }

        // Same guard as 2026_10_20_000002: SELECT is granted only when the agent_ro role already exists.
        $grants = implode("\n", array_map(fn ($v) => "GRANT SELECT ON agent_ro.{$v} TO agent_ro;", self::CAMPAIGN_VIEWS));
        DB::statement("
            DO \$\$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'agent_ro') THEN
                    {$grants}
                END IF;
            END \$\$");
    }

    // Definitions of 2026_10_19_200000 and 2026_10_20_000002; $withCampaign appends campaign_id at the end
    // and resolves the referente of v_lideres inside the same campaign.
    private function views(bool $withCampaign): array
    {
        $digits = fn (string $col) => "right(regexp_replace(coalesce({$col}, ''), '[^0-9]', '', 'g'), 4)";
        $col = fn (string $expr) => $withCampaign ? ", {$expr} AS campaign_id" : '';
        $sameCampaign = $withCampaign ? 'AND r.campaign_id = l.campaign_id' : '';
        $joinLider = fn (string $alias) => $withCampaign ? "LEFT JOIN public.lideres l ON l.id = {$alias}.lider_id" : '';

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
                       {$col('l.campaign_id')}
                FROM public.lideres l
                LEFT JOIN LATERAL (
                    SELECT r.id FROM public.lideres r
                    WHERE l.referente_documento IS NOT NULL AND l.referente_documento <> ''
                      AND r.cedula = l.referente_documento
                      {$sameCampaign}
                    LIMIT 1
                ) ref ON true",

            'v_lider_contactos' => "
                SELECT c.id, c.lider_id, c.tipo, c.principal, c.estado,
                       CASE WHEN l.autoriza_datos IS TRUE THEN right(c.valor, 4) END AS valor_last4,
                       c.created_at, c.updated_at
                       {$col('l.campaign_id')}
                FROM public.lider_contactos c
                JOIN public.lideres l ON l.id = c.lider_id",

            // Scalar subquery instead of a join: the view stays a simple (auto-updatable) one, so writes keep
            // failing on privileges exactly as before.
            'v_lider_info_politica' => "
                SELECT i.id, i.lider_id, i.cargo_politico, i.departamento, i.municipio, i.partido, i.aliado,
                       i.votos, i.estado, i.created_at, i.updated_at
                       {$col('(SELECT l.campaign_id FROM public.lideres l WHERE l.id = i.lider_id)')}
                FROM public.lider_info_politica i",

            'v_nexos' => "
                SELECT n.id, n.person_id,
                       CASE WHEN EXISTS (SELECT 1 FROM public.lideres l WHERE l.id = n.person_id) THEN n.person_id END AS lider_id,
                       n.nombre, n.parentesco, n.cargo, n.created_at, n.updated_at
                       {$col('n.campaign_id')}
                FROM public.nexos_familiares n",

            'v_lider_seguimientos' => "
                SELECT s.id, s.lider_id, s.user_id, u.name AS user_name, s.tipo, s.fecha,
                       s.created_at, s.updated_at
                       {$col('l.campaign_id')}
                FROM public.lider_seguimientos s
                LEFT JOIN public.users u ON u.id = s.user_id
                {$joinLider('s')}",
        ];
    }
};
