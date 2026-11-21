<?php

namespace Tests\Feature;

use App\Exports\GenericExport;
use App\Models\Campaign;
use App\Services\Reportes\DescargaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsReportesB3Scenario;
use Tests\TestCase;

// Fixes of the QA of Reportes B-3 (docs/qa/2026-11-21-reportes-b3-*): presets without personal data (BUG-25), each
// responsable's phone next to their name (BUG-24), searches that ignore accents (BUG-19, the PostgreSQL side is in
// ReportesB3PostgresTest) and the generic notice of the old exports (O-1). SQLite.
class ReportesQaB3Test extends TestCase
{
    use BuildsReportesB3Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReportesB3();
    }

    // [clave => columnas] of the presets of a fuente, and the default one's clave.
    private function presets($user, string $fuente): array
    {
        $presets = $this->fuente($user, $fuente)['presets'];

        return [array_column($presets, 'columnas', 'clave'), collect($presets)->firstWhere('por_defecto', true)['clave']];
    }

    public function test_without_personal_data_no_preset_offers_a_sensitive_column(): void
    {
        foreach (['personas' => 'contacto_basico', 'asistencias' => 'contacto', 'compromisos' => 'con_telefono'] as $fuente => $contacto) {
            $sensibles = collect($this->fuente($this->cdDatos, $fuente)['columnas'])->where('sensible', true)->pluck('clave')->all();

            [$presets, $defecto] = $this->presets($this->cd, $fuente);
            $this->assertArrayNotHasKey($contacto, $presets, "{$fuente}: the contact preset is not offered without the permission");
            foreach ($presets as $clave => $columnas) {
                $this->assertSame([], array_values(array_intersect($columnas, $sensibles)), "{$fuente}.{$clave} has a sensitive column");
            }
            $this->assertNotSame($contacto, $defecto);
            // The default preset is what a list without columns gets: it works without the permission.
            $this->actingAs($this->cd)->postJson("/reportes/{$fuente}/lista", ['spec' => $this->spec($fuente, [], [])])->assertOk();

            [$presets, $defecto] = $this->presets($this->cdDatos, $fuente);
            $this->assertSame($contacto, $defecto, "{$fuente}: with the permission the contact preset is the default");
            $this->assertNotSame([], array_values(array_intersect($presets[$contacto], $sensibles)));
        }

        foreach (['organizaciones', 'miembros'] as $vista) {
            $sensibles = collect($this->fuente($this->cdDatos, 'empresarios')['columnas'])->where('sensible', true)->pluck('clave')->all();
            foreach ($this->fuente($this->cd, 'empresarios')['presets'] as $preset) {
                $this->assertSame([], array_values(array_intersect($preset['columnas'], $sensibles)), "empresarios.{$preset['clave']}");
            }
            $this->actingAs($this->cd)->postJson('/reportes/empresarios/lista', ['spec' => $this->spec('empresarios', ['vista' => $vista], [])])->assertOk();
        }
    }

    public function test_each_responsable_phone_goes_with_its_name(): void
    {
        $limite = Carbon::now('America/Bogota')->subDay()->toDateString();
        $ids = $this->acta($this->evCd, null, Campaign::DEFAULT_ID, [['Con tres responsables', $limite, 'pendiente', 'Ana Pérez']]);
        $id = $ids['Con tres responsables'];
        DB::table('event_compromiso_responsables')->where('compromiso_id', $id)->update(['telefono' => '3001112222']);
        foreach ([['Beto Sin Teléfono', null, 1], ['Ceci Gómez', '3103334444', 2]] as [$nombre, $telefono, $orden]) {
            DB::table('event_compromiso_responsables')->insert(['id' => (string) Str::uuid(), 'compromiso_id' => $id, 'orden' => $orden,
                'nombre' => $nombre, 'telefono' => $telefono, 'created_at' => now(), 'updated_at' => now()]);
        }

        $celdas = $this->lista($this->cdDatos, 'compromisos', [], ['descripcion', 'responsables', 'telefono_responsable'])['filas'][0]['celdas'];
        $this->assertSame('Ana Pérez / Beto Sin Teléfono / Ceci Gómez', $celdas['responsables']);
        $this->assertSame('Ana Pérez: 3001112222; Ceci Gómez: 3103334444', $celdas['telefono_responsable']);

        // The old Excel of /eventos/compromisos says it the same way.
        Excel::fake();
        $this->actingAs($this->cdDatos)->get('/eventos/compromisos.xlsx')->assertOk();
        Excel::assertDownloaded('compromisos.xlsx', function (GenericExport $export) {
            $telefonos = array_search('TELÉFONOS', $export->headings(), true);
            $this->assertSame('Ana Pérez: 3001112222; Ceci Gómez: 3103334444', $export->array()[0][$telefonos]);

            return true;
        });
    }

    public function test_options_of_municipios_ignore_accents(): void
    {
        foreach (['asistencias', 'compromisos', 'empresarios'] as $fuente) {
            if ($fuente === 'empresarios') {
                $this->org(Campaign::DEFAULT_ID, 'Empresa Girón');
            }
            if ($fuente === 'compromisos') {
                $this->acta($this->evCd, null, Campaign::DEFAULT_ID, [['Uno', null, 'pendiente', 'Ana']]);
            }
            $opciones = $this->actingAs($this->cd)->getJson("/reportes/opciones/municipios?fuente={$fuente}&q=GIRON")->assertOk()->json('opciones');
            $this->assertSame(['Girón'], array_column($opciones, 'etiqueta'), $fuente);
        }
    }

    public function test_the_notice_of_the_old_exports_names_personal_data_in_general(): void
    {
        $this->assertSame('Este archivo no incluye datos personales (cédula, teléfonos, correo, dirección u observaciones) porque no tienes el permiso '
            .'Descargar datos personales.', DescargaGuard::AVISO_SIN_DATOS);

        Excel::fake();
        $this->actingAs($this->cd)->get("/eventos/{$this->evCd->id}/asistentes.xlsx")->assertOk();
        Excel::assertDownloaded('asistentes-gira-giron.xlsx', fn (GenericExport $export) => $export->headings()[0] === [DescargaGuard::AVISO_SIN_DATOS]);
    }
}
