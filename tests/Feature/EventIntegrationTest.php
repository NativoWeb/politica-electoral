<?php

namespace Tests\Feature;

use App\Actions\Eventos\ApproveAttendees;
use App\Exports\GenericExport;
use App\Exports\SheetsExport;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Eventos especiales, phase 4 (docs/eventos-api.md §12): events in the person's ficha, Votantes filters and count,
// the "Asistentes a eventos" tipo of the Mapa Político (off by default), the territory view and the Excel exports.
// Campaigns: CD (root) sees its subcampaign X; Y is independent. SQLite.
class EventIntegrationTest extends TestCase
{
    use BuildsEventosScenario;

    private const PERMS = ['view.eventos', 'view.votantes', 'view.mapa'];

    private User $cd;

    private User $x;

    private User $y;

    private string $ana;

    private string $beto;

    private string $carla;

    private string $dora;

    private string $asis;

    private CampaignEvent $evCd;

    private CampaignEvent $evLebrija;

    private CampaignEvent $evX;

    private CampaignEvent $evY;

    private CampaignEvent $evBorrado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();

        $this->cd = $this->eventUser(Campaign::DEFAULT_ID, self::PERMS);
        $this->x = $this->eventUser($this->campX, self::PERMS);
        $this->y = $this->eventUser($this->campY, self::PERMS);

        $this->ana = $this->lider(Campaign::DEFAULT_ID, 'Ana Uno', '111', ['barrio' => 'San Martín']);
        $this->beto = $this->lider($this->campX, 'Beto Equis', '222', ['barrio' => 'SAN  MARTIN']);
        $this->carla = $this->lider($this->campY, 'Carla Ye', '333', ['barrio' => 'san martin']);
        $this->dora = $this->lider(Campaign::DEFAULT_ID, 'Dora Lebrija', '444', ['geographic_unit_id' => $this->lebrija, 'municipio' => 'Lebrija', 'barrio' => 'Centro']);
        $this->asis = $this->lider(Campaign::DEFAULT_ID, 'Asistente Evento', '555', ['tipo' => ApproveAttendees::TIPO, 'cargo' => null, 'barrio' => 'San Martín']);

        $this->evCd = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira CD Girón']);
        $this->barrio($this->evCd, 'San Martín');
        $this->evLebrija = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Novena Lebrija', 'municipio_id' => $this->lebrija, 'starts_at' => now()->subDays(3)]);
        $this->evX = $this->makeEvent($this->campX, ['nombre' => 'Gira X Girón', 'starts_at' => now()->subDays(2)]);
        $this->evY = $this->makeEvent($this->campY, ['nombre' => 'Gira Y Girón']);
        $this->barrio($this->evY, 'San Martín');
        $this->evBorrado = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Evento borrado', 'deleted_at' => now()]);

        $this->attendee($this->evCd, $this->ana, '111', 'San Martín', 'qr', 'vinculado');
        $this->attendee($this->evCd, $this->asis, '555', 'San Martín', 'excel', 'aprobado');
        $this->attendee($this->evCd, null, '666', 'San  Martin ', 'operador', 'pendiente', 'Pepe');
        $this->attendee($this->evLebrija, $this->ana, '111', 'Centro', 'operador', 'vinculado');
        $this->attendee($this->evBorrado, $this->ana, '111', 'San Martín', 'qr', 'vinculado');
        $this->attendee($this->evX, $this->beto, '222', 'san martín', 'qr', 'vinculado');
        $this->attendee($this->evY, $this->carla, '333', 'San Martín', 'qr', 'vinculado');

