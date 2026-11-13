<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentContactQuota;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Opt-in checks of GET /api/agent/v1/personas/{id}/ficha(/pdf) on a LOCAL PostgreSQL database (persona() uses ilike):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AgentFichaApiPostgresTest
// Everything runs inside a transaction that is rolled back.
class AgentFichaApiPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $mun;

    private string $tag;

    private string $bruno;

    private string $brunoPerson;

    private string $elenaPerson;

    private string $pedroPerson;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        config(['agent.hmac_secret' => self::SECRET]);
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['whatsapp_enabled' => true]);
        $this->tag = 'Zqf'.Str::lower(Str::random(6));
        $this->mun = $this->municipio("{$this->tag} Bucaramanga");

        $this->bruno = $this->lider("{$this->tag} Bruno Sin Autorizar", '1098800002', false, ['barrio' => "{$this->tag} Poblado"]);
        $this->lider("{$this->tag} Elena Maria Electa", '1098800003', false);
        $this->brunoPerson = $this->candidate("{$this->tag} Bruno Sin Autorizar");
        $this->elenaPerson = $this->candidate("{$this->tag} Elena Electa");
        $this->pedroPerson = $this->candidate("{$this->tag} Pedro Solo");

        $this->campo = $this->user([$this->mun], ['view.mapa', 'view.votantes']);
    }

    private function municipio(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => 'municipality', 'canonical_name' => $name]);

        return $id;
    }

    private function lider(string $nombre, string $cedula, bool $autoriza, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'campaign_id' => Campaign::DEFAULT_ID, 'id' => $id, 'nombre' => $nombre, 'cargo' => 'Líder',
            'municipio' => DB::table('geographic_units')->where('id', $this->mun)->value('canonical_name'),
            'cedula' => $cedula, 'telefono' => '315'.substr($cedula, -7), 'email' => "{$cedula}@correo.co", 'observacion' => "Nota {$cedula}",
            'autoriza_datos' => $autoriza, 'geographic_unit_id' => $this->mun, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);

        return $id;
    }

    private function candidate(string $fullName): string
    {
        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => "Alcaldía {$this->tag} {$fullName}",
            'electoral_event_id' => DB::table('contests')->whereNotNull('electoral_event_id')->value('electoral_event_id'),
            'office_id' => DB::table('offices')->where('name', 'Alcaldía')->value('id'), 'geographic_unit_id' => $this->mun,
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $personId, 'first_name' => 'Zq', 'last_name' => 'Candidato', 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName)]);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $personId, 'contest_id' => $contestId, 'outcome' => 'not_elected']);

        return $personId;
    }

    private function user(?array $scope, array $permissions): User
    {
        $user = User::create([
            'name' => 'Ficha PG', 'email' => 'ficha-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R09_CAMPO')->value('id'), 'territory_scope' => $scope, 'permissions' => [...$permissions, 'use.whatsapp'],
        ]);
        $user->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function agent(string $path, array $query = [], ?User $user = null): TestResponse
    {
        $token = ($user ?? $this->campo)->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
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

    private function ficha(string $id, ?User $user = null): TestResponse
    {
        return $this->agent("/api/agent/v1/personas/{$id}/ficha", [], $user);
    }

    private function assertParity(string $id): array
    {
        $web = $this->actingAs($this->campo)->getJson("/mapa-politico/persona/{$id}")->assertOk()->json();
        $agent = $this->ficha($id)->assertOk()->json('data');

        $this->assertArrayHasKey('partidos', $web);
        $this->assertArrayNotHasKey('partidos', $agent);
        $this->assertArrayNotHasKey('foto', $agent);
        $this->assertSame([], array_values(array_diff(array_keys($web), array_keys($agent), ['foto', 'partidos'])));
        foreach (array_intersect_key($web, $agent) as $key => $value) {
            $this->assertSame($value, $agent[$key], "La llave {$key} difiere de la web.");
        }

        return $agent;
    }

    public function test_lider_ficha_matches_the_web_and_carries_unauthorized_contact_data(): void
    {
        $quota = app(AgentContactQuota::class);
        $before = $quota->used($this->campo->id);

        $data = $this->assertParity($this->bruno);

        $this->assertSame('1098800002', $data['cedula']);
        $this->assertSame('3158800002', $data['telefono']);
        $this->assertSame('1098800002@correo.co', $data['email']);
        $this->assertSame('Nota 1098800002', $data['observacion']);
        $this->assertFalse($data['datos_autorizados']);
        $this->assertSame($before + 1, $quota->used($this->campo->id));
    }

    public function test_a_candidate_linked_to_a_lider_opens_as_that_lider(): void
    {
        $this->ficha($this->brunoPerson)->assertOk()
            ->assertJsonPath('data.source', 'lider')
            ->assertJsonPath('data.id', $this->bruno);

        $this->assertSame($this->bruno, AuditLog::where('action', 'agent.persona.ficha')->latest('created_at')->first()->entity_id);
    }

    public function test_a_candidate_without_ficha_takes_contacts_by_name_like_the_web_and_says_so(): void
    {
        $data = $this->assertParity($this->elenaPerson);

        $this->assertSame('persona', $data['source']);
        $this->assertSame('3158800003', $data['telefono']);
        $this->assertTrue($data['coincidencia_por_nombre']);
        $this->assertFalse($data['datos_autorizados']);
        $this->assertNull($data['seguimientos']);
        $this->assertNull($data['eventos']);
    }

    public function test_a_candidate_without_any_match_costs_nothing(): void
    {
        $this->ficha($this->pedroPerson)->assertOk()
            ->assertJsonPath('data.source', 'persona')
            ->assertJsonPath('data.coincidencia_por_nombre', false)
            ->assertJsonPath('meta.con_datos', 0);
        $this->assertSame(0, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_a_nexo_alone_is_no_match_by_name_but_still_costs_one(): void
    {
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'person_id' => $this->pedroPerson,
            'nombre' => "{$this->tag} Hijo", 'parentesco' => 'Hijo', 'telefono' => '3009998877', 'created_at' => now(), 'updated_at' => now()]);

        $this->ficha($this->pedroPerson)->assertOk()
            ->assertJsonPath('data.source', 'persona')
            ->assertJsonPath('data.nexos.0.telefono', '3009998877')
            ->assertJsonPath('data.coincidencia_por_nombre', false)
            ->assertJsonPath('meta.con_datos', 1);
        $this->assertSame(1, app(AgentContactQuota::class)->used($this->campo->id));
    }

    public function test_only_votantes_cannot_open_an_electoral_person_and_outside_the_territory_is_403(): void
    {
        $votantes = $this->user([$this->mun], ['view.votantes']);
        $this->ficha($this->elenaPerson, $votantes)->assertForbidden();
        $this->ficha($this->bruno, $votantes)->assertOk();

        $otro = $this->user([$this->municipio("{$this->tag} Lebrija")], ['view.mapa']);
        $this->ficha($this->bruno, $otro)->assertForbidden();
        $this->ficha($this->elenaPerson, $otro)->assertForbidden();
    }

    public function test_pdf_on_postgres(): void
    {
        $response = $this->agent("/api/agent/v1/personas/{$this->elenaPerson}/ficha/pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Rows-With-Data', '1');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, AuditLog::where('action', 'agent.persona.ficha_pdf')->where('user_id', $this->campo->id)->count());
    }

    public function test_lists_still_hide_the_contact_data_of_who_did_not_authorize(): void
    {
        $this->ficha($this->bruno)->assertOk();

        $votantes = $this->agent('/api/agent/v1/votantes', ['q' => "{$this->tag} Bruno"])->assertOk();
        $this->assertSame([$this->bruno], array_column($votantes->json('data'), 'id'));
        $this->assertArrayNotHasKey('cedula', $votantes->json('data.0'));
        $this->assertArrayNotHasKey('telefono', $votantes->json('data.0'));

        $mapa = $this->agent('/api/agent/v1/mapa', ['q' => "{$this->tag} Bruno", 'municipio_id' => $this->mun])->assertOk();
        $this->assertNotEmpty($mapa->json('data'));
        $this->assertStringNotContainsString('1098800002', $mapa->getContent());
        $this->assertStringNotContainsString('3158800002', $mapa->getContent());
    }
}
