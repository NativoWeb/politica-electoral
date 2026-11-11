<?php

namespace Tests\Feature;

use App\Mail\CompromisosResumen;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Follow-up of the compromisos of the actas (docs/eventos-api.md §14): estados, avances with history and files,
// vencidos, the general screen with its filters and Excel, isolation and permissions. SQLite.
class EventCompromisoTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos', 'view.eventos']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID);
    }

    // [acta id, [compromiso ids]]
    // A confirmed acta (only those are followed up) unless $confirm is false.
    private function acta(CampaignEvent $event, array $compromisos, ?User $user = null, string $fecha = '2026-11-08', bool $confirm = true): array
    {
        $response = $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$event->id}/actas", [
            'titulo' => 'Acta', 'fecha' => $fecha, 'compromisos' => $compromisos,
        ])->assertCreated();
        $id = $response->json('data.id');
        if ($confirm) {
            $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$event->id}/actas/{$id}/confirmar")->assertOk();
        }

        return [$id, array_column($response->json('data.compromisos'), 'id')];
    }

    private function compromiso(string $descripcion, ?string $limite = null, string $responsable = 'Responsable Uno'): array
    {
        return ['descripcion' => $descripcion, 'fecha_limite' => $limite, 'responsables' => [['nombre' => $responsable, 'telefono' => '3001234567']]];
    }

    public function test_changing_the_estado_writes_an_avance_with_the_change(): void
    {
        [, [$id]] = $this->acta($this->event, [$this->compromiso('Gestionar cámaras')]);

        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$id}/estado", ['estado' => 'en_curso', 'nota' => 'Ya se llamó'])
            ->assertOk()->assertJsonPath('data.estado', 'en_curso')
            ->assertJsonPath('data.avances.0.estado_anterior', 'pendiente')->assertJsonPath('data.avances.0.estado_nuevo', 'en_curso')
            ->assertJsonPath('data.avances.0.nota', 'Ya se llamó')->assertJsonPath('data.avances.0.usuario', $this->manager->name);
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$id}/estado", ['estado' => 'en_curso'])->assertUnprocessable();
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$id}/estado", ['estado' => 'terminado'])->assertUnprocessable();

        $this->assertSame('en_curso', DB::table('event_compromisos')->where('id', $id)->value('estado'));
        $this->assertSame(1, DB::table('event_compromiso_avances')->count());
        $audit = json_decode(DB::table('audit_logs')->where('action', 'EVENTO_COMPROMISO_ESTADO')->value('metadata'), true);
        $this->assertSame(['compromiso_id' => $id, 'de' => 'pendiente', 'a' => 'en_curso'], $audit);
    }

    public function test_avances_take_a_note_and_an_optional_photo_or_pdf(): void
    {
        [, [$id]] = $this->acta($this->event, [$this->compromiso('Gestionar cámaras')]);

        $this->actingAs($this->manager)->postJson("/eventos/compromisos/{$id}/avances", ['nota' => 'Se envió la carta'])->assertCreated()
            ->assertJsonPath('data.avances.0.nota', 'Se envió la carta')->assertJsonPath('data.avances.0.archivo', null);

        $png = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(4, 4), $png);
        $response = $this->actingAs($this->manager)->post("/eventos/compromisos/{$id}/avances", ['archivo' => new UploadedFile($png, 'foto.png', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated();
        $avance = $response->json('data.avances.0');
        $this->assertSame('foto.png', $avance['archivo']['nombre']);
        Storage::disk('local')->assertExists(DB::table('event_compromiso_avances')->whereNotNull('archivo_path')->value('archivo_path'));
        $this->actingAs($this->manager)->get($avance['archivo']['url'])->assertOk()->assertHeader('content-type', 'image/png');

        $docx = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($docx, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<x/>');
        $zip->close();
        $this->actingAs($this->manager)->post("/eventos/compromisos/{$id}/avances", ['archivo' => new UploadedFile($docx, 'a.docx', null, null, true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
        $this->actingAs($this->manager)->postJson("/eventos/compromisos/{$id}/avances", ['nota' => ''])->assertUnprocessable()->assertJsonValidationErrors('nota');
        $this->assertSame(2, DB::table('event_compromiso_avances')->count());

        $other = $this->eventUser($this->campY, ['manage.eventos', 'view.eventos']);
        $this->actingAs($other)->get($avance['archivo']['url'])->assertNotFound();
    }

    public function test_a_compromiso_is_vencido_when_its_deadline_passed_and_it_is_still_open(): void
    {
        [, [$late, $done, $future, $none]] = $this->acta($this->event, [
            $this->compromiso('Atrasado', now('America/Bogota')->subDay()->toDateString()),
            $this->compromiso('Cumplido tarde', now('America/Bogota')->subDays(5)->toDateString()),
            $this->compromiso('A tiempo', now('America/Bogota')->addDays(3)->toDateString()),
            $this->compromiso('Sin fecha'),
        ]);
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$done}/estado", ['estado' => 'cumplido'])->assertOk()->assertJsonPath('data.vencido', false);

        $this->actingAs($this->manager)->getJson("/eventos/compromisos/{$late}")->assertJsonPath('data.vencido', true);
        $this->actingAs($this->manager)->get('/eventos/compromisos?vencidos=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Eventos/Compromisos')->has('compromisos.data', 1)->where('compromisos.data.0.id', $late)->where('compromisos.data.0.vencido', true));
        $this->actingAs($this->manager)->get('/eventos/compromisos')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('compromisos.data', 4)->where('compromisos.data.3.id', $done));
        $this->assertNotNull($future.$none);
    }

    public function test_the_screen_filters_paginates_and_exports(): void
    {
        $other = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Lebrija', 'municipio_id' => $this->lebrija]);
        $many = array_map(fn ($i) => $this->compromiso("Compromiso {$i}"), range(1, 21));
        $this->acta($this->event, $many);
        [, [$lebrija]] = $this->acta($other, [$this->compromiso('Gestionar vía Lebrija', null, 'Sandra Pachón')], null, '2026-10-01');

        $this->actingAs($this->manager)->get('/eventos/compromisos')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('compromisos.data', 20)->where('compromisos.total', 22)->where('compromisos.last_page', 2));
        foreach ([
            "municipio={$this->lebrija}", "evento={$other->id}", 'responsable=sandra', 'responsable=SANDRA PACH', 'hasta=2026-10-31', 'q=v%C3%ADa lebrija',
        ] as $query) {
            $this->actingAs($this->manager)->get("/eventos/compromisos?{$query}")->assertInertia(fn (AssertableInertia $page) => $page
                ->has('compromisos.data', 1)->where('compromisos.data.0.id', $lebrija)->where('compromisos.data.0.responsables.0.nombre', 'Sandra Pachón'));
        }
        $this->actingAs($this->manager)->get('/eventos/compromisos?desde=2026-11-01&estado=pendiente')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('compromisos.total', 21));
        $this->actingAs($this->manager)->get('/eventos/compromisos?estado=cumplido')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('compromisos.total', 0));

        $this->actingAs($this->manager)->get("/eventos/compromisos.xlsx?evento={$other->id}")->assertOk()
            ->assertDownload('compromisos.xlsx');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_COMPROMISOS_EXPORTADOS')->count());
    }

    public function test_compromisos_of_a_borrador_are_not_listed_nor_followed(): void
    {
        [, [$draft]] = $this->acta($this->event, [$this->compromiso('Del borrador', now('America/Bogota')->subDay()->toDateString())], null, '2026-11-08', false);

        $this->actingAs($this->manager)->get('/eventos/compromisos')->assertInertia(fn (AssertableInertia $page) => $page->where('compromisos.total', 0));
        $this->actingAs($this->manager)->getJson("/eventos/compromisos/{$draft}")->assertNotFound();
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$draft}/estado", ['estado' => 'cumplido'])->assertNotFound();
        $this->actingAs($this->manager)->postJson("/eventos/compromisos/{$draft}/avances", ['nota' => 'x'])->assertNotFound();
        $this->actingAs($this->manager)->getJson('/eventos/compromisos/alertas')->assertJsonPath('data.total', 0);
        $this->assertSame(0, DB::table('event_compromiso_avances')->count());
    }

    public function test_a_reminder_date_is_set_on_a_confirmed_compromiso(): void
    {
        [, [$id]] = $this->acta($this->event, [$this->compromiso('Con recordatorio')]);

        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$id}/recordatorio", ['recordar_el' => '2026-12-24'])->assertOk()
            ->assertJsonPath('data.recordar_el', '2026-12-24');
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$id}/recordatorio", ['recordar_el' => 'mañana'])->assertUnprocessable();
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$id}/recordatorio", ['recordar_el' => null])->assertOk()
            ->assertJsonPath('data.recordar_el', null);
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $this->actingAs($viewer)->putJson("/eventos/compromisos/{$id}/recordatorio", ['recordar_el' => '2026-12-24'])->assertForbidden();
    }

    public function test_the_bell_counts_overdue_due_soon_and_reminders_of_what_the_user_sees(): void
    {
        $day = fn (int $d) => now('America/Bogota')->addDays($d)->toDateString();
        [, [$late, $soon, $far, $remind, $later, $done]] = $this->acta($this->event, [
            $this->compromiso('Vencido', $day(-2)),
            $this->compromiso('Vence en 3 días', $day(3)),
            $this->compromiso('Vence en 10 días', $day(10)),
            $this->compromiso('Recordar hoy', $day(10)),
            $this->compromiso('Recordar mañana'),
            $this->compromiso('Cumplido', $day(-5)),
        ]);
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$remind}/recordatorio", ['recordar_el' => $day(0)])->assertOk();
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$later}/recordatorio", ['recordar_el' => $day(1)])->assertOk();
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$done}/estado", ['estado' => 'cumplido'])->assertOk();
        $sub = $this->makeEvent($this->campX);
        $xManager = $this->eventUser($this->campX, ['manage.eventos', 'view.eventos']);
        [, [$subLate]] = $this->acta($sub, [$this->compromiso('Vencido subcampaña', $day(-1))], $xManager);

        $this->actingAs($this->manager)->getJson('/eventos/compromisos/alertas')->assertOk()
            ->assertJsonPath('data.total', 4)->assertJsonPath('data.vencidos', 2)->assertJsonPath('data.por_vencer', 1)->assertJsonPath('data.recordatorios', 1)
            ->assertJsonPath('data.items.0.tipo', 'vencido')->assertJsonPath('data.items.2.id', $soon)->assertJsonPath('data.items.3.id', $remind)
            ->assertJsonPath('data.items.3.url', "/eventos/compromisos?abrir={$remind}");
        $this->actingAs($xManager)->getJson('/eventos/compromisos/alertas')->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $subLate);
        $velez = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->velez]);
        $this->actingAs($velez)->getJson('/eventos/compromisos/alertas')->assertJsonPath('data.total', 0);
        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']))->getJson('/eventos/compromisos/alertas')->assertForbidden();

        // Cached per user; a change made by the user refreshes their own count.
        $this->assertTrue(Cache::has('compromisos-alertas:'.$this->manager->id));
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$late}/estado", ['estado' => 'cumplido'])->assertOk();
        $this->actingAs($this->manager)->getJson('/eventos/compromisos/alertas')->assertJsonPath('data.vencidos', 1);
        $this->assertNotNull($far);
    }

    public function test_the_daily_summary_mails_only_managers_with_something_pending_in_their_campaigns(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Mail::fake();
        $day = fn (int $d) => now('America/Bogota')->addDays($d)->toDateString();
        $this->acta($this->event, [$this->compromiso('Vencido CD', $day(-1))]);
        $sub = $this->makeEvent($this->campX);
        $xManager = $this->eventUser($this->campX, ['manage.eventos']);
        $this->acta($sub, [$this->compromiso('Por vencer X', $day(1))], $xManager);
        $yManager = $this->eventUser($this->campY, ['manage.eventos']);
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $inactive = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $inactive->forceFill(['is_active' => false])->save();

        $this->artisan('compromisos:resumen-diario', ['--dry-run' => true])->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('compromisos:resumen-diario')->assertSuccessful();
        Mail::assertSent(CompromisosResumen::class, 2);
        Mail::assertSent(CompromisosResumen::class, fn ($m) => $m->hasTo($this->manager->email) && $m->resumen['total'] === 2 && $m->resumen['vencidos'] === 1);
        Mail::assertSent(CompromisosResumen::class, fn ($m) => $m->hasTo($xManager->email) && $m->resumen['total'] === 1 && $m->resumen['items'][0]['descripcion'] === 'Por vencer X');
        foreach ([$yManager, $viewer, $inactive] as $u) {
            Mail::assertNotSent(CompromisosResumen::class, fn ($m) => $m->hasTo($u->email));
        }
        $html = (new CompromisosResumen('Ana', ['total' => 1, 'vencidos' => 1, 'por_vencer' => 0, 'recordatorios' => 0, 'items' => [
            ['id' => 'x', 'tipo' => 'vencido', 'descripcion' => 'Gestionar vía', 'estado' => 'pendiente', 'fecha_limite' => '2026-11-01', 'recordar_el' => null, 'evento' => 'Gira', 'url' => '/x'],
        ]], 'https://app.test/eventos/compromisos'))->render();
        $this->assertStringContainsString('Gestionar vía', $html);
        $this->assertStringContainsString('https://app.test/eventos/compromisos', $html);
    }

    public function test_the_daily_summary_does_nothing_without_mail_configured(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        $this->acta($this->event, [$this->compromiso('Vencido', now('America/Bogota')->subDay()->toDateString())]);

        $this->artisan('compromisos:resumen-diario')->expectsOutputToContain('Correo no configurado')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_isolation_and_permissions(): void
    {
        [, [$own]] = $this->acta($this->event, [$this->compromiso('Del CD')]);
        $sub = $this->makeEvent($this->campX);
        $xManager = $this->eventUser($this->campX, ['manage.eventos', 'view.eventos']);
        [, [$subId]] = $this->acta($sub, [$this->compromiso('De la subcampaña')], $xManager);

        // The parent sees both, writes only its own.
        $this->actingAs($this->manager)->get('/eventos/compromisos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('compromisos.total', 2)->where('compromisos.data.0.editable', true)->where('compromisos.data.1.editable', false));
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$subId}/estado", ['estado' => 'cumplido'])->assertForbidden();
        $this->actingAs($this->manager)->postJson("/eventos/compromisos/{$subId}/avances", ['nota' => 'x'])->assertForbidden();
        // The subcampaign and another campaign do not see the parent's.
        $this->actingAs($xManager)->get('/eventos/compromisos')->assertInertia(fn (AssertableInertia $page) => $page->where('compromisos.total', 1));
        $this->actingAs($xManager)->getJson("/eventos/compromisos/{$own}")->assertNotFound();
        $y = $this->eventUser($this->campY, ['manage.eventos', 'view.eventos']);
        $this->actingAs($y)->postJson("/eventos/compromisos/{$own}/avances", ['nota' => ''])->assertNotFound();
        // Territory: a user of Vélez sees no compromiso of Girón.
        $velez = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->velez]);
        $this->actingAs($velez)->get('/eventos/compromisos')->assertInertia(fn (AssertableInertia $page) => $page->where('compromisos.total', 0));
        $this->actingAs($velez)->getJson("/eventos/compromisos/{$own}")->assertNotFound();
        // A deleted event hides its compromisos.
        $this->event->delete();
        $this->actingAs($this->manager)->getJson("/eventos/compromisos/{$own}")->assertNotFound();

        // view.eventos reads; it does not write.
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $this->actingAs($viewer)->getJson("/eventos/compromisos/{$subId}")->assertOk()->assertJsonPath('data.editable', false);
        $this->actingAs($viewer)->putJson("/eventos/compromisos/{$subId}/estado", ['estado' => 'cumplido'])->assertForbidden();
        $operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']);
        $this->actingAs($operator)->getJson("/eventos/compromisos/{$subId}")->assertForbidden();
        $this->assertSame(0, DB::table('event_compromiso_avances')->count());
    }
}