        DB::table('event_organizers')->insert([
            ['id' => (string) Str::uuid(), 'event_id' => $this->evLebrija->id, 'lider_id' => $this->ana, 'nombre' => 'Ana Uno', 'rol' => 'Coordinadora'],
            ['id' => (string) Str::uuid(), 'event_id' => $this->evCd->id, 'lider_id' => $this->dora, 'nombre' => 'Dora Lebrija', 'rol' => null],
        ]);
    }

    private function barrio(CampaignEvent $event, string $nombre): void
    {
        DB::table('event_barrios')->insert([
            'id' => (string) Str::uuid(), 'event_id' => $event->id, 'nombre' => $nombre,
            'nombre_normalizado' => AttendeeInput::barrioKey($nombre),
        ]);
    }

    private function attendee(CampaignEvent $event, ?string $liderId, string $cedula, string $barrio, string $source, string $link, string $nombres = 'Persona'): string
    {
        $id = (string) Str::uuid();
        DB::table('event_attendees')->insert([
            'id' => $id, 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'lider_id' => $liderId,
            'nombres' => $nombres, 'apellidos' => 'Asistente '.$cedula, 'cedula' => $cedula, 'celular' => '3001234567',
            'barrio' => $barrio, 'barrio_normalizado' => AttendeeInput::barrioKey($barrio),
            'autoriza_datos' => true, 'source' => $source, 'link_status' => $link, 'client_uuid' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function votantes(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/votantes'.$query)->assertOk()->viewData('page')['props'];
    }

    // ── 1. Ficha de la persona ──

    public function test_the_ficha_lists_attended_and_organized_events_of_visible_campaigns(): void
    {
        $data = $this->actingAs($this->cd)->getJson("/eventos/persona/{$this->ana}")
            ->assertOk()->assertJsonPath('success', true)->json('data');

        $this->assertSame(2, $data['total'], 'Gira CD and Novena Lebrija; the deleted event does not count');
        $this->assertSame(2, $data['asistio']);
        $this->assertSame(1, $data['organizo']);
        $byKey = collect($data['eventos'])->keyBy(fn ($e) => $e['nombre'].'|'.$e['participacion']);
        $this->assertSame(['Gira CD Girón|asistente', 'Novena Lebrija|asistente', 'Novena Lebrija|organizador'], $byKey->keys()->sort()->values()->all());
        $gira = $byKey['Gira CD Girón|asistente'];
        $this->assertSame('qr', $gira['origen']);
        $this->assertSame('Asistente', $gira['rol']);
        $this->assertSame(['San Martín'], $gira['barrios']);
        $this->assertSame('Girón', $gira['municipio']);
        $this->assertSame("/eventos/{$this->evCd->id}", $gira['url']);
        $this->assertSame('Organizador (Coordinadora)', $byKey['Novena Lebrija|organizador']['rol']);
        $this->assertNull($byKey['Novena Lebrija|organizador']['origen']);
    }

    public function test_the_ficha_respects_campaigns_and_territory(): void
    {
        // The parent sees its subcampaign's lider and event; the subcampaign never sees the parent; Y sees nothing of them.
        $this->actingAs($this->cd)->getJson("/eventos/persona/{$this->beto}")->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.eventos.0.nombre', 'Gira X Girón');
        $this->actingAs($this->x)->getJson("/eventos/persona/{$this->ana}")->assertNotFound();
        $this->actingAs($this->y)->getJson("/eventos/persona/{$this->ana}")->assertNotFound();
        $this->actingAs($this->cd)->getJson("/eventos/persona/{$this->carla}")->assertNotFound();
        $this->actingAs($this->cd)->getJson('/eventos/persona/no-uuid')->assertNotFound();
        $this->actingAs($this->cd)->getJson('/eventos/persona/'.Str::uuid())->assertNotFound();

        // Territory Lebrija: a Girón ficha is out (403); Dora's Girón event is not listed.
        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, self::PERMS, 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->actingAs($lebrija)->getJson("/eventos/persona/{$this->ana}")->assertForbidden();
        $this->actingAs($lebrija)->getJson("/eventos/persona/{$this->dora}")->assertOk()->assertJsonPath('data.total', 0);
        $this->actingAs($this->cd)->getJson("/eventos/persona/{$this->dora}")->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.eventos.0.rol', 'Organizador');
    }

    public function test_the_ficha_events_need_view_eventos(): void
    {
        foreach ([['view.votantes', 'view.mapa'], ['register.asistentes', 'view.votantes'], ['view.eventos']] as $permissions) {
            $user = $this->eventUser(Campaign::DEFAULT_ID, $permissions);
            $this->actingAs($user)->getJson("/eventos/persona/{$this->ana}")->assertForbidden();
        }
        $manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.votantes']);
        $this->actingAs($manager)->getJson("/eventos/persona/{$this->ana}")->assertOk()->assertJsonPath('data.total', 2);
    }

    // ── 2. Votantes ──

    public function test_votantes_filters_by_one_event_and_by_any_event_with_counters(): void
    {
        $props = $this->votantes($this->cd, "?evento={$this->evCd->id}");
        $this->assertEqualsCanonicalizing([$this->ana, $this->asis], array_column($props['votantes']['data'], 'id'));
        $this->assertSame(2, $props['counters']['total']);
        $this->assertSame($this->evCd->id, $props['filters']['evento']);

        $props = $this->votantes($this->cd, '?asistentes_eventos=1');
        $this->assertEqualsCanonicalizing([$this->ana, $this->asis, $this->beto], array_column($props['votantes']['data'], 'id'));
        $this->assertSame(3, $props['counters']['total']);
        $this->assertSame('1', $props['filters']['asistentes_eventos']);

        $props = $this->votantes($this->cd);
        $this->assertSame(4, $props['counters']['total'], 'Without the filter: Ana, Beto, Dora and the attendee ficha; nobody of Y');
        $counts = collect($props['votantes']['data'])->pluck('eventos_count', 'id');
        $this->assertSame(2, (int) $counts[$this->ana], 'The deleted event does not count');
        $this->assertSame(1, (int) $counts[$this->beto]);
        $this->assertSame(0, (int) $counts[$this->dora], 'Organizing is not attending');
        $this->assertTrue($props['canViewEventos']);
        $this->assertSame(['Gira CD Girón', 'Gira X Girón', 'Novena Lebrija'], collect($props['eventos'])->pluck('nombre')->sort()->values()->all());
    }

    public function test_votantes_event_filter_is_ignored_when_the_event_is_not_visible_or_without_permission(): void
    {
        $props = $this->votantes($this->x, "?evento={$this->evCd->id}");
        $this->assertSame([$this->beto], array_column($props['votantes']['data'], 'id'));
        $this->assertArrayNotHasKey('evento', $props['filters']);
        $this->assertSame(['Gira X Girón'], collect($props['eventos'])->pluck('nombre')->all());

        $this->assertSame(1, $this->votantes($this->y, "?evento={$this->evY->id}")['counters']['total']);

        $sinEventos = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa']);
        $props = $this->votantes($sinEventos, "?evento={$this->evCd->id}&asistentes_eventos=1");
        $this->assertSame(4, $props['counters']['total'], 'Ignored: this user cannot see attendees');
        $this->assertFalse($props['canViewEventos']);
        $this->assertSame([], $props['eventos']);
        $this->assertArrayNotHasKey('eventos_count', $props['votantes']['data'][0]);
        $this->assertArrayNotHasKey('evento', $props['filters']);
    }

    public function test_votantes_export_has_the_same_rows_as_the_filtered_screen(): void
    {
        foreach (["&evento={$this->evCd->id}", '&asistentes_eventos=1'] as $query) {
            $screen = collect($this->votantes($this->cd, '?'.ltrim($query, '&'))['votantes']['data'])->pluck('nombre')->all();
            Excel::fake();
            $this->actingAs($this->cd)->get("/exportar/excel?source=votantes&title=Votantes{$query}")->assertOk();
            $exported = null;
            Excel::assertDownloaded('Votantes.xlsx', function (GenericExport $export) use (&$exported) {
                $exported = $export->array();

                return true;
            });
            $this->assertSame($screen, array_column($exported, 0), $query);
        }
    }

    // ── 3. Mapa Político ──

    private function mapa(User $user, string $query): array
    {
        return $this->actingAs($user)->get("/mapa-politico?municipio={$this->giron}{$query}")->assertOk()->viewData('page')['props'];
    }

    public function test_attendee_fichas_are_their_own_tipo_off_by_default(): void
    {
        $lideres = $this->mapa($this->cd, '&tipo=lideres');
        $this->assertEqualsCanonicalizing(['Ana Uno', 'Beto Equis'], array_column($lideres['data'], 'nombre'));
        $this->assertSame(['Líderes' => 2], $lideres['sectionCounts']);

        $asistentes = $this->mapa($this->cd, '&tipo=asistentes_evento');
        $this->assertSame(['Asistente Evento'], array_column($asistentes['data'], 'nombre'));
        $this->assertSame(['Asistentes a eventos' => 1], $asistentes['sectionCounts']);
        $this->assertContains('Líder', $asistentes['cargosPorTipo']['asistentes_evento'] ?? []);

        $both = $this->mapa($this->cd, '&tipo=lideres,asistentes_evento');
        $this->assertSame(['Asistentes a eventos' => 1, 'Líderes' => 2], collect($both['sectionCounts'])->sortKeys()->all());

        // Y never sees CD's attendee ficha.
        $this->assertSame([], $this->mapa($this->y, '&tipo=asistentes_evento')['data']);
    }

    public function test_adding_attendee_fichas_does_not_change_the_lideres_counts(): void
    {
        DB::table('lideres')->where('id', $this->asis)->delete();
        $before = $this->mapa($this->cd, '&tipo=lideres');

        foreach (['Nueva Asistente', 'Otro Asistente'] as $i => $nombre) {
            $this->lider(Campaign::DEFAULT_ID, $nombre, '90'.$i, ['tipo' => ApproveAttendees::TIPO, 'cargo' => null]);
        }
        $after = $this->mapa($this->cd, '&tipo=lideres');

        $this->assertSame($before['sectionCounts'], $after['sectionCounts']);
        $this->assertSame(array_column($before['data'], 'nombre'), array_column($after['data'], 'nombre'));
        $this->assertCount(2, $this->mapa($this->cd, '&tipo=asistentes_evento')['data']);
    }

    public function test_mapa_export_labels_the_attendee_tipo(): void
    {
        Excel::fake();
        $this->actingAs($this->cd)->get("/exportar/excel?source=mapa-politico&tipo=asistentes_evento&municipio={$this->giron}")->assertOk();
        $exported = null;
        Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) use (&$exported) {
            $exported = $export->array();

            return true;
        });
        $this->assertSame([['Asistente Evento', 'Girón', 'Asistentes a eventos']], array_map(fn ($r) => array_slice($r, 0, 3), $exported));
    }

    public function test_ficha_tipo_label_and_saving_keeps_an_attendee_until_lideres_is_checked(): void
    {
        $this->assertSame('Asistentes a eventos', MapaPoliticoController::liderTipoLabel(ApproveAttendees::TIPO));
        $this->assertSame('Nuevos Líderes', MapaPoliticoController::liderTipoLabel('nuevo_lider'));
        $this->assertSame('Líderes', MapaPoliticoController::liderTipoLabel('directorio'));

        $this->assertSame(ApproveAttendees::TIPO, MapaPoliticoController::liderTipoFromLabels(['Asistentes a eventos']));
        $this->assertSame('directorio', MapaPoliticoController::liderTipoFromLabels(['Asistentes a eventos', 'Líderes']));
        $this->assertSame('nuevo_lider', MapaPoliticoController::liderTipoFromLabels(['Asistentes a eventos', 'Nuevos Líderes']));
        $this->assertSame('directorio', MapaPoliticoController::liderTipoFromLabels([]));
    }

    // ── 4. Vista por territorio ──

    private function territorio(User $user, string $query): array
    {
        return $this->actingAs($user)->get('/eventos/territorio'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_territory_view_matches_the_barrio_without_accents_case_or_extra_spaces(): void
    {
        $props = $this->territorio($this->cd, "?municipio={$this->giron}&barrio=".urlencode('  SAN   martín '));
        $r = $props['resultado'];

        $this->assertSame('SAN martín', $props['filters']['barrio']);
        $this->assertEqualsCanonicalizing(['Ana Uno', 'Beto Equis'], array_column($r['lideres']['data'], 'nombre'), 'Not the attendee ficha, not Y');
        $this->assertSame(['Gira CD Girón'], array_column($r['eventos'], 'nombre'), 'Gira X has no barrios; Gira Y is of Y');
        $this->assertSame(3, $r['eventos'][0]['asistentes']);
        $this->assertSame(3, $r['eventos'][0]['asistentes_barrio']);
        $this->assertEqualsCanonicalizing(['111', '555', '666', '222'], array_column($r['asistentes']['data'], 'cedula'));
        $this->assertSame(['lideres' => 2, 'eventos' => 1, 'asistentes' => 4, 'personas' => 4, 'en_votantes' => 3], $r['kpis']);

        $sanMartin = collect($props['barrios'])->where('key', 'san martin')->values();
        $this->assertCount(1, $sanMartin, 'One suggestion per barrio whatever its spelling');
        $this->assertSame('San Martín', $sanMartin[0]['nombre']);

        // The whole municipio: also Gira X and its attendee.
        $all = $this->territorio($this->cd, "?municipio={$this->giron}")['resultado'];
        $this->assertEqualsCanonicalizing(['Gira CD Girón', 'Gira X Girón'], array_column($all['eventos'], 'nombre'));
        $this->assertSame(4, $all['kpis']['asistentes']);
    }

    public function test_territory_view_isolation_and_permissions(): void
    {
        $x = $this->territorio($this->x, "?municipio={$this->giron}")['resultado'];
        $this->assertSame(['Beto Equis'], array_column($x['lideres']['data'], 'nombre'));
        $this->assertSame(['Gira X Girón'], array_column($x['eventos'], 'nombre'));
        $this->assertSame(['222'], array_column($x['asistentes']['data'], 'cedula'));

        $y = $this->territorio($this->y, "?municipio={$this->giron}")['resultado'];
        $this->assertSame(['Carla Ye'], array_column($y['lideres']['data'], 'nombre'));
        $this->assertSame(['333'], array_column($y['asistentes']['data'], 'cedula'));

        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, self::PERMS, 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->actingAs($lebrija)->get("/eventos/territorio?municipio={$this->giron}")->assertForbidden();
        $this->actingAs($lebrija)->get("/eventos/territorio/excel?municipio={$this->giron}")->assertForbidden();
        $props = $this->territorio($lebrija, '');
        $this->assertSame(['Lebrija'], collect($props['provincias'])->flatMap(fn ($p) => array_column($p['municipios'], 'name'))->all());
        $this->assertNull($props['resultado']);

        foreach ([['register.asistentes'], ['view.votantes', 'view.mapa']] as $permissions) {
            $user = $this->eventUser(Campaign::DEFAULT_ID, $permissions);
            $this->actingAs($user)->get("/eventos/territorio?municipio={$this->giron}")->assertRedirect('/');
            $this->actingAs($user)->getJson("/eventos/territorio/excel?municipio={$this->giron}")->assertForbidden();
        }
        $this->actingAs($this->cd)->get('/eventos/territorio/excel')->assertNotFound();
    }

    public function test_territory_excel_has_the_screen_rows_and_neutralizes_formulas(): void
    {
        $this->lider(Campaign::DEFAULT_ID, '=HYPERLINK("http://x","clic")', '777', ['barrio' => 'San Martín']);
        $query = "?municipio={$this->giron}&barrio=san%20martin";
        $screen = $this->territorio($this->cd, $query)['resultado'];

        Excel::fake();
        $this->actingAs($this->cd)->get('/eventos/territorio/excel'.$query)->assertOk();
        $sheets = null;
        Excel::assertDownloaded('territorio-giron-san-martin.xlsx', function (SheetsExport $export) use (&$sheets) {
            $sheets = $export->sheets();

            return true;
        });

        [$lideres, $eventos, $asistentes] = array_map(fn (GenericExport $s) => $s->array(), $sheets);
        $this->assertSame(['Líderes', 'Eventos', 'Asistentes'], array_map(fn (GenericExport $s) => $s->title(), $sheets));
        $this->assertSame(
            array_map(fn ($n) => str_starts_with($n, '=') ? "'".$n : $n, array_column($screen['lideres']['data'], 'nombre')),
            array_column($lideres, 0),
        );
        $this->assertContains('\'=HYPERLINK("http://x","clic")', array_column($lideres, 0));
        $this->assertSame(array_column($screen['eventos'], 'nombre'), array_column($eventos, 0));
        $this->assertSame(array_column($screen['asistentes']['data'], 'cedula'), array_map('strval', array_column($asistentes, 2)));
        $this->assertContains('GRUPO POLÍTICO', $sheets[0]->headings(), 'CD sees two campaigns: the sheet says whose each lider is');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTOS_TERRITORIO_EXPORTADO')->count());
    }

    // ── 5. Excel de asistentes del evento ──

    private function attendeesExcel(User $user, CampaignEvent $event, string $query = ''): array
    {
        Excel::fake();
        $this->actingAs($user)->get("/eventos/{$event->id}/asistentes.xlsx{$query}")->assertOk();
        $rows = null;
        Excel::assertDownloaded('asistentes-'.Str::slug($event->nombre).'.xlsx', function (GenericExport $export) use (&$rows) {
            $rows = ['headings' => $export->headings(), 'data' => $export->array()];

            return true;
        });

        return $rows;
    }

    public function test_attendees_excel_has_the_detail_rows_with_link_status_and_origin(): void
    {
        $this->attendee($this->evCd, null, '888', 'Centro', 'qr', 'pendiente', '=1+1');
        $screen = $this->actingAs($this->cd)->get("/eventos/{$this->evCd->id}")->assertOk()->viewData('page')['props']['asistentes'];

        $excel = $this->attendeesExcel($this->cd, $this->evCd);
        $this->assertSame(['NOMBRES', 'APELLIDOS', 'CÉDULA', 'CELULAR', 'TELÉFONO FIJO', 'FECHA DE NACIMIENTO', 'DIRECCIÓN', 'BARRIO',
            'ORIGEN', 'REGISTRADO POR', 'ESTADO DE VÍNCULO', 'EN VOTANTES', 'FECHA DE REGISTRO'], $excel['headings']);
        $this->assertSame($screen['total'], count($excel['data']));
        $this->assertEqualsCanonicalizing(array_column($screen['data'], 'cedula'), array_map('strval', array_column($excel['data'], 2)));
        $this->assertContains("'=1+1", array_column($excel['data'], 0));
        $ana = collect($excel['data'])->first(fn ($r) => (string) $r[2] === '111');
        $this->assertSame(['QR', 'Ya estaba en Votantes', 'Sí'], [$ana[8], $ana[10], $ana[11]]);

        $pendientes = $this->attendeesExcel($this->cd, $this->evCd, '?link_status=pendiente');
        $this->assertEqualsCanonicalizing(['666', '888'], array_map('strval', array_column($pendientes['data'], 2)));
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'EVENTO_ASISTENTES_EXPORTADOS')->count());
    }

    public function test_attendees_excel_respects_isolation_and_permissions(): void
    {
        $this->actingAs($this->cd)->get("/eventos/{$this->evY->id}/asistentes.xlsx")->assertNotFound();
        $this->actingAs($this->x)->get("/eventos/{$this->evCd->id}/asistentes.xlsx")->assertNotFound();
        $this->actingAs($this->cd)->get("/eventos/{$this->evBorrado->id}/asistentes.xlsx")->assertNotFound();
        $this->assertCount(1, $this->attendeesExcel($this->cd, $this->evX)['data'], 'The parent reads its subcampaign');

        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, self::PERMS, 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->actingAs($lebrija)->get("/eventos/{$this->evCd->id}/asistentes.xlsx")->assertForbidden();

        $operador = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']);
        $this->actingAs($operador)->getJson("/eventos/{$this->evCd->id}/asistentes.xlsx")->assertForbidden();
        $this->actingAs($operador)->get("/eventos/{$this->evCd->id}/asistentes.xlsx")->assertRedirect('/');
    }

    // ── 6. OBS-07: the import requests check campaign and territory before validating the body ──

    public function test_import_requests_answer_404_or_403_before_validating_the_body(): void
    {
        $manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $importY = (string) Str::uuid();
        $importX = (string) Str::uuid();
        foreach ([[$importY, $this->evY], [$importX, $this->evX]] as [$id, $event]) {
            DB::table('event_imports')->insert([
                'id' => $id, 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'original_name' => 'a.xlsx',
                'file_path' => 'x', 'file_sha256' => str_repeat('a', 64), 'headers' => '[]', 'status' => 'revisado',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $fila = (string) Str::uuid();

        $requests = fn (CampaignEvent $event, string $import) => [
            ['postJson', "/eventos/{$event->id}/importar", []],
            ['putJson', "/eventos/{$event->id}/importar/{$import}/columnas", ['columnas' => 'no']],
            ['patchJson', "/eventos/{$event->id}/importar/{$import}/filas/{$fila}", ['decision' => 'quizás']],
            ['postJson', "/eventos/{$event->id}/importar/{$import}/confirmar", []],
        ];

        foreach ($requests($this->evY, $importY) as [$method, $url, $body]) {
            $this->actingAs($manager)->{$method}($url, $body)->assertNotFound();
        }
        foreach ($requests($this->evX, $importX) as [$method, $url, $body]) {
            $this->actingAs($manager)->{$method}($url, $body)->assertForbidden();
        }
        // An import of another event, or one that does not exist, is a 404 too.
        $this->actingAs($manager)->putJson("/eventos/{$this->evCd->id}/importar/{$importY}/columnas", ['columnas' => 'no'])->assertNotFound();
        $this->actingAs($manager)->postJson("/eventos/{$this->evCd->id}/importar/".Str::uuid().'/confirmar', [])->assertNotFound();

        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $this->actingAs($lebrija)->postJson("/eventos/{$this->evCd->id}/importar", [])->assertForbidden();

        // The own event still validates the body.
        $this->actingAs($manager)->postJson("/eventos/{$this->evCd->id}/importar", [])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    // ── Revisión: documentos y teléfonos como texto en el .xlsx real ──

    private function xlsx(User $user, string $url): Spreadsheet
    {
        $response = $this->actingAs($user)->get($url)->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    // Data type of the cell under the given heading in row 2 ('s' = text).
    private function typeUnder(Spreadsheet $book, string $sheet, string $heading): string
    {
        $ws = $book->getSheetByName($sheet);
        $headings = $ws->rangeToArray('A1:'.$ws->getHighestColumn().'1')[0];
        $col = array_search($heading, $headings, true);
        $this->assertNotFalse($col, "{$sheet}: no column {$heading}");

        return $ws->getCell([$col + 1, 2])->getDataType();
    }

    public function test_documents_and_phones_are_written_as_text_in_every_excel(): void
    {
        $evento = $this->xlsx($this->cd, "/eventos/{$this->evCd->id}/asistentes.xlsx");
        $this->assertSame('s', $this->typeUnder($evento, 'Asistentes', 'CÉDULA'));
        $this->assertSame('s', $this->typeUnder($evento, 'Asistentes', 'CELULAR'));

        $territorio = $this->xlsx($this->cd, "/eventos/territorio/excel?municipio={$this->giron}");
        $this->assertSame('s', $this->typeUnder($territorio, 'Líderes', 'CÉDULA'));
        $this->assertSame('s', $this->typeUnder($territorio, 'Líderes', 'TELÉFONO'));
        $this->assertSame('s', $this->typeUnder($territorio, 'Asistentes', 'CÉDULA'));
        $this->assertSame('s', $this->typeUnder($territorio, 'Asistentes', 'CELULAR'));
        $this->assertSame('@', $territorio->getSheetByName('Asistentes')->getStyle('C2')->getNumberFormat()->getFormatCode());

        $mapa = $this->xlsx($this->cd, "/exportar/excel?source=mapa-politico&tipo=lideres&municipio={$this->giron}&columns=nombre,cedula,telefono");
        $this->assertSame('s', $this->typeUnder($mapa, 'Exportacion', 'CEDULA'));
        $this->assertSame('s', $this->typeUnder($mapa, 'Exportacion', 'TELÉFONO'));

        $votantes = $this->xlsx($this->cd, '/exportar/excel?source=votantes&title=Votantes&columns=nombre,cedula,telefono');
        $this->assertSame('s', $this->typeUnder($votantes, 'Votantes', 'CEDULA'));
        $this->assertSame('s', $this->typeUnder($votantes, 'Votantes', 'TELÉFONO'));
        $this->assertSame('111', (string) $votantes->getSheetByName('Votantes')->getCell('B2')->getValue());
    }

    public function test_territory_attendees_sheet_says_whose_campaign(): void
    {
        Excel::fake();
        $this->actingAs($this->cd)->get("/eventos/territorio/excel?municipio={$this->giron}")->assertOk();
        Excel::assertDownloaded('territorio-giron.xlsx', function (SheetsExport $export) {
            $asistentes = $export->sheets()[2];
            $this->assertContains('GRUPO POLÍTICO', $asistentes->headings());
            $campana = collect($asistentes->array())->mapWithKeys(fn ($r) => [(string) $r[2] => $r[array_key_last($r)]]);
            $this->assertSame('Alejandro', $campana['222']);
            $this->assertSame('Centro Democrático', $campana['111']);

            return true;
        });
    }

    // ── Revisión: sin view.eventos el Mapa no revela asistencia a eventos ──

    public function test_without_view_eventos_attendee_fichas_are_plain_lideres_in_the_mapa(): void
    {
        $mapaOnly = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes']);

        $lideres = $this->mapa($mapaOnly, '&tipo=lideres');
        $this->assertEqualsCanonicalizing(['Ana Uno', 'Beto Equis', 'Asistente Evento'], array_column($lideres['data'], 'nombre'));
        $this->assertSame(['Líderes' => 3], $lideres['sectionCounts']);
        $this->assertSame('Líderes', collect($lideres['data'])->firstWhere('nombre', 'Asistente Evento')['tipo_registro']);
        $this->assertArrayNotHasKey('asistentes_evento', $lideres['cargosPorTipo']);

        // QA BUG-06: the attendee tipo is dropped as if it had not been sent (not an empty list).
        $asked = $this->mapa($mapaOnly, '&tipo=lideres,asistentes_evento');
        $this->assertSame(array_column($lideres['data'], 'nombre'), array_column($asked['data'], 'nombre'));
        $this->assertSame('lideres', $asked['filters']['tipo']);
        $this->assertStringNotContainsString('Asistentes a eventos', json_encode($asked, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Asistentes a eventos', json_encode($lideres, JSON_UNESCAPED_UNICODE));

        $ficha = $this->actingAs($mapaOnly)->getJson("/mapa-politico/persona/{$this->asis}")->assertOk()->json();
        $this->assertSame('Líderes', $ficha['tipo_registro']);
        $this->assertStringNotContainsString('Asistentes a eventos', json_encode($ficha, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('asistente_evento', json_encode($ficha, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Asistentes a eventos', $this->actingAs($this->cd)->getJson("/mapa-politico/persona/{$this->asis}")->json('tipo_registro'));

        Excel::fake();
        $this->actingAs($mapaOnly)->get("/exportar/excel?source=mapa-politico&tipo=lideres,asistentes_evento&municipio={$this->giron}")->assertOk();
        Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) {
            $rows = collect($export->array());
            $this->assertSame('Líderes', $rows->first(fn ($r) => $r[0] === 'Asistente Evento')[2]);
            $this->assertNotContains('Asistentes a eventos', $rows->pluck(2)->all());

            return true;
        });
    }

    public function test_barrio_options_leave_out_attendee_only_barrios_while_the_tipo_is_off(): void
    {
        DB::table('lideres')->where('id', $this->asis)->update(['barrio' => 'Solo Asistentes']);
        $mapaOnly = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes']);

        $this->assertNotContains('Solo Asistentes', $this->mapa($this->cd, '&tipo=lideres')['barrios']);
        $this->assertContains('Solo Asistentes', $this->mapa($this->cd, '&tipo=lideres,asistentes_evento')['barrios']);
        // For whoever cannot see events the ficha is a listed lider, so its barrio is a usable option.
        $this->assertContains('Solo Asistentes', $this->mapa($mapaOnly, '&tipo=lideres')['barrios']);
    }

    public function test_without_view_eventos_no_json_or_prop_carries_the_attendee_tipo(): void
    {
        $plain = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes']);
        $leaks = fn ($payload) => str_contains(json_encode($payload, JSON_UNESCAPED_UNICODE), 'asistente_evento');

        $props = $this->votantes($plain);
        $this->assertSame('directorio', collect($props['votantes']['data'])->firstWhere('id', $this->asis)['tipo']);
        $this->assertFalse($leaks($props), 'Votantes props');

        $show = $this->actingAs($plain)->getJson("/votantes/{$this->asis}")->assertOk()->json();
        $this->assertSame('directorio', $show['data']['tipo']);
        $this->assertFalse($leaks($show), 'GET /votantes/{id}');

        $this->assertFalse($leaks($this->actingAs($plain)->getJson("/mapa-politico/persona/{$this->asis}")->assertOk()->json()), 'Ficha del Mapa');
        $this->assertFalse($leaks($this->actingAs($plain)->getJson("/votantes/{$this->ana}/referidos")->assertOk()->json()), 'Referidos');

        $lideres = $this->actingAs($plain)->get("/lideres/{$this->giron}")->assertOk()->viewData('page')['props'];
        $this->assertSame('directorio', collect($lideres['lideres'])->firstWhere('id', $this->asis)['tipo']);
        $this->assertFalse($leaks($lideres), '/lideres/{municipio}');

        // Who may see events keeps the real tipo.
        $this->assertSame('asistente_evento', $this->actingAs($this->cd)->getJson("/votantes/{$this->asis}")->json('data.tipo'));
        $this->assertSame('asistente_evento', collect($this->votantes($this->cd)['votantes']['data'])->firstWhere('id', $this->asis)['tipo']);
    }

    // ── QA F4 ──

    public function test_a_ficha_without_cargo_sends_no_null_cargos(): void
    {
        $ficha = $this->actingAs($this->cd)->getJson("/mapa-politico/persona/{$this->asis}")->assertOk()->json();
        $this->assertSame([], $ficha['cargos'], 'BUG-01: never [null]');
        $this->assertSame(['Líder'], $this->actingAs($this->cd)->getJson("/mapa-politico/persona/{$this->ana}")->json('cargos'));
    }

    public function test_votantes_counters_follow_every_filter(): void
    {
        DB::table('lideres')->where('id', $this->dora)->update(['gran_elector' => true]);

        $props = $this->votantes($this->cd, '?municipio=Lebrija');
        $this->assertSame([$this->dora], array_column($props['votantes']['data'], 'id'));
        $this->assertSame(['total' => 1, 'gran_elector' => 1, 'con_referidos' => 0], $props['counters']);

        $props = $this->votantes($this->cd, '?municipio=Girón&gran_elector=1');
        $this->assertSame(0, $props['counters']['total']);
        $this->assertSame(0, $props['counters']['gran_elector']);
    }

    public function test_without_view_eventos_only_the_attendee_tipo_falls_back_to_todos(): void
    {
        $mapaOnly = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa']);
        $request = Request::create('/mapa-politico', 'GET', ['tipo' => 'asistentes_evento']);
        $request->setUserResolver(fn () => $mapaOnly);
        $this->assertSame(['todos'], MapaPoliticoController::effectiveTipos($request));

        $request = Request::create('/mapa-politico', 'GET', ['tipo' => 'asistentes_evento']);
        $request->setUserResolver(fn () => $this->cd);
        $this->assertSame(['asistentes_evento'], MapaPoliticoController::effectiveTipos($request));
    }
}
