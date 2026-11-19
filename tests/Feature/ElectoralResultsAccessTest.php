<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use App\Support\CurrentCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// campaigns.ve_resultados_electorales: a grupo político without it (subgroup X, like Alejandro Cárdenas) never gets the
// shared electoral results (candidates, votes, parties) by any route, but keeps its own fichas, also in the Alcaldía and
// Asamblea sections by their cargo. CD (switch on) and the superadmin keep seeing everything.
// Each test in its own process: the suite already runs close to the memory limit of the shared process.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ElectoralResultsAccessTest extends TestCase
{
    use BuildsEventosScenario;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    private const TIPOS = 'alcaldia,lideres,asamblea';

    private string $pedro;

    private string $diego;

    private string $partido;

    private string $laura;

    private User $userX;

    private User $userCd;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        (require database_path('migrations/2026_10_19_183258_create_personal_access_tokens_table.php'))->up();
        DB::table('campaigns')->update(['whatsapp_enabled' => true]);

        $event = $this->row('electoral_events', ['name' => 'Territoriales 2023', 'election_date' => '2023-10-29']);
        $alcaldia = $this->row('offices', ['name' => 'Alcaldía']);
        $asamblea = $this->row('corporations', ['name' => 'Asamblea']);
        $this->partido = $this->row('political_organizations', ['canonical_name' => 'PARTIDO DE PRUEBA']);

        $this->pedro = $this->candidate('Pedro Candidato Electo', $event, ['office_id' => $alcaldia], 'elected', 5400);
        $this->diego = $this->candidate('Diego Diputado', $event, ['corporation_id' => $asamblea], 'elected', 870);

        // Fichas of subgroup X: one aspiring to the Alcaldía (shown in that section by its cargo), one plain leader.
        $this->laura = $this->lider($this->campX, 'Laura Aspirante', '1001', ['cargo' => 'Candidato Alcaldía 2027']);
        $this->lider($this->campX, 'Mario Lider X', '1002');
        $this->lider($this->campX, 'Dora Diputada X', '1003', ['cargo' => 'Candidato Asamblea', 'tipo' => 'nuevo_lider']);
        $this->lider(Campaign::DEFAULT_ID, 'Ana Lider CD', '2001');

        $permissions = ['view.mapa', 'view.votantes', 'view.gobernador', 'write.data', 'use.whatsapp'];
        $this->userX = $this->agentReady($this->eventUser($this->campX, $permissions));
        $this->userCd = $this->agentReady($this->eventUser(Campaign::DEFAULT_ID, $permissions));
    }

    private function row(string $table, array $data): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id, ...$data]);

        return $id;
    }

    private function candidate(string $nombre, string $event, array $contest, string $outcome, int $votos): string
    {
        $contestId = $this->row('contests', ['electoral_event_id' => $event, 'geographic_unit_id' => $this->giron, 'name' => $nombre, ...$contest]);
        $person = $this->row('persons', ['full_name' => $nombre, 'normalized_name' => Str::upper(Str::ascii($nombre))]);
        $candidacy = $this->row('candidacies', ['person_id' => $person, 'contest_id' => $contestId, 'outcome' => $outcome]);
        $this->row('candidacy_endorsements', ['candidacy_id' => $candidacy, 'organization_id' => $this->partido, 'is_primary' => true]);
        $this->row('electoral_results', ['candidacy_id' => $candidacy, 'contest_id' => $contestId, 'geographic_unit_id' => $this->giron,
            'metric_type' => 'votes', 'value' => $votos]);

        return $person;
    }

    private function agentReady(User $user): User
    {
        $user->forceFill(['whatsapp_phone' => '+5730'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function superadmin(string $campaignId): User
    {
        $role = Role::create(['code' => 'R01_SUPERADMIN', 'name' => 'Superadmin', 'level' => 10]);
        $user = User::create(['name' => 'Super', 'email' => 'super@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'permissions' => ['*']]);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    private function switchOn(string $campaignId): void
    {
        DB::table('campaigns')->where('id', $campaignId)->update(['ve_resultados_electorales' => true]);
    }

    // [nombre => tipo_registro] of the Mapa Político for Girón.
    private function mapa(User $user, string $tipos = self::TIPOS): array
    {
        $rows = [];
        $this->actingAs($user)->get('/mapa-politico?'.http_build_query(['municipio' => $this->giron, 'tipo' => $tipos]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$rows) {
                $rows = $page->toArray()['props']['data'];
            });

        return collect($rows)->mapWithKeys(fn ($r) => [$r['nombre'] => $r['tipo_registro']])->all();
    }

    private function agentGet(string $uri, User $user): TestResponse
    {
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $token = $user->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->call('GET', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\nGET\n{$uri}\n", self::SECRET),
        ]);
    }

    public function test_group_without_results_sees_only_its_fichas_on_the_map_also_in_the_electoral_sections(): void
    {
        $mapa = $this->mapa($this->userX);

        $this->assertArrayNotHasKey('Pedro Candidato Electo', $mapa);
        $this->assertArrayNotHasKey('Diego Diputado', $mapa);
        $this->assertArrayNotHasKey('Ana Lider CD', $mapa);
        $this->assertSame('Líderes, Alcaldía', $mapa['Laura Aspirante']);
        // Nuevos Líderes is not among the tipos asked: the ficha shows only in its office's section.
        $this->assertSame('Asamblea', $mapa['Dora Diputada X']);
        $this->assertSame('Líderes', $mapa['Mario Lider X']);

        // Only the Alcaldía section: the group's own ficha is there, never the elected mayor.
        $this->assertSame(['Laura Aspirante' => 'Alcaldía'], $this->mapa($this->userX, 'alcaldia'));

        $this->actingAs($this->userX)->get('/mapa-politico?'.http_build_query(['municipio' => $this->giron, 'tipo' => self::TIPOS]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('partyTotals', [])
                ->where('auth.user.seesElectoralResults', false)
                ->where('auth.user.campaign.ve_resultados_electorales', false));
    }

    public function test_group_without_results_gets_403_on_every_electoral_route(): void
    {
        $municipio = $this->giron;
        $pedro = $this->pedro;
        $pages = [
            '/gobernador', "/gobernador/{$municipio}", '/senado', "/senado/{$municipio}", '/camara', "/camara/{$municipio}",
            '/concejo', "/concejo/{$municipio}", '/asamblea', "/asamblea/{$municipio}", '/consultas', '/analisis',
            "/municipio/{$municipio}", "/persona/{$pedro}", "/partido/{$this->partido}",
            "/reporte/persona/{$pedro}", "/reporte/municipio/{$municipio}",
        ];
        foreach ($pages as $uri) {
            $this->actingAs($this->userX)->get($uri)->assertForbidden()->assertSee(CurrentCampaign::SIN_RESULTADOS, false);
        }

        $json = [
            ['getJson', "/api/comparar?a={$pedro}&b={$this->diego}", []],
            ['getJson', "/mapa-politico/persona/{$pedro}", []],
            ['getJson', "/mapa-politico/persona/{$pedro}/posibles-duplicados", []],
            ['putJson', "/mapa-politico/persona/{$pedro}", ['telefono' => '3009999999']],
            ['postJson', "/mapa-politico/persona/{$pedro}/nexos", ['nombre' => 'Primo', 'parentesco' => 'Primo']],
            ['getJson', "/mapa-politico/persona/{$pedro}/unificar-con/buscar?q=Pedro", []],
            ['postJson', "/mapa-politico/persona/{$this->laura}/unificar-con/vista-previa", ['con' => $pedro]],
            ['postJson', "/mapa-politico/persona/{$this->laura}/unificar-con", ['con' => $pedro]],
            ['getJson', '/exportar/excel?source=gobernador', []],
            ['getJson', '/exportar/pdf?source=asamblea', []],
            ['getJson', '/exportar/excel?source=senado', []],
        ];
        foreach ($json as [$method, $uri, $payload]) {
            $this->actingAs($this->userX)->{$method}($uri, $payload)
                ->assertForbidden()->assertJson(['message' => CurrentCampaign::SIN_RESULTADOS]);
        }
        // Nothing was written by the rejected writes.
        $this->assertSame(0, DB::table('nexos_familiares')->count());
        $this->assertSame(2, DB::table('persons')->count());
    }

    public function test_group_without_results_finds_no_candidates_in_searches_and_never_lands_on_gobernador(): void
    {
        $this->actingAs($this->userX)->getJson('/api/search?q=Pedro')->assertOk()->assertExactJson(['results' => []]);
        $this->actingAs($this->userX)->getJson('/mapa-politico/persona/'.$this->laura.'/unificar-con/buscar?q=Pedro')
            ->assertOk()->assertJsonMissing(['tipo' => 'candidato']);
        // /gobernador is the landing of a user with view.gobernador and no map: not for a group without the results.
        $this->actingAs($this->eventUser($this->campX, ['view.gobernador', 'view.eventos']))->get('/')->assertRedirect('/eventos');
        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['view.gobernador', 'view.eventos']))->get('/')->assertRedirect('/gobernador');
    }

    // The offline package reads lideres with ILIKE (PostgreSQL): its gating is checked by hand, see the delivery report.
    public function test_group_without_results_exports_only_its_fichas(): void
    {
        $excel = $this->actingAs($this->userX)->get('/exportar/excel?'.http_build_query(['source' => 'mapa-politico', 'municipio' => $this->giron, 'tipo' => self::TIPOS]));
        $excel->assertOk();
        $sheet = IOFactory::load($excel->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
        $names = array_column($sheet, 0);
        $this->assertContains('Laura Aspirante', $names);
        $this->assertNotContains('Pedro Candidato Electo', $names);
        $this->assertNotContains('Diego Diputado', $names);
    }

    public function test_whatsapp_agent_of_a_group_without_results_gets_no_candidates(): void
    {
        $mapa = $this->agentGet('/api/agent/v1/mapa?'.http_build_query(['municipio_id' => $this->giron, 'tipo' => 'alcaldia,lideres']), $this->userX)
            ->assertOk()->json();
        $this->assertStringNotContainsString('Pedro Candidato Electo', json_encode($mapa));
        $this->assertStringContainsString('Laura Aspirante', json_encode($mapa));

        $this->agentGet("/api/agent/v1/personas/{$this->pedro}/ficha", $this->userX)->assertForbidden()
            ->assertJson(['success' => false, 'message' => CurrentCampaign::SIN_RESULTADOS]);
        $this->agentGet("/api/agent/v1/reportes/persona/{$this->pedro}", $this->userX)->assertForbidden();
        $this->agentGet("/api/agent/v1/reportes/municipio/{$this->giron}", $this->userX)->assertForbidden();
        $this->agentGet('/api/agent/v1/me', $this->userX)->assertOk()->assertJsonPath('campaign.ve_resultados_electorales', false);

        // CD's agent keeps the candidates.
        $cd = $this->agentGet('/api/agent/v1/mapa?'.http_build_query(['municipio_id' => $this->giron, 'tipo' => 'alcaldia,lideres']), $this->userCd)
            ->assertOk()->json();
        $this->assertStringContainsString('Pedro Candidato Electo', json_encode($cd));
    }

    public function test_switch_on_gives_the_group_the_results(): void
    {
        $this->switchOn($this->campX);

        $mapa = $this->mapa($this->userX);
        $this->assertSame('Alcaldía', $mapa['Pedro Candidato Electo']);
        $this->assertSame('Asamblea', $mapa['Diego Diputado']);
        // With the results the own fichas go back to their usual sections only.
        $this->assertSame('Líderes', $mapa['Laura Aspirante']);
        $this->actingAs($this->userX)->get('/mapa-politico?municipio='.$this->giron.'&tipo='.self::TIPOS)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.seesElectoralResults', true)
                ->where('partyTotals.Asamblea.0.votos', 870));
    }

    public function test_cd_keeps_seeing_the_results_as_before(): void
    {
        $mapa = $this->mapa($this->userCd);
        $this->assertSame('Alcaldía', $mapa['Pedro Candidato Electo']);
        $this->assertSame('Asamblea', $mapa['Diego Diputado']);
        $this->assertSame('Líderes', $mapa['Laura Aspirante']);
        $this->assertSame('Líderes', $mapa['Ana Lider CD']);
        $this->actingAs($this->userCd)->get('/mapa-politico?municipio='.$this->giron.'&tipo='.self::TIPOS)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.seesElectoralResults', true));
    }

    public function test_superadmin_sees_the_results_even_in_a_group_without_them(): void
    {
        $super = $this->superadmin($this->campY);

        $mapa = $this->mapa($super);
        $this->assertSame('Alcaldía', $mapa['Pedro Candidato Electo']);
        $this->actingAs($super)->get('/mapa-politico?municipio='.$this->giron.'&tipo='.self::TIPOS)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.seesElectoralResults', true));
    }
}
