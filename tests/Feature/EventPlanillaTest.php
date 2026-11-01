<?php

namespace Tests\Feature;

use App\Http\Controllers\EventoController;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Support\PlanillaQr;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Printable sign-in sheet of an event (GET /eventos/{id}/planilla.pdf), plus the attendee validation messages
// (nonexistent dates, Spanish 422 summary). SQLite.
class EventPlanillaTest extends TestCase
{
    use BuildsEventosScenario;

    private CampaignEvent $event;

    private User $manager;

    private User $operator;

    private ?array $viewData = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Gira Girón', 'status' => 'borrador']);
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R10_CONSULTA_EJECUTIVA');
        View::composer('eventos.planilla', fn ($view) => $this->viewData = $view->getData());
    }

    private function planilla(?User $user = null, ?CampaignEvent $event = null, string $query = '')
    {
        return $this->actingAs($user ?? $this->manager)->get('/eventos/'.($event ?? $this->event)->id.'/planilla.pdf'.$query);
    }

    private function pdfPages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_manage_eventos_downloads_a_three_page_pdf_and_it_is_audited(): void
    {
        $response = $this->planilla()->assertOk()->assertHeader('content-type', 'application/pdf');

        $date = $this->event->starts_at->copy()->setTimezone(EventoController::TZ)->format('Y-m-d');
        $this->assertStringContainsString("attachment; filename=planilla-gira-giron-{$date}.pdf", $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(3, $this->pdfPages($response->getContent()));

        $log = DB::table('audit_logs')->where('action', 'EVENTO_PLANILLA_DESCARGADA')->first();
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame($this->event->id, $log->entity_id);
        $this->assertSame('evento', $log->entity_type);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('eventos.planilla', $metadata['route']);
        $this->assertSame(3, $metadata['paginas']);
    }

    public function test_an_operator_with_register_asistentes_alone_downloads_it(): void
    {
        $this->planilla($this->operator)->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_pages_are_clamped_between_1_and_20(): void
    {
        $this->assertSame(3, EventoController::planillaPages(null));
        $this->assertSame(3, EventoController::planillaPages('abc'));
        $this->assertSame(3, EventoController::planillaPages('2.5'));
        $this->assertSame(1, EventoController::planillaPages('0'));
        $this->assertSame(1, EventoController::planillaPages('-4'));
        $this->assertSame(7, EventoController::planillaPages('7'));
        $this->assertSame(20, EventoController::planillaPages('500'));

        $this->assertSame(20, $this->pdfPages($this->planilla(query: '?paginas=500')->assertOk()->getContent()));
        $this->assertSame(1, $this->pdfPages($this->planilla(query: '?paginas=0')->assertOk()->getContent()));
        $this->assertSame([1, 20], DB::table('audit_logs')->where('action', 'EVENTO_PLANILLA_DESCARGADA')
            ->pluck('metadata')->map(fn ($m) => json_decode($m, true)['paginas'])->sort()->values()->all());
    }

    // The PNG for a payload is deterministic. The event id is pinned because the library's PHP reader (a ZXing port)
    // rejects about 1 in 100 valid codes that other readers (OpenCV) decode, which would make a random id flaky.
    public function test_each_page_qr_decodes_to_the_event_id_and_page_number(): void
    {
        $id = '0b1e4f7a-6c2d-4e8f-9a3b-7d5c1e2f4a6b';
        DB::table('events')->where('id', $this->event->id)->update(['id' => $id]);
        $this->event = CampaignEvent::findOrFail($id);
        $this->planilla(query: '?paginas=3')->assertOk();

        $this->assertCount(3, $this->viewData['pages']);
        $reader = new QRCode(new QROptions(['readerUseImagickIfAvailable' => false]));
        foreach ($this->viewData['pages'] as $page) {
            $png = base64_decode(substr($page['qr'], strlen('data:image/png;base64,')));
            $decoded = json_decode((string) $reader->readFromBlob($png), true);
            $this->assertSame(['e' => $this->event->id, 'p' => $page['numero']], $decoded);
        }
        $this->assertStringNotContainsString($this->event->public_token, PlanillaQr::payload($this->event->id, 1));
    }

    public function test_the_provisional_text_is_marked_and_an_approved_one_is_not(): void
    {
        $this->planilla()->assertOk();
        $html = view('eventos.planilla', $this->viewData)->render();
        $this->assertSame('provisional-1', $this->viewData['consent']->version);
        $this->assertStringContainsString('TEXTO PROVISIONAL — no válido como autorización', $html);
        $this->assertStringContainsString('Versión provisional-1', $html);

        $this->approvedText(Campaign::DEFAULT_ID, 'Autorizo a la campaña el tratamiento de mis datos. '.str_repeat('Detalle de la política de datos. ', 40));
        $this->planilla()->assertOk();
        $html = view('eventos.planilla', $this->viewData)->render();
        $this->assertStringNotContainsString('TEXTO PROVISIONAL', $html);
        $this->assertStringContainsString('Versión v1', $html);
        $this->assertStringContainsString('[…]', $html, 'A long text is summarized');
    }

    public function test_the_consent_summary_cuts_at_a_sentence_end(): void
    {
        $this->assertSame('Texto corto.', EventoController::consentSummary("Texto   corto.\n"));
        $long = 'Primera frase de la autorización con bastante contenido para ocupar espacio. '.str_repeat('palabra ', 100);
        $summary = EventoController::consentSummary($long, 120);
        $this->assertSame('Primera frase de la autorización con bastante contenido para ocupar espacio. […]', $summary);
        $this->assertLessThanOrEqual(130, mb_strlen(EventoController::consentSummary(str_repeat('palabra ', 100), 120)));
    }

    public function test_isolation_matches_the_event_detail(): void
    {
        $sub = $this->makeEvent($this->campX);
        $other = $this->makeEvent($this->campY);

        // Parent campaign sees (and prints) its subcampaign's events; an independent campaign's are a 404.
        $this->planilla(event: $sub)->assertOk();
        $this->planilla(event: $other)->assertNotFound();
        $this->planilla($this->operator, $other)->assertNotFound();
        // The subcampaign does not see the parent's events.
        $this->planilla($this->eventUser($this->campX, ['manage.eventos']))->assertNotFound();

        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R09_CAMPO', [$this->velez]);
        $this->planilla($outside)->assertForbidden();
        $inside = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R09_CAMPO', [$this->giron]);
        $this->planilla($inside)->assertOk();

        $this->actingAs($this->manager)->get('/eventos/no-es-uuid/planilla.pdf')->assertNotFound();
        $this->event->delete();
        $this->planilla()->assertNotFound();

        $this->assertSame(2, DB::table('audit_logs')->where('action', 'EVENTO_PLANILLA_DESCARGADA')->count(), 'Only real downloads are audited');
    }

    public function test_without_manage_or_register_permission_there_is_no_planilla(): void
    {
        foreach ([['view.eventos'], ['view.mapa', 'write.data']] as $permissions) {
            $user = $this->eventUser(Campaign::DEFAULT_ID, $permissions);
            $this->actingAs($user)->getJson("/eventos/{$this->event->id}/planilla.pdf")->assertForbidden();
            $this->actingAs($user)->get("/eventos/{$this->event->id}/planilla.pdf")->assertRedirect('/');
        }
        auth()->logout();
        $this->get("/eventos/{$this->event->id}/planilla.pdf")->assertRedirect('/login');

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EVENTO_PLANILLA_DESCARGADA')->count());
    }

    private function headPlanilla(?User $user = null, ?CampaignEvent $event = null)
    {
        return $this->actingAs($user ?? $this->manager)->call('HEAD', '/eventos/'.($event ?? $this->event)->id.'/planilla.pdf');
    }

    public function test_head_checks_access_but_does_not_render_or_audit(): void
    {
        Pdf::shouldReceive('loadView')->never();

        $response = $this->headPlanilla($this->operator)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('', $response->getContent());
        $this->assertStringContainsString('filename=planilla-gira-giron-', $response->headers->get('content-disposition'));
        $this->assertNull($this->viewData);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EVENTO_PLANILLA_DESCARGADA')->count());
    }

    public function test_head_without_access_answers_like_get(): void
    {
        Pdf::shouldReceive('loadView')->never();
        $other = $this->makeEvent($this->campY);
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes'], 'R09_CAMPO', [$this->velez]);
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);

        $this->headPlanilla(event: $other)->assertNotFound();
        $this->headPlanilla($outside)->assertForbidden();
        $this->headPlanilla($viewer)->assertForbidden(); // the permission middleware only redirects GET page visits
        $this->actingAs($this->manager)->call('HEAD', '/eventos/no-es-uuid/planilla.pdf')->assertNotFound();
    }

    public function test_head_has_its_own_quota_and_does_not_spend_the_downloads(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->headPlanilla($this->operator)->assertOk();
        }
        $this->headPlanilla($this->operator)->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->operator)->get("/eventos/{$this->event->id}/planilla.pdf?paginas=1")->assertOk();
        }
        $this->actingAs($this->operator)->get("/eventos/{$this->event->id}/planilla.pdf?paginas=1")->assertStatus(429);
    }

    private function register(array $overrides)
    {
        return $this->actingAs($this->operator)->postJson(
            "/eventos/{$this->event->id}/asistentes",
            $this->attendeePayload($this->provisionalText()->id, $overrides),
        );
    }

    public function test_a_nonexistent_birth_date_says_so_instead_of_the_format_message(): void
    {
        $this->register(['fecha_nacimiento' => '1980-02-31'])->assertStatus(422)
            ->assertJsonPath('errors.fecha_nacimiento', ['Esa fecha no existe.']);
        $this->register(['fecha_nacimiento' => '1981-02-29'])->assertStatus(422)
            ->assertJsonPath('errors.fecha_nacimiento', ['Esa fecha no existe.']);
        $this->register(['fecha_nacimiento' => '02/04/1980'])->assertStatus(422)
            ->assertJsonPath('errors.fecha_nacimiento', ['La fecha de nacimiento debe tener el formato AAAA-MM-DD.']);
        $this->register(['fecha_nacimiento' => '1880-01-01'])->assertStatus(422)
            ->assertJsonPath('errors.fecha_nacimiento', ['Revise la fecha de nacimiento.']);
        $this->register(['fecha_nacimiento' => now()->subYears(10)->toDateString()])->assertStatus(422)
            ->assertJsonPath('errors.fecha_nacimiento', ['El registro es solo para mayores de 18 años.']);
        $this->register(['fecha_nacimiento' => ['1980-01-01']])->assertStatus(422)->assertJsonValidationErrors('fecha_nacimiento');

        $this->assertSame(0, DB::table('event_attendees')->count());
        $this->register(['fecha_nacimiento' => '1980-02-29'])->assertCreated();
    }

    // Needs APP_LOCALE=es (config default); production must not override it with en.
    public function test_the_422_summary_message_is_in_spanish(): void
    {
        app()->setLocale('es');
        $this->register(['nombres' => '', 'apellidos' => '', 'celular' => '1'])->assertStatus(422)
            ->assertJsonPath('message', 'Escriba los nombres. (y 2 errores más)');
        $this->register(['nombres' => '', 'apellidos' => ''])->assertStatus(422)
            ->assertJsonPath('message', 'Escriba los nombres. (y 1 error más)');
    }
}
