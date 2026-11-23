<?php

namespace Tests\Feature;

use App\Http\Controllers\MapaPoliticoController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\VotanteController;
use App\Models\Campaign;
use App\Models\Lider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

// Opt-in: the Mapa Político with ~130.000 fichas (Electo): capped sections, "Ver más" pages, voters counted but loaded
// on demand, and the exports, offline copy and referidos that no longer read a whole city.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=MapaPoliticoPagingPostgresTest
class MapaPoliticoPagingPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const MUNICIPIO = 'Municipio Paginado PG';

    private string $mun;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => self::MUNICIPIO]);

        $this->user = User::create([
            'name' => 'Paginado PG', 'email' => 'pag-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'territory_scope' => [$this->mun], 'permissions' => ['view.votantes', 'view.mapa', 'write.data'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    // $n fichas named "{prefix} 000".."{prefix} n-1" of the given tipo in the test municipio.
    private function fichas(int $n, string $tipo = 'directorio', string $prefix = 'Zzpag Lider', array $extra = []): array
    {
        $ids = [];
        foreach (array_chunk(range(0, $n - 1), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $ids[] = $id = (string) Str::uuid();
                $rows[] = [
                    'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun,
                    'nombre' => sprintf('%s %04d', $prefix, $i), 'municipio' => self::MUNICIPIO, 'tipo' => $tipo,
                    'cargo' => $tipo === 'directorio' ? 'Coordinador' : null, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(), ...$extra,
                ];
            }
            DB::table('lideres')->insert($rows);
        }

        return $ids;
    }

    private function page(string $query = ''): array
    {
        return $this->actingAs($this->user)->get("/mapa-politico?municipio={$this->mun}{$query}")
            ->assertOk()->viewData('page')['props'];
    }

    private function seccion(string $seccion, int $offset, string $query = '')
    {
        return $this->actingAs($this->user)
            ->getJson("/mapa-politico/seccion?municipio={$this->mun}&seccion={$seccion}&offset={$offset}{$query}");
    }

    public function test_a_big_section_is_cut_counted_and_paged_and_voters_are_only_counted(): void
    {
        $this->fichas(205);
        $this->fichas(3, Lider::TIPO_VOTANTE, 'Zzpag Votante');

        $props = $this->page();
        $lideres = collect($props['data'])->filter(fn ($r) => str_contains($r['tipo_registro'], 'Líderes'));

        $this->assertCount(MapaPoliticoController::SECTION_LIMIT, $lideres);
        $this->assertSame(['key' => 'lideres', 'loaded' => 200, 'total' => 205], $props['sectionPaging']['Líderes']);
        $this->assertSame(['key' => 'votantes', 'loaded' => 0, 'total' => 3], $props['sectionPaging']['Votantes']);
        $this->assertEmpty(collect($props['data'])->filter(fn ($r) => str_starts_with($r['nombre'], 'Zzpag Votante')));

        $next = $this->seccion('lideres', 200)->assertOk()->assertJson(['success' => true, 'has_more' => false]);
        $this->assertSame(['Zzpag Lider 0200', 'Zzpag Lider 0201', 'Zzpag Lider 0202', 'Zzpag Lider 0203', 'Zzpag Lider 0204'],
            array_column($next->json('data'), 'nombre'));

        $votantes = $this->seccion('votantes', 0)->assertOk()->json('data');
        $this->assertSame(['Votantes'], array_values(array_unique(array_column($votantes, 'tipo_registro'))));
        $this->assertCount(3, $votantes);
    }

    public function test_choosing_the_votantes_tipo_or_searching_loads_them(): void
    {
        $this->fichas(3, Lider::TIPO_VOTANTE, 'Zzpag Votante');

        $props = $this->page('&tipo=votantes');
        $this->assertCount(3, $props['data']);
        $this->assertSame([], $props['sectionPaging']);

        $found = collect($this->page('&search=Zzpag+Votante+0001')['data'])->pluck('nombre')->all();
        $this->assertSame(['Zzpag Votante 0001'], $found);
    }

    public function test_a_small_municipio_is_listed_as_before_without_paging(): void
    {
        $this->fichas(2);

        $props = $this->page();

        $this->assertSame([], $props['sectionPaging']);
        $this->assertSame(['Zzpag Lider 0000', 'Zzpag Lider 0001'], collect($props['data'])->pluck('nombre')->sort()->values()->all());
    }

    public function test_the_partido_filter_is_applied_before_the_cap(): void
    {
        $this->fichas(205);
        $this->fichas(2, 'directorio', 'Zzpag Verde', ['partido' => 'Partido Verde Pag']);

        $props = $this->page('&partido='.rawurlencode('Partido Verde Pag'));

        $this->assertSame(['Zzpag Verde 0000', 'Zzpag Verde 0001'], collect($props['data'])->pluck('nombre')->sort()->values()->all());
        $this->assertSame([], $props['sectionPaging']);
    }

    public function test_seccion_rejects_an_unknown_section_and_a_section_the_user_may_not_see(): void
    {
        $this->seccion('todos', 0)->assertStatus(422);
        $this->seccion('lideres', -1)->assertStatus(422);
        // Without view.eventos the attendees section does not exist for this user.
        $this->seccion(MapaPoliticoController::ASISTENTES_EVENTO, 0, '&tipo='.MapaPoliticoController::ASISTENTES_EVENTO)
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_a_ficha_merged_into_its_candidate_row_is_not_repeated_by_the_next_page(): void
    {
        $this->fichas(200);
        $this->fichas(1, 'directorio', 'Zzpag Zz Candidata');
        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => 'Alcaldía paginada', 'electoral_event_id' => DB::table('contests')->whereNotNull('electoral_event_id')->value('electoral_event_id'),
            'office_id' => DB::table('offices')->where('name', 'Alcaldía')->value('id'), 'geographic_unit_id' => $this->mun,
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $personId, 'first_name' => 'Zzpag', 'last_name' => 'Candidata',
            'full_name' => 'Zzpag Zz Candidata 0000', 'normalized_name' => 'zzpag zz candidata 0000']);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $personId, 'contest_id' => $contestId, 'outcome' => 'elected']);

        $candidata = collect($this->page()['data'])->firstWhere('nombre', 'Zzpag Zz Candidata 0000');
        $this->assertSame('Alcaldía, Líderes', $candidata['tipo_registro']);

        $next = $this->seccion('lideres', 200)->assertOk();
        $this->assertSame([], $next->json('data'));
        // The next page starts after the row read and left out, not at the same offset again.
        $this->assertSame(201, $next->json('next_offset'));
        $this->assertFalse($next->json('has_more'));
        // Without Alcaldía among the tipos the page has no candidate row: the ficha is listed.
        $this->assertSame(['Zzpag Zz Candidata 0000'], array_column($this->seccion('lideres', 200, '&tipo=lideres')->json('data'), 'nombre'));
    }

    public function test_filters_list_santander_and_only_the_municipios_of_other_departamentos_with_fichas(): void
    {
        $depto = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $depto, 'type' => 'department', 'canonical_name' => 'Zzdepto Nacional PG']);
        $sinFichas = (string) Str::uuid();
        $conFichas = (string) Str::uuid();
        DB::table('geographic_units')->insert([
            ['id' => $sinFichas, 'type' => 'municipality', 'canonical_name' => 'AAA Zz Sin Fichas PG', 'parent_id' => $depto],
            ['id' => $conFichas, 'type' => 'municipality', 'canonical_name' => 'AAA Zz Con Fichas PG', 'parent_id' => $depto],
        ]);
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $conFichas,
            'nombre' => 'Zzpag Fuera', 'municipio' => 'AAA Zz Con Fichas PG', 'tipo' => 'directorio', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->user->forceFill(['territory_scope' => null])->save();

        $props = $this->actingAs($this->user)->get('/mapa-politico?provincia='.rawurlencode('Fuera de Santander'))->assertOk()->viewData('page')['props'];
        $names = array_column($props['municipios'], 'name');
        $this->assertContains('AAA Zz Con Fichas PG', $names);
        $this->assertNotContains('AAA Zz Sin Fichas PG', $names);
        $this->assertSame('Fuera de Santander', end($props['provincias']));
        $this->assertNotContains('Zzdepto Nacional PG', $props['provincias']);
        $this->assertContains('Zzpag Fuera', array_column($props['data'], 'nombre'));

        $todos = collect($this->actingAs($this->user)->getJson('/api/geo/municipios-todos')->assertOk()->json('data'));
        $this->assertFalse($todos->firstWhere('name', 'AAA Zz Sin Fichas PG')['santander']);
        $this->assertTrue($todos->first()['santander']);

        $this->actingAs($this->user)->get('/lideres')->assertRedirect();
        $this->assertNotSame("/lideres/{$sinFichas}", parse_url($this->actingAs($this->user)->get('/lideres')->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_a_ficha_moves_to_the_municipio_id_and_a_bare_name_prefers_santander(): void
    {
        $this->user->forceFill(['territory_scope' => null])->save();
        $depto = (string) Str::uuid();
        $homonimo = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $depto, 'type' => 'department', 'canonical_name' => 'Zzdepto Homonimo PG']);
        DB::table('geographic_units')->insert(['id' => $homonimo, 'type' => 'municipality', 'canonical_name' => self::MUNICIPIO, 'parent_id' => $depto]);
        $lider = $this->fichas(1)[0];

        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$lider}", ['municipio_id' => $homonimo])->assertOk();
        $this->assertSame($homonimo, DB::table('lideres')->where('id', $lider)->value('geographic_unit_id'));

        // Only the name (offline queue, older clients): Santander's municipio of that name, not the other departamento's.
        DB::table('lideres')->where('id', $lider)->update(['municipio' => 'Otro texto']);
        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$lider}", ['municipio' => self::MUNICIPIO])->assertOk();
        $this->assertSame($this->mun, DB::table('lideres')->where('id', $lider)->value('geographic_unit_id'));

        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$lider}", ['municipio_id' => (string) Str::uuid()])->assertStatus(422);
        $this->assertSame($this->mun, MapaPoliticoController::municipioIndex()[mb_strtolower(Str::ascii(self::MUNICIPIO))]);
    }

    public function test_a_municipio_that_is_not_an_id_is_no_filter(): void
    {
        $this->actingAs($this->user)->get('/mapa-politico?municipio=no-es-un-uuid')->assertOk();
        $this->actingAs($this->user)->getJson('/mapa-politico/seccion?municipio=123&seccion=lideres&offset=0')->assertOk();
    }

    public function test_nuevo_votante_creates_a_voter(): void
    {
        $id = $this->actingAs($this->user)->postJson('/votantes', ['nombre' => 'Zzpag Nuevo Votante', 'municipio_id' => $this->mun])
            ->assertCreated()->json('id');

        $this->assertSame(Lider::TIPO_VOTANTE, DB::table('lideres')->where('id', $id)->value('tipo'));
    }

    public function test_votantes_pages_never_repeat_a_namesake(): void
    {
        $this->fichas(25, Lider::TIPO_VOTANTE, 'Zzpag Mismo');
        DB::table('lideres')->where('nombre', 'like', 'Zzpag Mismo%')->update(['nombre' => 'Zzpag Mismo Nombre']);
        $municipio = rawurlencode(self::MUNICIPIO);

        $ids = [];
        foreach ([1, 2] as $page) {
            $props = $this->actingAs($this->user)->get("/votantes?municipio={$municipio}&page={$page}")->assertOk()->viewData('page')['props'];
            $ids = [...$ids, ...array_column($props['votantes']['data'], 'id')];
        }

        $this->assertCount(25, $ids);
        $this->assertCount(25, array_unique($ids));
    }

    public function test_the_excel_and_the_pdf_of_the_mapa_give_the_same_total(): void
    {
        // Fichas of the Directorio are in two sections and merge into one row while reading: how many merge depends on
        // how many rows are read, so the PDF reading fewer than the Excel gave another total (QA BUG-05).
        $this->fichas(MapaPoliticoController::EXPORT_MAX_ROWS - 550);
        $this->fichas(600, 'directorio', 'Zzpag Dir', ['cargo' => 'Directorio Municipal']);
        $query = "source=mapa-politico&municipio={$this->mun}&tipo=lideres,directorio&columns=nombre";

        $pdfTotal = null;
        View::composer('exports.table', function ($view) use (&$pdfTotal) {
            $pdfTotal = $view->getData()['totalCount'] ?? null;
        });
        $this->actingAs($this->user)->get("/exportar/pdf?{$query}")->assertOk();

        $xlsx = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($xlsx, $this->actingAs($this->user)->get("/exportar/excel?{$query}")->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($xlsx);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($xlsx);

        $this->assertSame(MapaPoliticoController::EXPORT_MAX_ROWS + 50, $pdfTotal);
        $this->assertStringContainsString('de 10.050 registros', $sheet);
    }

    public function test_electo_filters_the_notice_and_completing_a_field_clears_its_mark(): void
    {
        [$pendiente, $diferente, $menor] = $this->fichas(3, Lider::TIPO_VOTANTE, 'Zzpag Electo');
        DB::table('lideres')->where('id', $pendiente)->update(['migracion_flags' => json_encode(['por_completar' => ['barrio', 'convenio'], 'electo_codigos' => ['barrio' => '77']])]);
        DB::table('lideres')->where('id', $diferente)->update(['telefono' => '3001112222', 'migracion_flags' => json_encode(['contradicciones' => ['telefono' => '3109998888']])]);
        DB::table('lideres')->where('id', $menor)->update(['is_active' => false, 'migracion_flags' => json_encode(['menor' => true])]);
        $municipio = rawurlencode(self::MUNICIPIO);
        $listed = fn (string $q) => array_column($this->actingAs($this->user)->get("/votantes?municipio={$municipio}{$q}")
            ->assertOk()->viewData('page')['props']['votantes']['data'], 'id');

        $this->assertSame([$pendiente], $listed('&por_completar=barrio'));
        $this->assertSame([$pendiente], $listed('&por_completar=cualquiera'));
        $this->assertSame([$diferente], $listed('&diferencias=1'));
        $this->assertSame([$menor], $listed('&importados_inactivos=menor'));
        $this->assertSame([$menor], $listed('&importados_inactivos=todos'));

        $ficha = $this->actingAs($this->user)->getJson("/votantes/{$pendiente}")->assertOk()->json('data.migracion');
        $this->assertSame(['barrio', 'convenio'], array_column($ficha['por_completar'], 'campo'));
        $this->assertSame('77', $ficha['por_completar'][0]['codigo_electo']);
        $diff = $this->actingAs($this->user)->getJson("/mapa-politico/persona/{$diferente}")->assertOk()->json('migracion.diferencias');
        $this->assertSame([['campo' => 'telefono', 'label' => 'Teléfono', 'electo' => '3109998888', 'actual' => '3001112222', 'usar_electo' => true]], $diff);

        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$pendiente}", ['barrio' => 'Centro'])->assertOk();
        $flags = json_decode(DB::table('lideres')->where('id', $pendiente)->value('migracion_flags'), true);
        $this->assertSame(['convenio'], $flags['por_completar']);
        $this->assertSame(['barrio' => '77'], $flags['electo_codigos']);
        $this->assertSame([], $listed('&por_completar=barrio'));
    }

    public function test_seccion_needs_a_session(): void
    {
        $this->getJson('/mapa-politico/seccion?seccion=lideres&offset=0')->assertUnauthorized();
    }

    public function test_a_voter_saved_from_the_panel_stays_a_voter(): void
    {
        $this->assertSame(Lider::TIPO_VOTANTE, MapaPoliticoController::liderTipoFromLabels(['Votantes']));
        $this->assertSame('directorio', MapaPoliticoController::liderTipoFromLabels(['Votantes', 'Líderes']));
        $this->assertSame('Votantes', MapaPoliticoController::liderTipoLabel(Lider::TIPO_VOTANTE));
    }

    public function test_a_big_excel_export_is_streamed_with_a_cut_notice_and_a_small_one_is_not(): void
    {
        config(['exports.stream_after_rows' => 50]);
        $this->fichas(60);

        $response = $this->actingAs($this->user)
            ->get("/exportar/excel?source=mapa-politico&municipio={$this->mun}&tipo=lideres&columns=nombre,telefono");
        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $response->streamedContent());

        $votantes = $this->actingAs($this->user)->get('/exportar/excel?source=votantes&municipio='.rawurlencode(self::MUNICIPIO));
        $votantes->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $votantes->baseResponse);
        $this->assertStringStartsWith('PK', $votantes->streamedContent());
    }

    public function test_the_pdf_of_a_big_section_reads_only_up_to_its_cap(): void
    {
        $this->fichas(510);

        $this->actingAs($this->user)
            ->get("/exportar/pdf?source=mapa-politico&municipio={$this->mun}&tipo=lideres")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_offline_copy_leaves_voters_out(): void
    {
        $this->fichas(3);
        $this->fichas(5, Lider::TIPO_VOTANTE, 'Zzpag Votante');

        $json = $this->actingAs($this->user)->getJson("/api/offline/download/{$this->mun}")->assertOk()->json();

        $this->assertSame(['Zzpag Lider 0000', 'Zzpag Lider 0001', 'Zzpag Lider 0002'], array_column($json['lideres'], 'nombre'));
        $this->assertSame(3, $json['lideresTotal']);
        $this->assertFalse($json['lideresTruncated']);
        $this->assertSame(2000, OfflineController::MAX_LIDERES);
    }

    public function test_referidos_are_capped_with_the_real_total(): void
    {
        $referente = $this->fichas(1, 'directorio', 'Zzpag Referente', ['cedula' => '99887766'])[0];
        $this->fichas(VotanteController::REFERIDOS_LIMIT + 5, Lider::TIPO_VOTANTE, 'Zzpag Referido', ['referente_documento' => '99887766']);

        $first = $this->actingAs($this->user)->getJson("/votantes/{$referente}/referidos")->assertOk();
        $this->assertCount(VotanteController::REFERIDOS_LIMIT, $first->json('data'));
        $this->assertSame(VotanteController::REFERIDOS_LIMIT + 5, $first->json('total'));

        $rest = $this->actingAs($this->user)->getJson("/votantes/{$referente}/referidos?offset=".VotanteController::REFERIDOS_LIMIT)->assertOk();
        $this->assertCount(5, $rest->json('data'));

        $show = $this->actingAs($this->user)->getJson("/votantes/{$referente}")->assertOk();
        $this->assertCount(VotanteController::REFERIDOS_LIMIT, $show->json('data.referidos'));
        $this->assertSame(VotanteController::REFERIDOS_LIMIT + 5, $show->json('data.referidos_count'));
    }
}
