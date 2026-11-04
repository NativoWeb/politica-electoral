<?php

namespace Tests\Feature;

use App\Console\Commands\Gobernacion2023Totales;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LegislativoController;
use App\Services\GobernacionResultados;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// data:gobernacion-2023-totales: the Gobernación Santander 2023 contest gets the official department totals, the
// turnout row, the full candidate names and the avales of the source; nothing outside that contest changes. /gobernador
// shows those totals and a municipio shows no votes.
class Gobernacion2023TotalesTest extends TestCase
{
    use BuildsElectoralSchema;

    private array $ids = [];

    private const SEED = [
        // [nombre corto, votos falsos, aval actual]
        ['Juvenal Diaz', 5948, 'Liga'],
        ['Hector Mantilla', 3144, 'Conservador'],
        ['Ferley Parra', 4423, 'Dignidad Y Compromiso'],
        ['Rodolfo Hernandez', 4976, 'Liga'],
        ['Julian Silva', 496, 'Nuevo Liberalismo'],
        ['Luis Rangel', 150, 'Pacto Historico'],
        ['Jose Domingo Cortes', 123, 'Nueva Fuerza Democratica'],
        ['Luis Quiñones', 133, 'Independiente'],
        ['Juan Torres', 65, 'Ecologista Colombiano'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElectoralSchema();
        Schema::table('electoral_results', function (Blueprint $t) {
            $t->decimal('percentage', 7, 4)->nullable();
            $t->smallInteger('ranking')->nullable();
        });
        Schema::table('political_organizations', fn (Blueprint $t) => $t->string('color_hex')->nullable());
        Schema::create('turnout_metrics', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('contest_id')->nullable();
            $t->uuid('electoral_event_id')->nullable();
            $t->uuid('geographic_unit_id');
            $t->string('grain');
            foreach (['electoral_potential', 'total_voters', 'valid_votes', 'blank_votes', 'null_votes', 'unmarked_votes'] as $c) {
                $t->integer($c)->nullable();
            }
            $t->decimal('participation_pct', 7, 4)->nullable();
            $t->string('raw_potential')->nullable();
            $t->uuid('source_id')->nullable();
            $t->timestamps();
        });
        Schema::create('source_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('original_name');
            $t->string('storage_path');
            $t->string('mime_type')->nullable();
            $t->string('file_type');
            $t->timestamps();
        });

