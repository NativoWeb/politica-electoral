<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in, on a LOCAL PostgreSQL database (rolled back):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AgentRoNexosCampaignPostgresTest
class AgentRoNexosCampaignPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = 'migrations/2026_11_06_000001_scope_agent_ro_v_nexos_lider_to_campaign.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        if (! DB::selectOne("SELECT 1 AS ok FROM pg_namespace WHERE nspname = 'agent_ro'")) {
            $this->markTestSkipped('The local database has no agent_ro schema.');
        }
    }

    private function lider(string $campaignId, string $nombre): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => $campaignId, 'nombre' => $nombre, 'municipio' => 'X', 'cargo' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function nexo(string $campaignId, string $personId): string
    {
        $id = (string) Str::uuid();
        DB::table('nexos_familiares')->insert([
            'id' => $id, 'campaign_id' => $campaignId, 'person_id' => $personId, 'nombre' => 'Primo Nexo PG',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function liderIdInView(string $nexoId): ?string
    {
        return DB::selectOne('SELECT lider_id FROM agent_ro.v_nexos WHERE id = ?', [$nexoId])->lider_id;
    }

    public function test_a_nexo_only_exposes_a_lider_of_its_own_campaign(): void
    {
        $campB = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $campB, 'name' => 'Campaña B PG', 'slug' => 'campana-b-'.Str::random(6), 'whatsapp_enabled' => false]);
        $liderA = $this->lider(Campaign::DEFAULT_ID, 'Lider A Nexo PG');
        $liderB = $this->lider($campB, 'Lider B Nexo PG');
        $nexoAaA = $this->nexo(Campaign::DEFAULT_ID, $liderA);
        $nexoAaB = $this->nexo(Campaign::DEFAULT_ID, $liderB);

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame($liderA, $this->liderIdInView($nexoAaA));
        $this->assertNull($this->liderIdInView($nexoAaB));
        $this->assertSame(Campaign::DEFAULT_ID, DB::selectOne('SELECT campaign_id FROM agent_ro.v_nexos WHERE id = ?', [$nexoAaB])->campaign_id);

        // down() restores the previous definition (the leak), proving the test exercises the fix.
        $migration->down();
        $this->assertSame($liderB, $this->liderIdInView($nexoAaB));
        $migration->up();
        $this->assertNull($this->liderIdInView($nexoAaB));
    }

    public function test_columns_are_unchanged(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_schema', 'agent_ro')->where('table_name', 'v_nexos')
            ->orderBy('ordinal_position')->pluck('column_name')->all();

        $this->assertSame(['id', 'person_id', 'lider_id', 'nombre', 'parentesco', 'cargo', 'created_at', 'updated_at', 'campaign_id'], $columns);
    }

    public function test_select_is_granted_to_an_existing_agent_ro_role(): void
    {
        if (! DB::table('pg_roles')->where('rolname', 'agent_ro')->exists()) {
            DB::statement('CREATE ROLE agent_ro NOLOGIN');
        }

        (require database_path(self::MIGRATION))->up();

        $this->assertTrue((bool) DB::selectOne("SELECT has_table_privilege('agent_ro', 'agent_ro.v_nexos', 'SELECT') AS ok")->ok);
    }
}
