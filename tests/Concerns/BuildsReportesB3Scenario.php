<?php

namespace Tests\Concerns;

use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Reportes\Fuentes\AsistenciasFuente;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Reader\XLSX\Reader;

// SQLite scenario of Reportes B-3 (docs/reportes.md): campaigns CD with subgrupo X and Y apart (BuildsEventosScenario),
// fichas in Girón and Lebrija, events of each grupo (one 40 days old, one deleted) with their attendees, and the
// helpers to ask the reports API. Users: cd (no personal data), cdDatos (with it), deGiron (territory Girón).
trait BuildsReportesB3Scenario
{
    use BuildsEventosScenario;

    private User $cd;

    private User $cdDatos;

    private User $deGiron;

    private CampaignEvent $evCd;

    private CampaignEvent $evViejo;

    private CampaignEvent $evX;

    private CampaignEvent $evY;

    private CampaignEvent $evBorrado;

    private string $fichaGiron;

    private string $fichaLebrija;

    private const PERMISOS = ['view.reportes', 'view.eventos', 'view.empresarios', 'view.votantes'];

    // The grupos, municipios, users, events and attendees every B-3 test starts from.
    protected function setUpReportesB3(): void
    {
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        PersonasFuente::olvidarEsquema();
        AsistenciasFuente::olvidarEsquema();

        $this->cd = $this->eventUser(Campaign::DEFAULT_ID, self::PERMISOS);
        $this->cdDatos = $this->eventUser(Campaign::DEFAULT_ID, [...self::PERMISOS, 'export.datos_personales']);
        $this->deGiron = $this->eventUser(Campaign::DEFAULT_ID, [...self::PERMISOS, 'export.datos_personales'], 'R02_ADMIN_FUNCIONAL', [$this->giron]);

        $this->fichaGiron = $this->lider(Campaign::DEFAULT_ID, 'Ficha Girón', '1111');
        $this->fichaLebrija = $this->lider(Campaign::DEFAULT_ID, 'Ficha Lebrija', '2222', ['geographic_unit_id' => $this->lebrija, 'municipio' => 'Lebrija']);

        $this->evCd = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Girón']);
        $this->evViejo = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Lebrija', 'municipio_id' => $this->lebrija, 'starts_at' => now()->subDays(40)]);
        $this->evX = $this->makeEvent($this->campX, ['nombre' => 'Gira X']);
        $this->evY = $this->makeEvent($this->campY, ['nombre' => 'Gira Y']);
        $this->evBorrado = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira borrada', 'deleted_at' => now()]);

        $this->asistente($this->evCd, 'Ana', '1111', 'Centro', $this->fichaGiron, 'vinculado', 'qr');
        $this->asistente($this->evCd, 'Beto', '3333', 'El Poblado', null, 'pendiente', 'operador');
        $this->asistente($this->evCd, 'Ceci', '2222', 'Centro', $this->fichaLebrija, 'vinculado', 'excel');
        $this->asistente($this->evViejo, 'Dora', '4444', 'Centro', null, 'descartado', 'qr');
        $this->asistente($this->evX, 'Equis', '5555', 'Centro', null, 'pendiente', 'qr');
        $this->asistente($this->evY, 'Ye', '6666', 'Centro', null, 'pendiente', 'qr');
        $this->asistente($this->evBorrado, 'Borrado', '7777', 'Centro', null, 'pendiente', 'qr');
    }

    private function asistente(CampaignEvent $event, string $nombres, string $cedula, string $barrio, ?string $liderId, string $link, string $source): string
    {
        $id = (string) Str::uuid();
        DB::table('event_attendees')->insert([
            'id' => $id, 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'lider_id' => $liderId,
            'nombres' => $nombres, 'apellidos' => 'Prueba', 'cedula' => $cedula, 'celular' => '300'.$cedula,
            'barrio' => $barrio, 'barrio_normalizado' => AttendeeInput::barrioKey($barrio), 'autoriza_datos' => false,
            'source' => $source, 'link_status' => $link, 'client_uuid' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function spec(string $fuente, array $filtros = [], array $columnas = ['nombre'], array $extra = []): array
    {
        return array_merge(['v' => 1, 'fuente' => $fuente, 'filtros' => $filtros, 'columnas' => $columnas, 'orden' => [], 'busqueda' => ''], $extra);
    }

    private function conteo(User $user, string $fuente, array $filtros = [], array $extra = []): int
    {
        return $this->actingAs($user)->postJson("/reportes/{$fuente}/conteo", ['spec' => $this->spec($fuente, $filtros, [], $extra)])
            ->assertOk()->assertJson(['success' => true])->json('total');
    }

    private function lista(User $user, string $fuente, array $filtros = [], array $columnas = ['nombre'], array $extra = []): array
    {
        return $this->actingAs($user)->postJson("/reportes/{$fuente}/lista", ['spec' => $this->spec($fuente, $filtros, $columnas, $extra)])
            ->assertOk()->json();
    }

    private function fuentes(User $user): array
    {
        $claves = null;
        $this->actingAs($user)->get('/reportes')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$claves) {
                $claves = array_column($page->toArray()['props']['fuentes'], 'clave');
            });

        return $claves;
    }

    private function fuente(User $user, string $clave): array
    {
        $fuente = null;
        $this->actingAs($user)->get('/reportes')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$fuente, $clave) {
                $fuente = collect($page->toArray()['props']['fuentes'])->firstWhere('clave', $clave);
            });

        return $fuente;
    }

    // [heading..., header, data...] of a downloaded Excel of Reportes.
    private function excel(User $user, string $fuente, array $spec): array
    {
        $response = $this->actingAs($user)->postJson("/reportes/{$fuente}/excel", ['spec' => $spec])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
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
        @unlink($path);

        return $rows;
    }

    // A confirmed acta (or a borrador) of an event or an organization with its compromisos: [descripcion => id].
    private function acta(?CampaignEvent $event, ?string $orgId, string $campaign, array $compromisos, string $estado = 'confirmada'): array
    {
        $acta = (string) Str::uuid();
        DB::table('event_actas')->insert(['id' => $acta, 'event_id' => $event?->id, 'organizacion_id' => $orgId, 'campaign_id' => $campaign,
            'titulo' => 'Acta '.Str::random(3), 'fecha' => '2026-11-10', 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ($compromisos as $i => [$descripcion, $limite, $estadoC, $responsable]) {
            $id = $ids[$descripcion] = (string) Str::uuid();
            DB::table('event_compromisos')->insert(['id' => $id, 'acta_id' => $acta, 'event_id' => $event?->id, 'organizacion_id' => $orgId,
                'campaign_id' => $campaign, 'orden' => $i, 'descripcion' => $descripcion, 'fecha_limite' => $limite, 'estado' => $estadoC,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('event_compromiso_responsables')->insert(['id' => (string) Str::uuid(), 'compromiso_id' => $id, 'orden' => 0,
                'nombre' => $responsable, 'telefono' => '3109990000', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $ids;
    }

    private function org(string $campaign, string $nombre, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('organizaciones')->insert(array_merge(['id' => $id, 'campaign_id' => $campaign, 'tipo' => 'empresa', 'nombre' => $nombre,
            'municipio_id' => $this->giron, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], $extra));

        return $id;
    }
}