        $santander = $this->row('geographic_units', ['type' => 'department', 'canonical_name' => 'Santander']);
        $this->ids['santander'] = $santander;
        $this->ids['metropolitana'] = $this->row('geographic_units', ['type' => 'province', 'canonical_name' => 'Metropolitana', 'parent_id' => $santander]);
        $this->ids['bucaramanga'] = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'Bucaramanga', 'parent_id' => $this->ids['metropolitana']]);
        $gobernacion = $this->row('offices', ['name' => 'Gobernación']);
        $alcaldia = $this->row('offices', ['name' => 'Alcaldía']);
        $event = $this->row('electoral_events', ['name' => 'Elecciones territoriales 2023', 'election_date' => '2023-10-29']);
        $this->ids['contest'] = $this->row('contests', ['electoral_event_id' => $event, 'office_id' => $gobernacion, 'geographic_unit_id' => $santander, 'name' => 'Gobernación Santander 2023']);

        $orgs = [];
        foreach (['Liga', 'Conservador', 'Dignidad Y Compromiso', 'Nuevo Liberalismo', 'Pacto Historico', 'Nueva Fuerza Democratica', 'Independiente', 'Ecologista Colombiano', 'Colombia Humana'] as $name) {
            $orgs[$name] = $this->row('political_organizations', ['type' => 'party', 'canonical_name' => $name, 'status' => 'active']);
        }
        $this->row('organization_aliases', ['organization_id' => $orgs['Liga'], 'raw_alias' => 'LIGA', 'normalized_alias' => 'LIGA']);
        $this->ids['orgs'] = $orgs;

        foreach (self::SEED as [$name, $votes, $org]) {
            $normalized = strtr(mb_strtoupper($name), ['Ñ' => 'N']);
            $person = $this->row('persons', ['first_name' => explode(' ', $name)[0], 'last_name' => 'x', 'full_name' => $name, 'normalized_name' => $normalized]);
            $this->row('person_aliases', ['person_id' => $person, 'alias_name' => mb_strtoupper($name), 'normalized_alias' => $normalized, 'alias_type' => 'electoral']);
            $cand = $this->row('candidacies', ['person_id' => $person, 'contest_id' => $this->ids['contest'], 'outcome' => $name === 'Juvenal Diaz' ? 'elected' : 'not_elected']);
            $this->row('candidacy_endorsements', ['candidacy_id' => $cand, 'organization_id' => $orgs[$org], 'endorsement_type' => 'endorsement', 'is_primary' => true]);
            $this->row('electoral_results', ['contest_id' => $this->ids['contest'], 'candidacy_id' => $cand, 'geographic_unit_id' => $santander, 'grain' => 'department', 'metric_type' => 'votes', 'value' => $votes, 'status' => 'validated']);
            $this->ids['persons'][$name] = $person;
            $this->ids['cands'][$name] = $cand;
        }
        $this->ids['potencial'] = $this->row('turnout_metrics', ['contest_id' => $this->ids['contest'], 'electoral_event_id' => $event, 'geographic_unit_id' => $this->ids['bucaramanga'], 'grain' => 'municipality', 'electoral_potential' => 450000]);

        // Out of scope: an Alcaldía contest with a Liga candidate, and "Luis Ferley Sierra" in another contest.
        $alcContest = $this->row('contests', ['electoral_event_id' => $event, 'office_id' => $alcaldia, 'geographic_unit_id' => $this->ids['bucaramanga'], 'name' => 'Alcaldía Bucaramanga 2023']);
        $alcPerson = $this->row('persons', ['first_name' => 'Otro', 'last_name' => 'Alcalde', 'full_name' => 'Otro Alcalde', 'normalized_name' => 'OTRO ALCALDE']);
        $alcCand = $this->row('candidacies', ['person_id' => $alcPerson, 'contest_id' => $alcContest, 'outcome' => 'elected']);
        $this->row('candidacy_endorsements', ['candidacy_id' => $alcCand, 'organization_id' => $orgs['Liga'], 'endorsement_type' => 'aval', 'is_primary' => true]);
        $this->row('electoral_results', ['contest_id' => $alcContest, 'candidacy_id' => $alcCand, 'geographic_unit_id' => $this->ids['bucaramanga'], 'grain' => 'municipality', 'metric_type' => 'votes', 'value' => 9000]);
        $this->ids['sierra'] = $this->row('persons', ['first_name' => 'Luis Ferley', 'last_name' => 'Sierra', 'full_name' => 'Luis Ferley Sierra', 'normalized_name' => 'LUIS FERLEY SIERRA']);
        $this->row('candidacies', ['person_id' => $this->ids['sierra'], 'contest_id' => $alcContest, 'outcome' => 'pending']);
    }

    private function votes(string $short): ?int
    {
        return DB::table('electoral_results')->where('candidacy_id', $this->ids['cands'][$short])->value('value');
    }

    private function orgOf(string $short): string
    {
        return DB::table('candidacy_endorsements as ce')->join('political_organizations as po', 'po.id', '=', 'ce.organization_id')
            ->where('ce.candidacy_id', $this->ids['cands'][$short])->value('po.canonical_name');
    }

    private function snapshot(): array
    {
        $tables = ['persons', 'person_aliases', 'political_organizations', 'organization_aliases', 'candidacy_endorsements', 'electoral_results', 'turnout_metrics', 'source_files', 'candidacies', 'contests'];

        return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    public function test_loads_the_official_totals_names_and_avales(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();

        $this->assertSame(423130, $this->votes('Juvenal Diaz'));
        $this->assertSame(181399, $this->votes('Ferley Parra'));
        $this->assertSame(3502, $this->votes('Juan Torres'));
        $juvenal = DB::table('electoral_results')->where('candidacy_id', $this->ids['cands']['Juvenal Diaz'])->first();
        $this->assertSame('department', $juvenal->grain);
        $this->assertSame($this->ids['santander'], $juvenal->geographic_unit_id);
        $this->assertSame('423.130', $juvenal->raw_value);
        $this->assertEquals(38.97, (float) $juvenal->percentage);
        $this->assertSame(1, (int) $juvenal->ranking);
        $source = DB::table('source_files')->where('storage_path', Gobernacion2023Totales::SOURCE_URL)->first();
        $this->assertNotNull($source);
        $this->assertSame($source->id, $juvenal->source_id);
        $this->assertSame(9, DB::table('electoral_results')->where('contest_id', $this->ids['contest'])->count());

        $ferley = DB::table('persons')->where('id', $this->ids['persons']['Ferley Parra'])->first();
        $this->assertSame('Luis Ferley Sierra Jaimes', $ferley->full_name);
        $this->assertSame('Luis Ferley', $ferley->first_name);
        $this->assertSame('Sierra Jaimes', $ferley->last_name);
        $this->assertSame('LUIS FERLEY SIERRA JAIMES', $ferley->normalized_name);
        $this->assertTrue(DB::table('person_aliases')->where('person_id', $ferley->id)->where('normalized_alias', 'FERLEY PARRA')->exists());
        $this->assertSame('LUIS MAURICIO QUINONES AMAYA', DB::table('persons')->where('id', $this->ids['persons']['Luis Quiñones'])->value('normalized_name'));

        $this->assertSame('General Juvenal Gobernador', $this->orgOf('Juvenal Diaz'));
        $this->assertSame('Coalicion Por El Desarrollo De Santander', $this->orgOf('Hector Mantilla'));
        $this->assertSame('Dignidad Santandereana', $this->orgOf('Ferley Parra'));
        $this->assertSame('Liga', $this->orgOf('Rodolfo Hernandez'));
        $this->assertSame('Colombia Humana', $this->orgOf('Luis Rangel'));
        $this->assertSame('Independiente', $this->orgOf('Luis Quiñones'));
        $this->assertSame(1, DB::table('political_organizations')->where('canonical_name', 'Liga')->count());
        $this->assertSame(1, DB::table('political_organizations')->where('canonical_name', 'Colombia Humana')->count());
        $this->assertSame(9 + 3, DB::table('political_organizations')->count());
        $this->assertTrue(DB::table('organization_aliases')->where('organization_id', $this->ids['orgs']['Liga'])->where('normalized_alias', 'PARTIDO LIGA GOBERNANTES ANTICORRUPCION - LIGA')->exists());

        $turnout = DB::table('turnout_metrics')->where('contest_id', $this->ids['contest'])->where('grain', 'department')->first();
        $this->assertSame($this->ids['santander'], $turnout->geographic_unit_id);
        $this->assertSame(1828912, (int) $turnout->electoral_potential);
        $this->assertSame(1168968, (int) $turnout->total_voters);
        $this->assertSame(1085625, (int) $turnout->valid_votes);
        $this->assertSame(76612, (int) $turnout->blank_votes);
        $this->assertSame(19718, (int) $turnout->null_votes);
        $this->assertSame(63625, (int) $turnout->unmarked_votes);
        $this->assertEquals(63.91, (float) $turnout->participation_pct);
        $this->assertSame(450000, (int) DB::table('turnout_metrics')->where('id', $this->ids['potencial'])->value('electoral_potential'));

        $this->assertSame(9, DB::table('audit_logs')->where('action', 'DATA_GOBERNACION_2023_CANDIDATURA')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'DATA_GOBERNACION_2023_ORGANIZACION_CREADA')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'DATA_GOBERNACION_2023_TOTALES')->count());
        $this->assertStringNotContainsString('FERLEY', mb_strtoupper(DB::table('audit_logs')->pluck('metadata')->implode(' ')));
    }

    public function test_official_figures_are_consistent_and_truncate_to_the_source_percentages(): void
    {
        $t = Gobernacion2023Totales::TURNOUT;
        $sum = array_sum(array_column(Gobernacion2023Totales::CANDIDATES, 4));
        $this->assertSame($t['valid_votes'], $sum + $t['blank_votes']);
        $this->assertSame($t['total_voters'], $t['valid_votes'] + $t['null_votes'] + $t['unmarked_votes']);
        $trunc = fn (int $v, int $base) => number_format(intdiv($v * 10000, $base) / 100, 2, '.', '');
        foreach (Gobernacion2023Totales::CANDIDATES as $c) {
            $this->assertSame($c[5], $trunc($c[4], $t['valid_votes']), $c[1]);
        }
        $this->assertSame('7.05', $trunc($t['blank_votes'], $t['valid_votes']));
        $this->assertSame($t['participation_pct'], $trunc($t['total_voters'], $t['electoral_potential']));
    }

    public function test_leaves_other_contests_and_people_alone(): void
    {
        $before = DB::table('electoral_results')->where('contest_id', '!=', $this->ids['contest'])->orderBy('id')->get();
        $endorsementsBefore = DB::table('candidacy_endorsements')->whereNotIn('candidacy_id', array_values($this->ids['cands']))->orderBy('id')->get();

        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();

        $this->assertEquals($before, DB::table('electoral_results')->where('contest_id', '!=', $this->ids['contest'])->orderBy('id')->get());
        $this->assertEquals($endorsementsBefore, DB::table('candidacy_endorsements')->whereNotIn('candidacy_id', array_values($this->ids['cands']))->orderBy('id')->get());
        $this->assertSame('Luis Ferley Sierra', DB::table('persons')->where('id', $this->ids['sierra'])->value('full_name'));
    }

    public function test_is_idempotent(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();
        $after = $this->snapshot();
        $audits = DB::table('audit_logs')->count();

        $this->artisan('data:gobernacion-2023-totales')->expectsOutput('Nada que corregir.')->assertSuccessful();

        $this->assertEquals($after, $this->snapshot());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan('data:gobernacion-2023-totales --dry-run')->expectsOutput('DRY-RUN: no se guardó nada.')->assertSuccessful();

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_refuses_when_a_candidate_has_municipal_results(): void
    {
        $this->row('electoral_results', ['contest_id' => $this->ids['contest'], 'candidacy_id' => $this->ids['cands']['Juan Torres'], 'geographic_unit_id' => $this->ids['bucaramanga'], 'grain' => 'municipality', 'metric_type' => 'votes', 'value' => 10]);
        $before = $this->snapshot();

        $this->artisan('data:gobernacion-2023-totales')->assertFailed();

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_refuses_when_the_contest_has_a_candidate_outside_the_source(): void
    {
        $extra = $this->row('persons', ['first_name' => 'Otro', 'last_name' => 'Candidato', 'full_name' => 'Otro Candidato', 'normalized_name' => 'OTRO CANDIDATO']);
        $this->row('candidacies', ['person_id' => $extra, 'contest_id' => $this->ids['contest'], 'outcome' => 'not_elected']);
        $before = $this->snapshot();

        $this->artisan('data:gobernacion-2023-totales')->assertFailed();

        $this->assertEquals($before, $this->snapshot());
    }

    private function gobernadorProps(?string $munId): array
    {
        $request = Request::create('/gobernador', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
        $this->app->instance('request', $request);

        return app(LegislativoController::class)->gobernadorMunicipio($munId)->toResponse($request)->getData(true)['props'];
    }

    public function test_gobernador_shows_department_totals_and_turnout(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();

        $props = $this->gobernadorProps(null);

        $this->assertSame('Juvenal Diaz Mateus', $props['candidatos'][0]['name']);
        $this->assertSame(423130, $props['candidatos'][0]['votos']);
        $this->assertSame('General Juvenal Gobernador', $props['candidatos'][0]['partido']);
        $this->assertTrue($props['candidatos'][0]['electo']);
        $this->assertSame([423130, 208781, 181399, 135183, 26532, 11125, 10868, 8493, 3502], array_column($props['candidatos'], 'votos'));
        $this->assertSame(1085625, $props['resumen']['validos']);
        $this->assertSame(76612, $props['resumen']['blanco']);
        $this->assertSame(1168968, $props['resumen']['votantes']);
        $this->assertEquals(63.91, $props['resumen']['participacion']);
        $this->assertSame(Gobernacion2023Totales::SOURCE_URL, $props['resumen']['fuenteUrl']);
    }

    public function test_gobernador_municipio_shows_no_votes_instead_of_the_department_totals(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();

        $props = $this->gobernadorProps($this->ids['bucaramanga']);

        $this->assertCount(9, $props['candidatos']);
        $this->assertSame([null], array_values(array_unique(array_column($props['candidatos'], 'votos'), SORT_REGULAR)));
        $this->assertNull($props['resumen']);
    }

    public function test_gobernador_without_turnout_has_no_resumen(): void
    {
        $props = $this->gobernadorProps(null);

        $this->assertNull($props['resumen']);
        $this->assertSame(5948, $props['candidatos'][0]['votos']);
    }

    public function test_gobernador_only_opens_a_municipio(): void
    {
        foreach (['santander', 'metropolitana'] as $key) {
            try {
                $this->gobernadorProps($this->ids[$key]);
                $this->fail("/gobernador/{id} abrió el id de {$key}");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $props = $this->gobernadorProps($this->ids['bucaramanga']);
        $this->assertSame('Bucaramanga', $props['municipio']['name']);
        $this->assertSame('Metropolitana', $props['municipio']['provincia']);
    }

    public function test_percentages_truncate_like_the_screen(): void
    {
        $this->assertSame('38,97', GobernacionResultados::porcentaje(423130, 1085625));
        $this->assertSame('7,05', GobernacionResultados::porcentaje(76612, 1085625));
        $this->assertSame('0,32', GobernacionResultados::porcentaje(3502, 1085625));
        $this->assertSame('66,66', GobernacionResultados::porcentaje(2, 3));
        $this->assertNull(GobernacionResultados::porcentaje(null, 1085625));
        $this->assertNull(GobernacionResultados::porcentaje(10, 0));
        $this->assertSame(110, GobernacionResultados::base([['votos' => 60], ['votos' => 40]], ['validos' => null, 'blanco' => 10]));
        $this->assertSame(500, GobernacionResultados::base([['votos' => 60]], ['validos' => 500, 'blanco' => 10]));
    }

    private function exportRequest(string $format, array $query, bool $allowed = true)
    {
        $user = new class($allowed)
        {
            public function __construct(private bool $allowed) {}

            public function hasPermission(string $p): bool
            {
                return $this->allowed && $p === 'view.gobernador';
            }

            public function allowedGeoIds(): ?array
            {
                return null;
            }
        };
        $request = Request::create("/exportar/{$format}", 'GET', $query + ['source' => 'gobernador', 'title' => 'Gobernador']);
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $user);

        return app(ExportController::class)->{$format}($request);
    }

    private function excelSheets(array $query): array
    {
        $response = $this->exportRequest('excel', $query);
        $book = IOFactory::load($response->getFile()->getPathname());

        return [
            $response->headers->get('content-disposition'),
            collect($book->getAllSheets())->mapWithKeys(fn ($sheet) => [$sheet->getTitle() => $sheet->toArray(null, true, false)])->all(),
        ];
    }

    private function captureExportView(?object &$view): void
    {
        View::composer('exports.table', function ($v) use (&$view) {
            $view = $v;
        });
    }

    public function test_excel_matches_the_screen_with_blank_vote_percentages_summary_and_source(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();

        [$disposition, $sheets] = $this->excelSheets([]);

        $this->assertStringContainsString('Gobernador_Santander.xlsx', $disposition);
        $this->assertSame(['Resumen', 'Candidatos'], array_keys($sheets));
        $candidatos = $sheets['Candidatos'];
        $this->assertSame(['NOMBRE', 'PARTIDO', 'CARGO', 'VOTOS', '% VÁLIDOS'], $candidatos[0]);
        $this->assertSame(['Juvenal Diaz Mateus', 'General Juvenal Gobernador', 'Gobernador Electo', 423130, '38,97%'], $candidatos[1]);
        $this->assertSame(['Voto en blanco', '—', '—', 76612, '7,05%'], $candidatos[10]);
        $this->assertCount(11, $candidatos);

        // The same rows and percentages the page computes from its props.
        $props = $this->gobernadorProps(null);
        $base = GobernacionResultados::base($props['candidatos'], $props['resumen']);
        foreach ($props['candidatos'] as $i => $c) {
            $row = $candidatos[$i + 1];
            $this->assertSame([$c['name'], $c['votos'], GobernacionResultados::porcentaje($c['votos'], $base).'%'], [$row[0], $row[3], $row[4]]);
        }

        $resumen = collect($sheets['Resumen'])->slice(1)->mapWithKeys(fn ($r) => [$r[0] => $r[1]])->all();
        $this->assertSame('Santander (total departamental)', $resumen['Ámbito']);
        $this->assertSame(1828912, $resumen['Potencial electoral']);
        $this->assertSame(1168968, $resumen['Votantes']);
        $this->assertSame(1085625, $resumen['Votos válidos']);
        $this->assertSame(76612, $resumen['Voto en blanco']);
        $this->assertSame(19718, $resumen['Votos nulos']);
        $this->assertSame(63625, $resumen['No marcados']);
        $this->assertSame('63,91%', $resumen['Participación']);
        $this->assertStringContainsString(Gobernacion2023Totales::SOURCE_URL, $resumen['Fuente']);
    }

    public function test_pdf_has_the_same_rows_summary_and_source(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();
        $view = null;
        $this->captureExportView($view);

        $response = $this->exportRequest('pdf', []);

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('Gobernador_Santander.pdf', $response->headers->get('content-disposition'));
        $data = $view->getData();
        $this->assertSame('Gobernador Santander', $data['title']);
        $this->assertSame(['Juvenal Diaz Mateus', 423130, '38,97%'], [$data['data'][0]['nombre'], $data['data'][0]['votos'], $data['data'][0]['porcentaje']]);
        $this->assertSame(['Voto en blanco', 76612, '7,05%'], [$data['data'][9]['nombre'], $data['data'][9]['votos'], $data['data'][9]['porcentaje']]);
        $resumen = collect($data['resumen'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]])->all();
        $this->assertSame('1.828.912', $resumen['Potencial electoral']);
        $this->assertSame('63,91%', $resumen['Participación']);
        $this->assertNull($data['aviso']);
        $rendered = $view->render();
        $this->assertStringContainsString('% VÁLIDOS', $rendered);
        $this->assertStringContainsString('38,97%', $rendered);
        $this->assertStringContainsString('Votos nulos', $rendered);
        $this->assertStringContainsString(Gobernacion2023Totales::SOURCE_URL, $rendered);
    }

    public function test_export_from_a_municipio_says_there_are_no_municipal_results(): void
    {
        $this->artisan('data:gobernacion-2023-totales')->assertSuccessful();

        [$disposition, $sheets] = $this->excelSheets(['municipio' => $this->ids['bucaramanga']]);

        $this->assertStringContainsString('Gobernador_Bucaramanga.xlsx', $disposition);
        $resumen = collect($sheets['Resumen'])->slice(1)->mapWithKeys(fn ($r) => [$r[0] => $r[1]])->all();
        $this->assertSame(['Ámbito' => 'Bucaramanga (Metropolitana)', 'Aviso' => GobernacionResultados::SIN_RESULTADOS_MUNICIPIO], $resumen);
        $candidatos = array_slice($sheets['Candidatos'], 1);
        $this->assertCount(9, $candidatos);
        $this->assertSame(['—'], array_values(array_unique(array_column($candidatos, 3))));
        $this->assertSame(['—'], array_values(array_unique(array_column($candidatos, 4))));
        $this->assertNotContains('Voto en blanco', array_column($candidatos, 0));

        $view = null;
        $this->captureExportView($view);
        $this->exportRequest('pdf', ['municipio' => $this->ids['bucaramanga']]);
        $this->assertSame(GobernacionResultados::SIN_RESULTADOS_MUNICIPIO, $view->getData()['aviso']);
        $this->assertSame(1, substr_count($view->render(), 'No hay resultados de Gobernación por municipio'));
        $this->assertStringNotContainsString('423.130', $view->render());
    }

    public function test_export_rejects_a_non_municipio_or_a_user_without_permission(): void
    {
        foreach ([$this->ids['santander'], $this->ids['metropolitana'], 'abc'] as $id) {
            try {
                $this->exportRequest('excel', ['municipio' => $id]);
                $this->fail("exportó municipio={$id}");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->exportRequest('pdf', [], allowed: false);
            $this->fail('exportó sin view.gobernador');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
