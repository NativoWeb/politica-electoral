<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Reportes\DescargaGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsReportesB3Scenario;
use Tests\TestCase;

// «Nombre con números» in Votantes (table, counter and Excel, which share VotanteController::listing) and in Reportes
// (Personas, group «Datos por completar»): the same fichas the database has with a digit in the name, only inside the
// user's grupo político and territory. SQLite, fictitious data.
class NombreConNumerosFiltroTest extends TestCase
{
    use BuildsReportesB3Scenario;

    private array $esperadasCd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportesB3();
        RateLimiter::for(DescargaGuard::LIMITADOR, fn () => Limit::none());

        $this->esperadasCd = [
            $this->lider(Campaign::DEFAULT_ID, 'Ana Ruiz 1098765432', '9001'),
            $this->lider(Campaign::DEFAULT_ID, 'Beto2 Paz', '9002', ['geographic_unit_id' => $this->lebrija, 'municipio' => 'Lebrija']),
            $this->lider(Campaign::DEFAULT_ID, 'Inactivo 3', '9003', ['is_active' => false]),
        ];
        $this->lider(Campaign::DEFAULT_ID, 'Sin Numeros', '9004');
        $this->lider($this->campY, 'Otro Grupo 7', '9005');
    }

    private function votantes(User $user, string $query): array
    {
        return $this->actingAs($user)->get('/votantes?'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_votantes_shows_exactly_the_fichas_of_the_database_with_digits(): void
    {
        $props = $this->votantes($this->cd, 'nombre_con_numeros=1&estado=todos');
        $ids = collect($props['votantes']['data'])->pluck('id')->sort()->values()->all();

        $enBd = DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->get(['id', 'nombre'])
            ->filter(fn ($l) => preg_match('/[0-9]/', $l->nombre))->pluck('id')->sort()->values()->all();
        $this->assertSame($enBd, $ids);
        $this->assertEqualsCanonicalizing($this->esperadasCd, $ids);
        $this->assertSame(3, $props['counters']['total']);
        $this->assertSame('1', $props['filters']['nombre_con_numeros']);

        $this->assertSame(2, $this->votantes($this->cd, 'nombre_con_numeros=1')['counters']['total'], 'Activos by default.');
        $this->assertGreaterThan(3, $this->votantes($this->cd, 'nombre_con_numeros=0&estado=todos')['counters']['total'], 'Off: no filter.');
    }

    public function test_the_filter_keeps_the_grupo_and_the_territory(): void
    {
        $y = $this->eventUser($this->campY, ['view.votantes']);
        $this->assertSame(['Otro Grupo 7'], collect($this->votantes($y, 'nombre_con_numeros=1')['votantes']['data'])->pluck('nombre')->all());

        $giron = collect($this->votantes($this->deGiron, 'nombre_con_numeros=1')['votantes']['data'])->pluck('nombre')->all();
        $this->assertSame(['Ana Ruiz 1098765432'], $giron);
    }

    public function test_the_votantes_excel_uses_the_same_filter(): void
    {
        Excel::fake();
        $this->actingAs($this->cdDatos)->get('/exportar/excel?nombre_con_numeros=1&source=votantes&title=Votantes')->assertOk();
        $rows = null;
        Excel::assertDownloaded('Votantes.xlsx', function (GenericExport $export) use (&$rows) {
            $rows = $export->array();

            return true;
        });

        $this->assertEqualsCanonicalizing(['Ana Ruiz 1098765432', 'Beto2 Paz'], array_column($rows, 0));
    }

    public function test_reportes_counts_the_same_fichas_inside_grupo_and_territory(): void
    {
        $this->assertSame(2, $this->conteo($this->cd, 'personas', ['nombre_con_numeros' => true]));
        $this->assertSame(3, $this->conteo($this->cd, 'personas', ['nombre_con_numeros' => true, 'estado' => 'todas']));
        $this->assertSame(1, $this->conteo($this->deGiron, 'personas', ['nombre_con_numeros' => true]));
        $this->assertSame($this->votantes($this->cd, 'nombre_con_numeros=1')['counters']['total'],
            $this->conteo($this->cd, 'personas', ['nombre_con_numeros' => true]));

        $nombres = array_column($this->lista($this->cd, 'personas', ['nombre_con_numeros' => true])['filas'], 'celdas');
        $this->assertEqualsCanonicalizing(['Ana Ruiz 1098765432', 'Beto2 Paz'], array_column($nombres, 'nombre'));

        $filtro = collect($this->fuente($this->cd, 'personas')['filtros'])->firstWhere('clave', 'por_completar');
        $this->assertContains('nombre_con_numeros', array_column($filtro['filtros'], 'clave'));
    }
}
