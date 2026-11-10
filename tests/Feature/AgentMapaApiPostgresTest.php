<?php

namespace Tests\Feature;

use App\Actions\Eventos\ApproveAttendees;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Opt-in checks of GET /api/agent/v1/mapa(/export) on a LOCAL PostgreSQL database (real schema, ilike and unnest):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AgentMapaApiPostgresTest
// Everything runs inside a transaction that is rolled back.
class AgentMapaApiPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private string $mun;

    private string $otroMun;

    private string $tag;

    private User $campo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        config(['agent.hmac_secret' => self::SECRET]);
        $this->tag = 'Zqx'.Str::lower(Str::random(6));
        $this->mun = $this->municipio("{$this->tag} Bucaramanga");
        $this->otroMun = $this->municipio("{$this->tag} Lebrija");

        $this->lider($this->mun, "{$this->tag} Ana Autoriza", '1098700001', true, ['barrio' => "{$this->tag} Poblado", 'cargo' => 'Coordinadora']);
        $this->lider($this->mun, "{$this->tag} Bruno Sin Autorizar", '1098700002', false, ['barrio' => "{$this->tag} Poblado"]);
        $this->lider($this->mun, "{$this->tag} Dora Directorio", '1098700003', false, ['cargo' => 'Directorio Municipal']);
        $this->lider($this->mun, "{$this->tag} Nina Nueva", '1098700004', true, ['tipo' => 'nuevo_lider', 'barrio' => "{$this->tag} Centro"]);
        $this->lider($this->otroMun, "{$this->tag} Carla Fuera", '1098700005', true);
        $this->candidate("{$this->tag} Elena Electa", 'elected');
        $this->candidate("{$this->tag} Bruno Sin Autorizar", 'not_elected');

        $this->campo = $this->user([$this->mun], ['view.mapa']);
    }

    private function municipio(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => 'municipality', 'canonical_name' => $name]);

        return $id;
    }

    private function lider(string $geo, string $nombre, string $cedula, bool $autoriza, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'campaign_id' => Campaign::DEFAULT_ID, 'id' => $id, 'nombre' => $nombre, 'cargo' => 'Líder',
            'municipio' => DB::table('geographic_units')->where('id', $geo)->value('canonical_name'),
            'cedula' => $cedula, 'telefono' => '315'.substr($cedula, -7), 'email' => "{$cedula}@correo.co", 'observacion' => "Nota {$cedula}",
            'autoriza_datos' => $autoriza, 'geographic_unit_id' => $geo, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);

        return $id;
    }

    private function candidate(string $fullName, string $outcome, ?string $partido = null): void
    {
        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => "Alcaldía {$this->tag} {$fullName}",
            'electoral_event_id' => DB::table('contests')->whereNotNull('electoral_event_id')->value('electoral_event_id'),
            'office_id' => DB::table('offices')->where('name', 'Alcaldía')->value('id'), 'geographic_unit_id' => $this->mun,
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $personId, 'first_name' => 'Zq', 'last_name' => 'Candidato', 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName)]);
        $candidacyId = (string) Str::uuid();
        DB::table('candidacies')->insert(['id' => $candidacyId, 'person_id' => $personId, 'contest_id' => $contestId, 'outcome' => $outcome]);
        if ($partido) {
            $org = DB::table('political_organizations')->where('canonical_name', $partido)->value('id');
            if (! $org) {
                $org = (string) Str::uuid();
                DB::table('political_organizations')->insert(['id' => $org, 'type' => 'party', 'canonical_name' => $partido]);
            }
            DB::table('candidacy_endorsements')->insert(['id' => (string) Str::uuid(), 'candidacy_id' => $candidacyId, 'organization_id' => $org, 'endorsement_type' => 'party', 'is_primary' => true]);
        }
    }

    private function user(?array $scope, array $permissions): User
    {
        $user = User::create([
            'name' => 'Mapa PG', 'email' => 'mapa-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
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

    private function list(array $query = [], ?User $user = null): TestResponse
    {
        return $this->agent('/api/agent/v1/mapa', $query, $user);
    }

    private function names(TestResponse $response): array
    {
        return array_map(fn ($n) => trim(str_replace($this->tag, '', $n)), array_column($response->json('data'), 'nombre'));
    }

    public function test_lists_lideres_directorio_and_candidates_of_the_municipio(): void
    {
        $response = $this->list(['municipio_id' => $this->mun, 'per_page' => 50])->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.con_datos', 2)
            ->assertJsonPath('meta.secciones.Alcaldía', 2)
            ->assertJsonPath('meta.secciones.Directorio Municipal', 1)
            ->assertJsonPath('meta.secciones.Nuevos Líderes', 1);

        $this->assertEqualsCanonicalizing(['Elena Electa', 'Bruno Sin Autorizar', 'Ana Autoriza', 'Dora Directorio', 'Nina Nueva'], $this->names($response));
    }

    public function test_candidates_are_excluded_by_tipo(): void
    {
        $response = $this->list(['municipio_id' => $this->mun, 'tipo' => 'lideres,nuevos_lideres,directorio'])->assertOk()
            ->assertJsonPath('meta.total', 4);

        $this->assertEqualsCanonicalizing(['Ana Autoriza', 'Bruno Sin Autorizar', 'Dora Directorio', 'Nina Nueva'], $this->names($response));
        $this->assertArrayNotHasKey('Alcaldía', $response->json('meta.secciones'));
    }

    public function test_a_cargo_filter_gives_the_real_total(): void
    {
        $response = $this->list(['municipio_id' => $this->mun, 'cargo' => 'directorio municipal'])->assertOk()->assertJsonPath('meta.total', 1);

        $this->assertSame(['Dora Directorio'], $this->names($response));
    }

    public function test_barrio_counts_in_meta_total(): void
    {
        $response = $this->list(['barrio' => "{$this->tag} Poblado", 'per_page' => 1])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2);
        $this->assertSame(2, $response->json('meta.last_page'));

        $this->list(['barrio' => "{$this->tag} Inexistente"])->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_barrio_alone_is_not_limited_by_the_web_listing_caps(): void
    {
        $consulta = $this->user(null, ['view.mapa']);
        for ($i = 0; $i < 205; $i++) {
            $this->lider($this->otroMun, "{$this->tag} Relleno {$i}", '77'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), false, ['barrio' => "{$this->tag} Masivo"]);
        }

        $this->list(['barrio' => "{$this->tag} Masivo"], $consulta)->assertOk()->assertJsonPath('meta.total', 205);
    }

    public function test_total_without_filters_is_the_same_as_the_export(): void
    {
        config(['agent.contact_rows_daily_max' => 100000]);
        $consulta = $this->user(null, ['view.mapa']);

        $total = $this->list([], $consulta)->assertOk()->json('meta.total');

        $this->agent('/api/agent/v1/mapa/export', [], $consulta)->assertOk()->assertHeader('X-Total-Count', (string) $total);
    }

    public function test_total_with_partido_counts_the_candidates_who_lost_and_matches_the_export(): void
    {
        $consulta = $this->user(null, ['view.mapa']);
        $partido = "PARTIDO {$this->tag}";
        $this->candidate("{$this->tag} Gana", 'elected', $partido);
        $this->candidate("{$this->tag} Pierde", 'not_elected', $partido);

        $this->list(['partido' => $partido], $consulta)->assertOk()->assertJsonPath('meta.total', 2);
        $this->agent('/api/agent/v1/mapa/export', ['partido' => $partido], $consulta)->assertOk()->assertHeader('X-Total-Count', '2');
    }

    public function test_a_namesake_barrio_does_not_count_for_the_candidate(): void
    {
        $this->candidate("{$this->tag} Hugo Homonimo", 'not_elected');
        $this->lider($this->mun, "{$this->tag} Hugo Homonimo", '1098700011', true, ['barrio' => "{$this->tag} Lagos"]);
        $this->lider($this->mun, "{$this->tag} Hugo Homonimo", '1098700012', false, ['barrio' => "{$this->tag} Lagos"]);

        $this->list(['municipio_id' => $this->mun, 'tipo' => 'alcaldia', 'barrio' => "{$this->tag} Lagos"])->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_solo_conteo_counts_a_barrio_without_consuming_quota(): void
    {
        config(['agent.contact_rows_daily_max' => 0]);

        $this->list(['barrio' => "{$this->tag} Poblado", 'solo_conteo' => 'true'])->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_q_finds_by_cedula_only_who_authorized_their_data(): void
    {
        $this->list(['q' => '1098700001'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.cedula', '1098700001');

        $response = $this->list(['q' => '1098700002'])->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        $this->assertStringNotContainsString('Bruno', $response->getContent());
        $this->list(['q' => '98700003'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->agent('/api/agent/v1/mapa/export', ['q' => '1098700002'])->assertOk()->assertHeader('X-Total-Count', '0');

        // His name is still found, without his data.
        $this->list(['q' => 'bruno sin', 'municipio_id' => $this->mun])->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.datos_autorizados', false);
    }

    public function test_q_is_masked_in_the_audit(): void
    {
        $this->list(['q' => '1098700001'])->assertOk();

        $log = DB::table('audit_logs')->where('user_id', $this->campo->id)->where('action', 'agent.mapa.list')->sole();
        $meta = json_decode($log->metadata, true);
        $this->assertSame('0001', $meta['filtros']['q_last4']);
        $this->assertStringNotContainsString('1098700001', $log->metadata);
    }

    public function test_unified_candidate_of_an_unauthorized_lider_shows_no_contact_data(): void
    {
        $response = $this->list(['municipio_id' => $this->mun, 'tipo' => 'alcaldia,lideres', 'per_page' => 50])->assertOk();

        $bruno = collect($response->json('data'))->firstWhere('nombre', "{$this->tag} Bruno Sin Autorizar");
        $this->assertSame('Alcaldía, Líderes', $bruno['tipo_registro']);
        $this->assertFalse($bruno['datos_autorizados']);
        foreach (['1098700002', '3158700002', '1098700002@correo.co', 'Nota 1098700002'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_attendees_are_invisible_without_the_events_permission(): void
    {
        $this->lider($this->mun, "{$this->tag} Oscar Asistente", '1098700006', false, ['tipo' => ApproveAttendees::TIPO]);

        $this->list(['municipio_id' => $this->mun, 'tipo' => 'asistentes_evento'])->assertForbidden();

        $sinEventos = $this->list(['municipio_id' => $this->mun, 'tipo' => 'asistentes_evento,lideres', 'per_page' => 50])->assertOk();
        $this->assertStringNotContainsString('Asistentes a eventos', $sinEventos->getContent());
        $this->assertSame('Líderes', collect($sinEventos->json('data'))->firstWhere('nombre', "{$this->tag} Oscar Asistente")['tipo_registro']);

        $conEventos = $this->user([$this->mun], ['view.mapa', 'view.eventos']);
        $this->list(['municipio_id' => $this->mun, 'tipo' => 'asistentes_evento'], $conEventos)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.secciones', ['Asistentes a eventos' => 1]);
    }

    public function test_another_campaign_does_not_leak(): void
    {
        $otra = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $otra, 'name' => "Otra {$this->tag}", 'slug' => "otra-{$this->tag}", 'whatsapp_enabled' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->lider($this->mun, "{$this->tag} Pablo Ajeno", '1098700007', true, ['campaign_id' => $otra]);
        $this->lider($this->mun, "{$this->tag} Elena Electa", '1098700008', true, ['campaign_id' => $otra]);

        $response = $this->list(['municipio_id' => $this->mun, 'per_page' => 50])->assertOk();

        $this->assertStringNotContainsString('Pablo Ajeno', $response->getContent());
        $this->assertStringNotContainsString('1098700008', $response->getContent());
        $this->assertFalse(collect($response->json('data'))->firstWhere('nombre', "{$this->tag} Elena Electa")['datos_autorizados']);
    }

    public function test_territory_on_postgresql(): void
    {
        $this->list(['municipio_id' => $this->otroMun])->assertForbidden();
        $this->assertStringNotContainsString('Carla Fuera', $this->list(['q' => $this->tag, 'per_page' => 50])->assertOk()->getContent());
    }

    public function test_export_on_postgresql_with_both_formats(): void
    {
        $xlsx = $this->agent('/api/agent/v1/mapa/export', ['municipio_id' => $this->mun])->assertOk()
            ->assertHeader('X-Total-Count', '5')
            ->assertHeader('X-Rows-With-Data', '2');
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));

        $pdf = $this->agent('/api/agent/v1/mapa/export', ['municipio_id' => $this->mun, 'formato' => 'pdf'])->assertOk()
            ->assertHeader('X-Rows-Delivered', '5')
            ->assertHeader('X-Rows-With-Data', '2');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }
}
