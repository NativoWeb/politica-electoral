<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

// Opt-in checks of GET /api/agent/v1/votantes(/export) on a LOCAL PostgreSQL database (real schema, ilike search, jsonb audit):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AgentVotanteQueryApiPostgresTest
// Everything runs inside a transaction that is rolled back.
class AgentVotanteQueryApiPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $geoA;

    private string $geoB;

    private string $tag;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        config(['agent.hmac_secret' => self::SECRET]);

        $geos = DB::table('geographic_units')->where('type', 'municipality')->orderBy('id')->limit(2)->pluck('id');
        if ($geos->count() < 2) {
            $this->markTestSkipped('The local database needs two municipalities.');
        }
        [$this->geoA, $this->geoB] = $geos->all();

        // A tag no real row has, so the search isolates this test's rows from the local data.
        $this->tag = 'Zqx'.Str::lower(Str::random(6));
        $this->lider($this->geoA, "{$this->tag} Ana Autoriza", '1098700001', true);
        $this->lider($this->geoA, "{$this->tag} Bruno Sin Autorizar", '1098700002', false);
        $this->lider($this->geoB, "{$this->tag} Carla Fuera", '1098700003', true);

        $role = Role::where('code', 'R09_CAMPO')->firstOrFail();
        $this->campo = User::create([
            'name' => 'Campo PG Lectura', 'email' => 'campo-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'territory_scope' => [$this->geoA], 'permissions' => ['view.votantes', 'use.whatsapp'],
        ]);
        $this->campo->forceFill(['whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();
    }

    private function lider(string $geo, string $nombre, string $cedula, bool $autoriza): void
    {
        DB::table('lideres')->insert([
            'campaign_id' => Campaign::DEFAULT_ID, 'id' => (string) Str::uuid(), 'nombre' => $nombre, 'municipio' => 'X',
            'cargo' => '', 'cedula' => $cedula, 'telefono' => '315'.substr($cedula, -7), 'email' => "{$cedula}@correo.co",
            'autoriza_datos' => $autoriza, 'geographic_unit_id' => $geo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agent(string $path, array $query = []): TestResponse
    {
        $token = $this->campo->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $uri = $query === [] ? $path : $path.'?'.http_build_query($query);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $this->app['auth']->forgetGuards();

        return $this->call('GET', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\nGET\n{$uri}\n", self::SECRET),
        ]);
    }

    public function test_search_territory_and_authorization_on_postgresql(): void
    {
        $data = collect($this->agent('/api/agent/v1/votantes', ['q' => Str::upper($this->tag)])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data'))->keyBy('nombre');

        $this->assertFalse($data->has("{$this->tag} Carla Fuera"));
        $ana = $data["{$this->tag} Ana Autoriza"];
        $this->assertSame(['1098700001', '3158700001', '1098700001@correo.co'], [$ana['cedula'], $ana['telefono'], $ana['email']]);
        $bruno = $data["{$this->tag} Bruno Sin Autorizar"];
        $this->assertFalse($bruno['datos_autorizados']);
        $this->assertArrayNotHasKey('cedula', $bruno);

        // By cédula: only inside the territory.
        $this->agent('/api/agent/v1/votantes', ['q' => '1098700001'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->agent('/api/agent/v1/votantes', ['q' => '1098700003'])->assertOk()->assertJsonPath('meta.total', 0);
        // ...and only of who authorized their data (Bruno did not), while his name is still found.
        $this->agent('/api/agent/v1/votantes', ['q' => '1098700002'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->agent('/api/agent/v1/votantes', ['q' => "{$this->tag} bruno"])->assertOk()->assertJsonPath('meta.total', 1);
        $this->agent('/api/agent/v1/votantes', ['municipio_id' => $this->geoB, 'q' => $this->tag])->assertForbidden();
    }

    public function test_audit_rows_in_jsonb_keep_counts_and_no_personal_data(): void
    {
        $before = DB::table('audit_logs')->count();
        $this->agent('/api/agent/v1/votantes', ['q' => '1098700001'])->assertOk();
        $export = $this->agent('/api/agent/v1/votantes/export', ['q' => $this->tag])->assertOk()->assertHeader('X-Total-Count', '2');

        $rows = IOFactory::load($export->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false);
        $this->assertCount(3, $rows);

        $logs = DB::table('audit_logs')->where('user_id', $this->campo->id)->orderBy('created_at')->get();
        $this->assertSame($before + 2, DB::table('audit_logs')->count());
        $this->assertEqualsCanonicalizing(['agent.votantes.list', 'agent.votantes.export'], $logs->pluck('action')->all());
        $list = $logs->firstWhere('action', 'agent.votantes.list');
        $meta = json_decode($list->metadata, true);
        $this->assertSame('0001', $meta['filtros']['q_last4']);
        $this->assertSame([1, 1, 1], [$meta['total'], $meta['entregadas'], $meta['con_datos']]);
        $this->assertSame(1, (int) DB::selectOne("SELECT (metadata->>'con_datos')::int AS n FROM audit_logs WHERE id = ?", [$list->id])->n);

        $json = $logs->pluck('metadata')->implode("\n");
        foreach (['1098700001', '3158700001', '1098700001@correo.co', 'Ana Autoriza', $this->tag] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
    }

    public function test_daily_quota_on_postgresql(): void
    {
        config(['agent.contact_rows_daily_max' => 1]);

        $this->agent('/api/agent/v1/votantes/export', ['q' => $this->tag])->assertOk();
        $this->agent('/api/agent/v1/votantes', ['q' => $this->tag])
            ->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Alcanzaste el límite diario de datos de contacto.']);
    }
}
