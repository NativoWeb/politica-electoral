<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Opt-in checks of the agent write API on a LOCAL PostgreSQL database (real schema, jsonb audit, agent_ro views):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AgentWriteApiPostgresTest
// Everything runs inside a transaction that is rolled back.
class AgentWriteApiPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $geo;

    private string $lider;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        config(['agent.hmac_secret' => self::SECRET]);

        $this->geo = DB::table('geographic_units')->where('type', 'municipality')->value('id')
            ?? $this->markTestSkipped('The local database has no municipalities.');

        $this->lider = (string) Str::uuid();
        DB::table('lideres')->insert([
            'campaign_id' => Campaign::DEFAULT_ID,
            'id' => $this->lider, 'nombre' => 'Lider PG Agente', 'municipio' => 'X', 'cargo' => '',
            'geographic_unit_id' => $this->geo, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $role = Role::where('code', 'R09_CAMPO')->firstOrFail();
        $this->campo = User::create([
            'name' => 'Campo PG', 'email' => 'campo-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'territory_scope' => [$this->geo], 'permissions' => ['view.votantes', 'write.data'],
        ]);
        $this->campo->forceFill(['whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();
    }

    private function agent(string $method, string $uri, ?array $body, array $headers = []): TestResponse
    {
        $token = $this->campo->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $raw = $body === null ? '' : json_encode($body);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\n{$method}\n{$uri}\n{$raw}", self::SECRET),
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->app['auth']->forgetGuards();

        return $this->call($method, $uri, [], [], [], $server, $raw);
    }

    public function test_create_and_patch_votante_write_audit_with_correlation_id(): void
    {
        $correlation = (string) Str::uuid();

        $id = $this->agent('POST', '/api/agent/v1/votantes', [
            'nombre' => 'Creada PG Agente', 'municipio_id' => $this->geo, 'nivel_confianza' => 'contactado',
        ], ['X-Correlation-Id' => $correlation])->assertCreated()->json('data.id');

        $this->agent('PATCH', "/api/agent/v1/votantes/{$id}", ['verificado' => true, 'mesa_votacion' => '7'], ['X-Correlation-Id' => $correlation])
            ->assertOk();

        $this->assertDatabaseHas('lideres', ['id' => $id, 'geographic_unit_id' => $this->geo, 'verificado' => true, 'mesa_votacion' => '7']);
        $this->assertSame(2, DB::table('audit_logs')->where('correlation_id', $correlation)->where('user_id', $this->campo->id)->count());
    }

    public function test_seguimiento_shows_up_in_agent_ro_view(): void
    {
        $id = $this->agent('POST', "/api/agent/v1/votantes/{$this->lider}/seguimientos", ['tipo' => 'reunion', 'nota' => 'Reunión PG'])
            ->assertCreated()
            ->json('data.id');

        $this->agent('GET', "/api/agent/v1/votantes/{$this->lider}/seguimientos", null)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $row = DB::selectOne('SELECT lider_id, user_id, user_name, tipo FROM agent_ro.v_lider_seguimientos WHERE id = ?', [$id]);
        $this->assertNotNull($row);
        $this->assertSame($this->lider, $row->lider_id);
        $this->assertSame($this->campo->id, $row->user_id);
        $this->assertSame('Campo PG', $row->user_name);
        $this->assertSame('reunion', $row->tipo);
    }

    public function test_view_migration_grants_select_to_an_existing_agent_ro_role(): void
    {
        if (! DB::table('pg_roles')->where('rolname', 'agent_ro')->exists()) {
            DB::statement('CREATE ROLE agent_ro NOLOGIN');
        }

        // Later migrations add columns to the view; CREATE OR REPLACE cannot drop them (rolled back with the test).
        DB::statement('DROP VIEW IF EXISTS agent_ro.v_lider_seguimientos');
        (require database_path('migrations/2026_10_20_000002_create_agent_ro_v_lider_seguimientos_view.php'))->up();

        $this->assertTrue((bool) DB::selectOne(
            "SELECT has_table_privilege('agent_ro', 'agent_ro.v_lider_seguimientos', 'SELECT') AS ok"
        )->ok);
        $this->assertFalse((bool) DB::selectOne(
            "SELECT has_table_privilege('agent_ro', 'public.lider_seguimientos', 'SELECT') AS ok"
        )->ok);
    }

    public function test_seguimiento_tipo_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('lider_seguimientos')->insert([
            'id' => (string) Str::uuid(), 'lider_id' => $this->lider, 'user_id' => $this->campo->id,
            'tipo' => 'fiesta', 'nota' => 'x', 'fecha' => now(),
        ]);
    }
}
