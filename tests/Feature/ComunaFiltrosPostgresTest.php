<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Models\Role;
use App\Models\User;
use App\Services\Geo\ComunaCatalog;
use App\Services\Reportes\DescargaGuard;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

// The comuna filter of Votantes («Sin comuna» or one comuna, inside a municipio with comunas), the «Sin comuna» option
// and the «Origen de la comuna» column of Reportes, on the real schema. Synthetic grupos and municipios, so the local
// data (the real Bucaramanga) is never counted; everything is rolled back.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=ComunaFiltrosPostgresTest
class ComunaFiltrosPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const BUCA = 'Bucaramanga Filtros Comuna';

    private string $grupo;

    private string $otro;

    private string $buca;

    private string $giron;

    private array $c = [];

    private User $admin;

    private User $deOtro;

    private User $deGiron;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        PersonasFuente::olvidarEsquema();
        $this->grupo = $this->campaign('Grupo Filtros Comuna');
        $this->otro = $this->campaign('Otro Filtros Comuna');

        $prov = $this->geo('province', 'Provincia Filtros Comuna');
        $this->buca = $this->geo('municipality', self::BUCA, $prov);
        $this->giron = $this->geo('municipality', 'Girón Filtros Comuna', $prov);
        $this->c['c2'] = $this->geo('comuna', 'Comuna 2 - Nororiental', $this->buca, '99001-02');
        $this->c['c1'] = $this->geo('comuna', 'Comuna 1 - Norte', $this->buca, '99001-01');
        $this->c['r1'] = $this->geo('corregimiento', 'Corregimiento 1', $this->buca, '99001-R1');

        $todo = ['view.reportes', 'view.votantes', 'view.mapa', 'write.data', 'export.datos_personales'];
        $this->admin = $this->user($this->grupo, $todo);
        $this->deOtro = $this->user($this->otro, $todo);
        $this->deGiron = $this->user($this->grupo, $todo, 'R09_CAMPO', [$this->giron]);

        $this->ficha('Fc A barrio', ['comuna_id' => $this->c['c1'], 'comuna_source' => 'auto']);
        $this->ficha('Fc B puesto', ['comuna_id' => $this->c['c1'], 'comuna_source' => 'puesto']);
        $this->ficha('Fc C texto', ['comuna_id' => $this->c['c2'], 'comuna_source' => 'texto']);
        $this->ficha('Fc D sin señal');
        $this->ficha('Fc E quitada a mano', ['comuna_source' => 'manual']);
        $this->ficha('Fc F corregimiento a mano', ['comuna_id' => $this->c['r1'], 'comuna_source' => 'manual']);
        $this->ficha('Fc G inactiva', ['is_active' => false]);
        $this->ficha('Fc H de Girón', ['geographic_unit_id' => $this->giron]);
        $this->ficha('Fc I otro grupo', ['campaign_id' => $this->otro]);
        $this->ficha('Fc J otro grupo con comuna', ['campaign_id' => $this->otro, 'comuna_id' => $this->c['c1'], 'comuna_source' => 'auto']);
    }

    private function campaign(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6),
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function geo(string $type, string $name, ?string $parent = null, ?string $code = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent, 'official_code' => $code]);

        return $id;
    }

    private function user(string $campaign, array $permissions, string $role = 'R02_ADMIN_FUNCIONAL', ?array $territory = null): User
    {
        $user = User::create([
            'name' => 'Usuaria '.Str::random(4), 'email' => 'comunas-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', $role)->value('id'), 'permissions' => $permissions, 'territory_scope' => $territory,
        ]);
        $user->forceFill(['campaign_id' => $campaign])->save();

        return $user;
    }

    private function ficha(string $nombre, array $extra = []): string
    {
        $id = (string) Str::uuid();
        $geo = $extra['geographic_unit_id'] ?? $this->buca;
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => $this->grupo, 'geographic_unit_id' => $geo, 'nombre' => $nombre,
            'municipio' => DB::table('geographic_units')->where('id', $geo)->value('canonical_name'),
            'tipo' => 'votante', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    private function votantes(User $user, string $query): array
    {
        return $this->actingAs($user)->get('/votantes?'.$query)->assertOk()->viewData('page')['props'];
    }

    private function nombres(array $props): array
    {
        return array_column($props['votantes']['data'], 'nombre');
    }

    // Straight from the table: active fichas of the grupo in the municipio without comuna.
    private function sinComunaEnBd(string $campaign): int
    {
        return DB::table('lideres')->where('campaign_id', $campaign)->where('geographic_unit_id', $this->buca)
            ->where('is_active', true)->whereNull('comuna_id')->count();
    }

    private function conteo(User $user, array $filtros): int
    {
        return $this->actingAs($user)->postJson('/reportes/personas/conteo', ['spec' => $this->spec($filtros)])
            ->assertOk()->assertJson(['success' => true])->json('total');
    }

    private function spec(array $filtros, array $columnas = ['nombre']): array
    {
        return ['v' => 1, 'fuente' => 'personas', 'filtros' => $filtros, 'columnas' => $columnas,
            'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''];
    }

    private function opcionesComuna(User $user): array
    {
        $fuente = $this->actingAs($user)->get('/reportes')->assertOk()->viewData('page')['props']['fuentes'][0];
        $filtro = collect($fuente['filtros'])->pluck('filtros')->flatten(1)->firstWhere('clave', 'comuna');

        return $filtro['opciones'] ?? [];
    }

    public function test_votantes_offers_the_comunas_only_of_a_municipio_that_has_them(): void
    {
        $props = $this->votantes($this->admin, 'municipio='.rawurlencode(self::BUCA));

        $this->assertSame(['Comuna 1 - Norte', 'Comuna 2 - Nororiental', 'Corregimiento 1'], array_column($props['comunasPorMunicipio'][self::BUCA], 'nombre'));
        $this->assertSame(['comuna', 'comuna', 'corregimiento'], array_column($props['comunasPorMunicipio'][self::BUCA], 'tipo'));
        $this->assertArrayNotHasKey('Girón Filtros Comuna', $props['comunasPorMunicipio']);
    }

    public function test_sin_comuna_and_one_comuna_count_what_the_database_has(): void
    {
        $municipio = 'municipio='.rawurlencode(self::BUCA);

        $sin = $this->votantes($this->admin, "{$municipio}&comuna=sin_comuna");
        $this->assertSame(['Fc D sin señal', 'Fc E quitada a mano'], $this->nombres($sin));
        $this->assertSame($this->sinComunaEnBd($this->grupo), $sin['votantes']['total']);
        $this->assertSame($this->sinComunaEnBd($this->grupo), $sin['counters']['total']);
        $this->assertSame('sin_comuna', $sin['filters']['comuna']);

        $c1 = $this->votantes($this->admin, "{$municipio}&comuna={$this->c['c1']}");
        $this->assertSame(['Fc A barrio', 'Fc B puesto'], $this->nombres($c1));
        $this->assertSame(2, $c1['counters']['total']);

        $r1 = $this->votantes($this->admin, "{$municipio}&comuna={$this->c['r1']}");
        $this->assertSame(['Fc F corregimiento a mano'], $this->nombres($r1));

        // Comunas + sin comuna = the whole municipio.
        $todo = $this->votantes($this->admin, $municipio)['votantes']['total'];
        $this->assertSame(6, $todo);
        $this->assertSame($todo, $sin['votantes']['total'] + $c1['votantes']['total'] + 1
            + $this->votantes($this->admin, "{$municipio}&comuna={$this->c['r1']}")['votantes']['total']);
    }

    public function test_a_comuna_without_municipio_or_not_a_uuid_is_ignored_not_an_error(): void
    {
        $sinMunicipio = $this->votantes($this->admin, 'comuna=sin_comuna&search=Fc');
        $this->assertSame(7, $sinMunicipio['votantes']['total']);

        $basura = $this->votantes($this->admin, 'municipio='.rawurlencode(self::BUCA)."&comuna=1' OR 1=1");
        $this->assertSame(6, $basura['votantes']['total']);
    }

    public function test_sin_comuna_stays_inside_the_grupo_politico_and_the_territory(): void
    {
        $municipio = 'municipio='.rawurlencode(self::BUCA);

        $otro = $this->votantes($this->deOtro, "{$municipio}&comuna=sin_comuna");
        $this->assertSame(['Fc I otro grupo'], $this->nombres($otro));
        $this->assertSame($this->sinComunaEnBd($this->otro), $otro['votantes']['total']);

        $giron = $this->votantes($this->deGiron, "{$municipio}&comuna=sin_comuna");
        $this->assertSame(0, $giron['votantes']['total']);
        $this->assertSame([], $giron['comunasPorMunicipio']);
    }

    public function test_the_votantes_excel_has_the_rows_of_the_comuna_filter(): void
    {
        foreach (['sin_comuna' => ['Fc D sin señal', 'Fc E quitada a mano'], $this->c['c1'] => ['Fc A barrio', 'Fc B puesto']] as $comuna => $esperadas) {
            Excel::fake();
            $this->actingAs($this->admin)->get('/exportar/excel?municipio='.rawurlencode(self::BUCA)."&comuna={$comuna}&source=votantes&title=Votantes")->assertOk();
            $exported = null;
            Excel::assertDownloaded('Votantes.xlsx', function (GenericExport $export) use (&$exported) {
                $exported = $export->array();

                return true;
            });
            $this->assertSame($esperadas, array_column($exported, 0), "Excel comuna={$comuna}");
            RateLimiter::clear(DescargaGuard::LIMITADOR.'|min|'.$this->admin->id);
        }
    }

    public function test_reportes_sin_comuna_counts_like_votantes_and_the_database(): void
    {
        $opciones = array_values(array_filter($this->opcionesComuna($this->admin), fn ($o) => $o['municipio'] === $this->buca));
        $this->assertSame([$this->c['c1'], $this->c['c2'], $this->c['r1'], 'sin_comuna:'.$this->buca], array_column($opciones, 'valor'));
        $this->assertStringStartsWith('Sin comuna', end($opciones)['etiqueta']);

        $sin = ['comuna' => ['sin_comuna:'.$this->buca]];
        $this->assertSame($this->sinComunaEnBd($this->grupo), $this->conteo($this->admin, $sin));
        $this->assertSame(
            $this->votantes($this->admin, 'municipio='.rawurlencode(self::BUCA).'&comuna=sin_comuna')['votantes']['total'],
            $this->conteo($this->admin, ['municipio' => [$this->buca]] + $sin)
        );
        // "O" inside the filter: comuna 1 or without comuna.
        $this->assertSame(4, $this->conteo($this->admin, ['comuna' => [$this->c['c1'], 'sin_comuna:'.$this->buca]]));
        // A «Sin comuna» of a municipio that has none (or does not exist) is dropped: everything visible.
        $this->assertSame($this->conteo($this->admin, []), $this->conteo($this->admin, ['comuna' => ['sin_comuna:'.$this->giron]]));

        // The heading of the Excel says which municipio «Sin comuna» is about.
        $response = $this->actingAs($this->admin)->postJson('/reportes/personas/excel', ['spec' => $this->spec($sin)])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'com').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);
        $this->assertSame('Filtros: Sin comuna en '.self::BUCA, $rows[1][0]);
        $this->assertSame([['Fc D sin señal'], ['Fc E quitada a mano']], array_slice($rows, 4));
    }

    public function test_reportes_sin_comuna_stays_inside_the_grupo_politico_and_the_territory(): void
    {
        $this->assertSame(1, $this->conteo($this->deOtro, ['comuna' => ['sin_comuna:'.$this->buca]]));
        $this->assertSame($this->sinComunaEnBd($this->otro), $this->conteo($this->deOtro, ['comuna' => ['sin_comuna:'.$this->buca]]));

        // Outside the territory: neither offered nor applied (and the territory still limits the count).
        $this->assertNotContains('sin_comuna:'.$this->buca, array_column($this->opcionesComuna($this->deGiron), 'valor'));
        $this->assertSame(1, $this->conteo($this->deGiron, ['comuna' => ['sin_comuna:'.$this->buca]]));
    }

    public function test_reportes_say_where_each_comuna_came_from(): void
    {
        $filas = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec(['municipio' => [$this->buca]], ['nombre', 'comuna', 'comuna_origen']),
        ])->assertOk()->json('filas');
        $origen = array_column(array_column($filas, 'celdas'), 'comuna_origen', 'nombre');

        $this->assertSame([
            'Fc A barrio' => 'Por barrio',
            'Fc B puesto' => 'Por puesto de votación',
            'Fc C texto' => 'Escrita',
            'Fc D sin señal' => null,
            'Fc E quitada a mano' => 'Manual',
            'Fc F corregimiento a mano' => 'Manual',
        ], $origen);

        $columnas = $this->actingAs($this->admin)->get('/reportes')->viewData('page')['props']['fuentes'][0]['columnas'];
        $this->assertSame('Origen de la comuna', collect($columnas)->firstWhere('clave', 'comuna_origen')['etiqueta']);
    }

    // ---- QA 2026-11-24: BUG-04 (municipio filter of Votantes) and BUG-01 (citing the polling station source) ----

    public function test_the_votantes_municipio_filter_uses_the_geographic_unit_like_reportes(): void
    {
        // Typed in capitals by hand, but its unit is the municipio: it counts, as in Reportes.
        $this->ficha('Fc K en mayusculas', ['municipio' => mb_strtoupper(self::BUCA)]);
        $municipio = 'municipio='.rawurlencode(self::BUCA);

        $votantes = $this->votantes($this->admin, $municipio);
        $this->assertContains('Fc K en mayusculas', $this->nombres($votantes));
        $this->assertSame($this->conteo($this->admin, ['municipio' => [$this->buca]]), $votantes['votantes']['total']);

        // A ficha without unit is still found by its text (Reportes, which goes by unit, does not have it).
        $this->ficha('Fc L sin unidad', ['geographic_unit_id' => null, 'municipio' => self::BUCA]);
        $this->assertSame($this->conteo($this->admin, ['municipio' => [$this->buca]]) + 1,
            $this->votantes($this->admin, $municipio)['votantes']['total']);
        $this->assertNotContains('Fc K en mayusculas', $this->nombres($this->votantes($this->admin, 'municipio='.rawurlencode('Girón Filtros Comuna'))));
    }

    private function encabezadoExcel(array $spec): array
    {
        $response = $this->actingAs($this->admin)->postJson('/reportes/personas/excel', ['spec' => $spec])->assertOk();
        RateLimiter::clear(DescargaGuard::LIMITADOR.'|min|'.$this->admin->id);
        $path = tempnam(sys_get_temp_dir(), 'com').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray()[0] ?? null;
            }
            break;
        }
        $reader->close();
        unlink($path);

        return array_slice($rows, 0, 5);
    }

    public function test_a_report_with_comunas_by_polling_station_cites_its_source(): void
    {
        $cita = ComunaCatalog::ATRIBUCION_PUESTOS;

        $this->assertContains($cita, $this->encabezadoExcel($this->spec(['municipio' => [$this->buca]], ['nombre', 'comuna'])));
        $this->assertContains($cita, $this->encabezadoExcel($this->spec(['municipio' => [$this->buca]], ['nombre', 'comuna_origen'])));
        // Without the comuna in the list, or without any row by polling station: no citation.
        $this->assertNotContains($cita, $this->encabezadoExcel($this->spec(['municipio' => [$this->buca]], ['nombre'])));
        $this->assertNotContains($cita, $this->encabezadoExcel($this->spec(['comuna' => [$this->c['c2']]], ['nombre', 'comuna'])));

        $vista = null;
        View::composer('reportes.lista', function ($view) use (&$vista) {
            $vista = $view->getData();
        });
        $this->actingAs($this->admin)->postJson('/reportes/personas/pdf', ['spec' => $this->spec(['municipio' => [$this->buca]], ['nombre', 'comuna_origen'])])
            ->assertOk();
        $this->assertSame([$cita], $vista['atribuciones']);
        $this->assertStringContainsString('mv2e-prx5', view('reportes.lista', $vista)->render());
    }
}
