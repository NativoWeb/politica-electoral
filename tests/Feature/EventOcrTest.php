<?php

namespace Tests\Feature;

use App\Console\Commands\OcrPilotSample;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Eventos\Ocr\AnthropicOcrClient;
use App\Services\Eventos\Ocr\OcrClient;
use App\Services\Eventos\Ocr\OcrException;
use App\Services\Eventos\Ocr\OcrSettings;
use App\Services\Eventos\Ocr\PlanillaPrompt;
use App\Support\PlanillaQr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\Support\FakeOcrClient;
use Tests\TestCase;

// Eventos especiales phase 5: printed sheets read with AI (docs/eventos-api.md §13). The API is never called: a
// FakeOcrClient answers. SQLite.
class EventOcrTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    private CampaignEvent $event;

    private FakeOcrClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos']);
        $this->event = $this->makeEvent(Campaign::DEFAULT_ID, ['status' => 'cerrado']);
        $this->approvedText(Campaign::DEFAULT_ID);
        $this->fake = new FakeOcrClient;
        $this->app->instance(OcrClient::class, $this->fake);
        $this->switches(true, true);
    }

    private function switches(bool $global, bool $campaign, ?string $key = 'sk-test'): void
    {
        config(['eventos.ocr.enabled' => $global, 'eventos.ocr.api_key' => $key]);
        DB::table('campaigns')->update(['ocr_enabled' => false]);
        if ($campaign) {
            DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['ocr_enabled' => true]);
        }
    }

    // A PNG "photo" of a sheet; with $qrEvent it carries the planilla QR of that event (decoded for real).
    private function photo(string $name = 'pagina.png', ?string $qrEvent = null, int $seed = 1): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 245, 240));
        imagestring($image, 5, 40, 40 + $seed, "Planilla {$seed}", imagecolorallocate($image, 0, 0, 0));
        if ($qrEvent) {
            $uri = PlanillaQr::png(PlanillaQr::payload($qrEvent, 1));
            $qr = imagecreatefromstring(base64_decode(substr($uri, strpos($uri, ',') + 1)));
            imagecopyresampled($image, $qr, 980, 40, 0, 0, 160, 160, imagesx($qr), imagesy($qr));
        }
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function upload(UploadedFile $file, ?string $importId = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post("/eventos/{$this->event->id}/importar/ocr", array_filter([
            'foto' => $file, 'importacion' => $importId,
        ]), ['Accept' => 'application/json']);
    }

    private function process(string $importId, array $body = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->postJson("/eventos/{$this->event->id}/importar/ocr/{$importId}/procesar", $body);
    }

    // Uploads the photos as one load and reads every page. Returns the import id.
    private function loadAndRead(array $photos): string
    {
        $id = null;
        foreach ($photos as $photo) {
            $id = $this->upload($photo, $id)->assertCreated()->json('data.id');
        }
        for ($i = 0; $i < count($photos); $i++) {
            $this->process($id)->assertOk();
        }

        return $id;
    }

    public function test_with_the_global_switch_off_nothing_exists(): void
    {
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1]]));
        $id = $this->loadAndRead([$this->photo()]);
        $this->switches(false, true);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr")->assertNotFound();
        $this->upload($this->photo('otra.png', null, 2))->assertNotFound();
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr/{$id}")->assertNotFound();
        $this->process($id)->assertNotFound();
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/{$id}")->assertNotFound();
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])->assertNotFound();
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.ocr', false));
    }

    public function test_with_the_campaign_switch_off_it_is_forbidden_and_hidden(): void
    {
        $this->switches(true, false);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr")->assertForbidden();
        $this->upload($this->photo())->assertForbidden()->assertJsonPath('message', OcrSettings::DISABLED_FOR_CAMPAIGN);
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.ocr', false));

        $this->switches(true, true);
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.ocr', true));
        $this->assertSame(0, DB::table('event_import_pages')->count());
        $this->assertSame([], $this->fake->calls);
    }

    public function test_without_api_key_the_screen_says_so_and_nothing_is_stored(): void
    {
        $this->switches(true, true, '');

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Planillas')
                ->where('ocr.listo', false)->where('ocr.aviso', OcrSettings::MISSING_KEY));
        $this->upload($this->photo())->assertUnprocessable()->assertJsonPath('errors.ocr.0', OcrSettings::MISSING_KEY);
        $this->assertSame(0, DB::table('event_imports')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->fake->calls);
    }

    public function test_full_flow_upload_read_review_correct_and_confirm(): void
    {
        $known = $this->lider(Campaign::DEFAULT_ID, 'Persona Conocida', '1098000002');
        $this->fake->push(
            FakeOcrClient::sheet([
                ['numero' => 1, 'cedula' => '1098000001', 'celular' => '3000000001'],
                ['numero' => 2, 'nombres' => 'LUIS', 'cedula' => '1098000002', 'celular' => '3000000002'],
                ['numero' => 3, 'nombres' => 'CARMEN', 'cedula' => '63000003', 'celular' => '3000000003', 'firma_presente' => false],
                ['numero' => 5, 'nombres' => 'PEDRO', 'cedula' => '12AB', 'celular' => '3000000005', 'confianza' => ['cedula' => 0.4]],
            ], ['evento_id' => $this->event->id]),
            FakeOcrClient::sheet([
                ['numero' => 1, 'nombres' => 'ROSA', 'cedula' => '37000007', 'celular' => '3000000007', 'confianza' => ['apellidos' => 0.5]],
            ], ['pagina' => 2]),
        );

        $first = $this->upload($this->photo('foto1.png', $this->event->id, 1))->assertCreated()
            ->assertJsonPath('success', true)->assertJsonPath('data.total_paginas', 1);
        $id = $first->json('data.id');
        $this->upload($this->photo('foto2.png', null, 2), $id)->assertCreated()->assertJsonPath('data.total_paginas', 2);
        $this->assertSame(2, count(Storage::disk('local')->files("eventos/ocr/{$this->event->id}/{$id}")));

        $this->process($id)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.pendientes', 1)->assertJsonPath('data.listo', false);
        $this->assertSame(0, DB::table('event_attendees')->count());
        $this->process($id)->assertOk()->assertJsonPath('data.listo', true)->assertJsonPath('data.filas', 5);

        $pages = DB::table('event_import_pages')->where('import_id', $id)->orderBy('sequence')->get();
        $this->assertSame('codigo', $pages[0]->qr_origin);
        $this->assertSame(strtolower($this->event->id), $pages[0]->qr_event_id);
        $this->assertStringContainsString('No se pudo leer el código del evento', (string) $pages[1]->message);
        $this->assertSame('revisado', DB::table('event_imports')->where('id', $id)->value('status'));
        $this->assertSame('ocr', DB::table('event_imports')->where('id', $id)->value('kind'));
        $this->assertSame(0, DB::table('event_attendees')->count(), 'nothing reaches the event before the confirmation');

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr/{$id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Eventos/Planillas')
                ->where('importacion.counts.total', 5)
                ->where('importacion.counts.validas', 3)
                ->where('importacion.counts.en_plataforma', 1)
                ->where('importacion.counts.no_autorizaron', 1)
                ->where('importacion.counts.errores', 1)
                ->where('pagina_actual', 1)
                ->where('paginas.0.dudosas', 1)
                ->where('paginas.1.dudosas', 1)
                ->where('filas.total', 4)
                ->where('filas.data.1.en_plataforma', true)
                ->where('filas.data.2.status', 'no_autorizo')
                ->where('filas.data.2.motivo', 'La IA no vio la firma de esta fila: la persona no se importa.')
                ->where('filas.data.3.fila_pagina', 5)
                ->where('filas.data.3.confianza.cedula', 0.4)
                ->where('filas.data.3.errores.cedula', 'La cédula debe tener solo números (entre 5 y 10 dígitos).'));
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr/{$id}?pagina=2")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pagina_actual', 2)->where('filas.total', 1)
                ->where('filas.data.0.valores.nombres', 'ROSA'));

        // The person corrects the wrong cédula looking at the photo: valid now, and no longer doubtful.
        $bad = DB::table('event_import_rows')->where('import_id', $id)->where('status', 'error')->value('id');
        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$bad}", ['cedula' => '1098000005'])
            ->assertOk()->assertJsonPath('data.fila.status', 'valida')->assertJsonPath('data.fila.confianza.cedula', 1);
        // A row without signature cannot be changed (only the sheet counts).
        $unsigned = DB::table('event_import_rows')->where('import_id', $id)->where('status', 'no_autorizo')->value('id');
        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$unsigned}", ['decision' => 'importar'])
            ->assertUnprocessable();

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", [])
            ->assertUnprocessable()->assertJsonValidationErrors('declaracion');
        $this->assertSame(0, DB::table('event_attendees')->count());

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])
            ->assertOk()->assertJsonPath('data.nuevos', 4)->assertJsonPath('data.ya_estaban', 0);

        $attendees = DB::table('event_attendees')->where('event_id', $this->event->id)->get();
        $this->assertCount(4, $attendees);
        $this->assertTrue($attendees->every(fn ($a) => $a->source === 'ocr' && $a->import_id === $id && (int) $a->autoriza_datos === 1 && $a->consent_id));
        $this->assertSame($known, $attendees->firstWhere('cedula', '1098000002')->lider_id);
        $this->assertNull($attendees->firstWhere('cedula', '63000003'), 'without signature nobody is registered');
        $this->assertSame(['operador'], DB::table('data_consents')->distinct()->pluck('channel')->all());
        $this->assertSame('confirmado', DB::table('event_imports')->where('id', $id)->value('status'));
        $this->assertCount(2, Storage::disk('local')->files("eventos/ocr/{$this->event->id}/{$id}"), 'the photos stay as evidence');

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}?source=ocr")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('kpis.ocr', 4)->where('asistentes.total', 4));
    }

    public function test_a_page_of_another_event_is_refused(): void
    {
        $other = $this->makeEvent(Campaign::DEFAULT_ID, ['nombre' => 'Otro evento']);

        // The QR says another event: refused before paying for the AI.
        $id = $this->upload($this->photo('ajena.png', $other->id))->assertCreated()->json('data.id');
        $this->process($id)->assertOk()->assertJsonPath('data.listo', true)->assertJsonPath('data.rechazadas', 1);
        $this->assertSame([], $this->fake->calls);
        $page = DB::table('event_import_pages')->where('import_id', $id)->first();
        $this->assertSame('rechazada', $page->status);
        $this->assertStringContainsString('es de otro evento (código '.strtoupper(substr($other->id, 0, 8)).')', $page->message);
        $this->assertSame(0, DB::table('event_import_rows')->where('import_id', $id)->count());

        // No QR, but the AI reads the other event's id on the trace line: refused, rows not stored.
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1]], ['evento_id' => $other->id]));
        $id2 = $this->upload($this->photo('ajena2.png', null, 3))->assertCreated()->json('data.id');
        $this->process($id2)->assertOk()->assertJsonPath('data.rechazadas', 1);
        $this->assertCount(1, $this->fake->calls);
        $this->assertSame(0, DB::table('event_import_rows')->where('import_id', $id2)->count());

        // A code of 8 characters of this event counts as this event.
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1]], ['codigo_evento' => strtoupper(substr($this->event->id, 0, 8))]));
        $id3 = $this->upload($this->photo('propia.png', null, 4))->assertCreated()->json('data.id');
        $this->process($id3)->assertOk()->assertJsonPath('data.procesadas', 1);
        $this->assertSame('ia', DB::table('event_import_pages')->where('import_id', $id3)->value('qr_origin'));
    }

    public function test_an_authorization_below_the_threshold_is_not_importable(): void
    {
        config(['eventos.ocr.threshold' => 0.85]);
        $this->fake->push(FakeOcrClient::sheet([
            ['numero' => 1, 'cedula' => '1098000001', 'confianza' => ['autorizo' => 0.6]],
            ['numero' => 2, 'cedula' => '1098000002', 'confianza' => ['firma_presente' => 0.84]],
            ['numero' => 3, 'cedula' => '1098000003', 'autorizo' => false],
            ['numero' => 4, 'cedula' => '1098000004', 'confianza' => ['autorizo' => 0.85, 'firma_presente' => 0.9, 'barrio' => 0.2]],
        ]));
        $id = $this->loadAndRead([$this->photo()]);

        $rows = DB::table('event_import_rows')->where('import_id', $id)->orderBy('row_number')->get();
        $this->assertSame(['no_autorizo', 'no_autorizo', 'no_autorizo', 'valida'], $rows->pluck('status')->all());
        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr/{$id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filas.data.0.motivo', 'La IA no está segura de la casilla «Autorizo» o de la firma: la persona no se importa.')
                ->where('filas.data.2.motivo', 'La IA no vio marcada la casilla «Autorizo»: la persona no se importa.')
                ->where('paginas.0.dudosas', 1)
                ->where('ocr.umbral', 0.85));

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])
            ->assertOk()->assertJsonPath('data.nuevos', 1);
        $this->assertSame(['1098000004'], DB::table('event_attendees')->pluck('cedula')->all());
    }

    public function test_a_failed_page_can_be_retried_or_skipped(): void
    {
        $this->fake->push(new OcrException('El proveedor de IA está ocupado. Espere un minuto y vuelva a intentar con esta página.', true));
        $id = $this->upload($this->photo('a.png', null, 1))->json('data.id');
        $this->upload($this->photo('b.png', null, 2), $id)->assertCreated();

        $this->process($id)->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'El proveedor de IA está ocupado. Espere un minuto y vuelva a intentar con esta página.')
            ->assertJsonPath('data.errores', 1);
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1, 'cedula' => '1098000001']]));
        $this->process($id)->assertOk()->assertJsonPath('data.listo', false)->assertJsonPath('data.pendientes', 0);

        $this->fake->push(FakeOcrClient::sheet([['numero' => 2, 'cedula' => '1098000002']]));
        $this->process($id, ['reintentar' => true])->assertOk()->assertJsonPath('data.listo', true)->assertJsonPath('data.errores', 0);
        $this->assertSame(2, DB::table('event_import_rows')->where('import_id', $id)->count());

        // Skipping: the failed page stays out and the load goes to review.
        $this->fake->push(new OcrException('No hubo conexión con el proveedor de IA. Vuelva a intentar en un momento.', true));
        $id2 = $this->upload($this->photo('c.png', null, 3))->json('data.id');
        $this->process($id2)->assertJsonPath('success', false);
        $this->process($id2, ['continuar' => true])->assertOk()->assertJsonPath('data.listo', true);
        $this->assertSame('revisado', DB::table('event_imports')->where('id', $id2)->value('status'));
    }

    public function test_bad_or_repeated_files_are_refused(): void
    {
        $photo = $this->photo('igual.png');
        $copy = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        copy($photo->getPathname(), $copy);
        $id = $this->upload($photo)->assertCreated()->json('data.id');
        $this->upload(new UploadedFile($copy, 'igual-otra-vez.png', 'image/png', null, true), $id)
            ->assertUnprocessable()->assertJsonPath('errors.foto.0', 'Esta foto ya se subió a este evento (igual.png): no se vuelve a leer.');

        $text = tempnam(sys_get_temp_dir(), 'ocr').'.jpg';
        file_put_contents($text, 'no soy una foto');
        $this->upload(new UploadedFile($text, 'falsa.jpg', 'image/jpeg', null, true))
            ->assertUnprocessable()->assertJsonPath('errors.foto.0', 'Solo se aceptan fotos JPG, PNG o WEBP, o un PDF.');
        $this->upload(UploadedFile::fake()->create('foto.heic', 100, 'image/heic'))->assertUnprocessable();
        $this->upload(UploadedFile::fake()->create('grande.jpg', 10241, 'image/jpeg'))
            ->assertUnprocessable()->assertJsonValidationErrors('foto');

        config(['eventos.ocr.max_pages' => 2]);
        $this->upload($this->photo('dos.png', null, 2), $id)->assertCreated();
        $this->upload($this->photo('tres.png', null, 3), $id)->assertUnprocessable()
            ->assertJsonPath('errors.foto.0', 'Una carga puede tener máximo 2 páginas. Termine esta y haga otra carga con el resto.');
    }

    public function test_a_pdf_page_count_and_one_request_per_page(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'ocr').'.pdf';
        copy(base_path('docs/ocr-piloto-ejemplo/planilla-1.pdf'), $pdf);
        $this->fake->push(fn ($mime, $page) => FakeOcrClient::sheet([['numero' => 1, 'cedula' => '1098000001']]));
        $id = $this->upload(new UploadedFile($pdf, 'planillas.pdf', 'application/pdf', null, true))
            ->assertCreated()->assertJsonPath('data.paginas', 1)->json('data.id');
        $this->process($id)->assertOk()->assertJsonPath('data.listo', true);
        $this->assertSame([['mime' => 'application/pdf', 'page' => 1]], array_map(fn ($c) => array_intersect_key($c, ['mime' => 1, 'page' => 1]), $this->fake->calls));
    }

    public function test_isolation_permissions_and_the_daily_limit(): void
    {
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1]]));
        $id = $this->loadAndRead([$this->photo()]);
        $paths = [
            ['getJson', "/eventos/{$this->event->id}/importar/ocr"],
            ['getJson', "/eventos/{$this->event->id}/importar/ocr/{$id}"],
            ['postJson', "/eventos/{$this->event->id}/importar/ocr/{$id}/procesar"],
        ];

        $other = $this->eventUser($this->campY, ['manage.eventos']);
        $sub = $this->eventUser($this->campX, ['manage.eventos']);
        $outside = $this->eventUser(Campaign::DEFAULT_ID, ['manage.eventos'], 'R02_ADMIN_FUNCIONAL', [$this->lebrija]);
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']);
        $operator = $this->eventUser(Campaign::DEFAULT_ID, ['register.asistentes']);
        foreach ($paths as [$method, $url]) {
            $this->actingAs($other)->{$method}($url)->assertNotFound();
            $this->actingAs($outside)->{$method}($url)->assertForbidden();
            $this->actingAs($viewer)->{$method}($url)->assertForbidden();
            $this->actingAs($operator)->{$method}($url)->assertForbidden();
        }
        // A visible subcampaign's event: read only (403), even with its switch on; its own user may.
        DB::table('campaigns')->where('id', $this->campX)->update(['ocr_enabled' => true]);
        $subEvent = $this->makeEvent($this->campX, ['nombre' => 'Evento de la subcampaña']);
        $this->actingAs($this->manager)->getJson("/eventos/{$subEvent->id}/importar/ocr")->assertForbidden();
        $this->actingAs($sub)->getJson("/eventos/{$this->event->id}/importar/ocr")->assertNotFound();
        $this->actingAs($sub)->get("/eventos/{$subEvent->id}/importar/ocr")->assertOk();

        // Daily cost guard per campaign.
        config(['eventos.ocr.max_pages_per_day' => 1]);
        $next = $this->upload($this->photo('otra.png', null, 9))->json('data.id');
        $this->process($next)->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'La campaña ya hizo 1 lecturas con IA hoy, el máximo diario. Continúe mañana.');
    }

    public function test_the_photos_are_private(): void
    {
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1]]));
        $id = $this->loadAndRead([$this->photo()]);
        $page = DB::table('event_import_pages')->where('import_id', $id)->first();
        $url = "/eventos/{$this->event->id}/importar/ocr/{$id}/paginas/{$page->id}/archivo";

        $this->assertStringStartsWith('eventos/ocr/', $page->file_path);
        Auth::forgetGuards();
        $this->get($url)->assertRedirect('/login');
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->eventUser($this->campY, ['manage.eventos']))->getJson($url)->assertNotFound();
        $this->actingAs($this->eventUser(Campaign::DEFAULT_ID, ['view.eventos']))->getJson($url)->assertForbidden();
        $this->actingAs($this->manager)->getJson("/eventos/{$this->event->id}/importar/ocr/{$id}/paginas/".Str::uuid().'/archivo')->assertNotFound();

        $response = $this->actingAs($this->manager)->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_audit_never_stores_what_was_read(): void
    {
        $this->fake->push(FakeOcrClient::sheet([
            ['numero' => 1, 'nombres' => 'ZACARÍAS', 'apellidos' => 'SECRETO URIBE', 'cedula' => '1077777777', 'celular' => '3177777777', 'barrio' => 'BARRIO OCULTO'],
        ]));
        $id = $this->loadAndRead([$this->photo()]);
        $row = DB::table('event_import_rows')->where('import_id', $id)->value('id');
        $this->actingAs($this->manager)->patchJson("/eventos/{$this->event->id}/importar/{$id}/filas/{$row}", ['apellidos' => 'SECRETO URIBE DOS'])->assertOk();
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])->assertOk();

        $logs = DB::table('audit_logs')->get();
        $all = $logs->map(fn ($l) => $l->metadata.$l->before_state.$l->after_state)->implode(' ');
        foreach (['ZACAR', 'SECRETO', '1077777777', '3177777777', 'OCULTO', '7777'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $all);
        }
        $read = json_decode($logs->firstWhere('action', 'EVENTO_OCR_PAGINA_LEIDA')->metadata, true);
        $this->assertSame(['event_id', 'pagina', 'resultado', 'model', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'duration_ms', 'filas', 'qr', 'qr_origen'], array_keys($read));
        $this->assertSame(3000, $read['input_tokens']);
        $this->assertSame(OcrSettings::cost(config('eventos.ocr.model'), 3000, 1500), $read['cost_usd']);
        $this->assertNotNull($logs->firstWhere('action', 'EVENTO_OCR_LECTURA_TERMINADA'));
        $this->assertNotNull($logs->firstWhere('action', 'EVENTO_OCR_ARCHIVO_SUBIDO'));
        $this->assertSame('ocr', json_decode($logs->firstWhere('action', 'EVENTO_IMPORTACION_CONFIRMADA')->metadata, true)['tipo']);
    }

    public function test_cleanup_deletes_unconfirmed_photos_and_keeps_confirmed_ones(): void
    {
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1, 'cedula' => '1098000001']]), FakeOcrClient::sheet([['numero' => 1, 'cedula' => '1098000002']]));
        $old = $this->loadAndRead([$this->photo('vieja.png', null, 1)]);
        $kept = $this->loadAndRead([$this->photo('confirmada.png', null, 2)]);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$kept}/confirmar", ['declaracion' => true])->assertOk();
        DB::table('event_imports')->update(['created_at' => now()->subDays(8), 'confirmed_at' => now()->subDays(31)]);

        $this->artisan('eventos:limpiar-importaciones')->assertSuccessful();

        $this->assertFalse(DB::table('event_imports')->where('id', $old)->exists());
        $this->assertSame([], Storage::disk('local')->allFiles("eventos/ocr/{$this->event->id}/{$old}"));
        $this->assertSame(0, DB::table('event_import_pages')->where('import_id', $old)->count());
        $this->assertCount(1, Storage::disk('local')->files("eventos/ocr/{$this->event->id}/{$kept}"));
        $this->assertNull(DB::table('event_import_rows')->where('import_id', $kept)->value('mapped'));
    }

    public function test_discarding_an_unconfirmed_load_deletes_its_photos(): void
    {
        $id = $this->upload($this->photo())->json('data.id');
        $this->actingAs($this->manager)->deleteJson("/eventos/{$this->event->id}/importar/{$id}")->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('eventos/ocr'));
        $this->assertSame(0, DB::table('event_import_pages')->count());
    }

    public function test_the_answer_is_cleaned_and_the_real_client_needs_a_key(): void
    {
        $data = FakeOcrClient::sheet([
            ['numero' => 3, 'cedula' => '1098000003', 'confianza' => ['cedula' => 1.7, 'celular' => -2]],
            ['numero' => 3, 'cedula' => '1098000004'],
            ['numero' => 7, 'nombres' => null, 'apellidos' => null, 'cedula' => null, 'celular' => null, 'fecha_nacimiento' => null,
                'direccion' => null, 'barrio' => null, 'autorizo' => false, 'firma_presente' => false],
        ]);
        $rows = PlanillaPrompt::rows($data);
        $this->assertSame([1, 3], array_column($rows, 'numero'), 'a repeated number takes a free one; an empty row is dropped');
        $this->assertSame(1.0, $rows[1]['confianza']['cedula']);
        $this->assertSame(0.0, $rows[1]['confianza']['celular']);

        $schema = PlanillaPrompt::schema();
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['filas']['items']['additionalProperties']);
        $this->assertFalse($schema['properties']['filas']['items']['properties']['cedula']['additionalProperties']);

        config(['eventos.ocr.api_key' => '']);
        $this->expectExceptionMessage(OcrSettings::MISSING_KEY);
        (new AnthropicOcrClient)->read('x', 'image/png', 1, 'claude-opus-5-5');
    }

    public function test_the_campaign_switch_command(): void
    {
        $this->switches(true, false);
        $this->artisan('eventos:ocr-campana', ['campaign' => 'centro-democratico', '--activar' => true])->assertSuccessful();
        $this->assertTrue((bool) DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->value('ocr_enabled'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_OCR_CAMPANA_ACTIVADA')->count());
        $this->artisan('eventos:ocr-campana', ['campaign' => 'centro-democratico', '--desactivar' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->value('ocr_enabled'));
        $this->artisan('eventos:ocr-campana', ['campaign' => 'no-existe'])->assertFailed();
    }

    public function test_the_pilot_compares_with_the_truth_without_touching_the_events_tables(): void
    {
        // The fake reads the synthetic sheets: all right except one cédula (with high confidence) and a missing row.
        $sheets = OcrPilotSample::sheets();
        $answer = function (int $sheet) use ($sheets) {
            $rows = [];
            foreach ($sheets[$sheet] as $n => $p) {
                if ($sheet === 2 && $n === 5) {
                    continue;
                }
                $rows[] = ['numero' => $n, 'nombres' => $p['nombres'], 'apellidos' => $p['apellidos'],
                    'cedula' => $sheet === 1 && $n === 1 ? '1098000071' : $p['cedula'], 'celular' => $p['celular'], 'fijo' => $p['fijo'] ?: null,
                    'fecha_nacimiento' => $p['fecha_nacimiento'], 'direccion' => $p['direccion'], 'barrio' => $p['barrio'],
                    'autorizo' => $p['autorizo'], 'firma_presente' => $p['firma']];
            }

            return FakeOcrClient::sheet($rows, ['pagina' => $sheet]);
        };
        $this->fake->push($answer(1), $answer(2));
        $json = tempnam(sys_get_temp_dir(), 'pilot').'.json';
        $before = DB::table('audit_logs')->count();

        $this->artisan('eventos:ocr-piloto', [
            'carpeta' => base_path('docs/ocr-piloto-ejemplo'), 'verdad' => base_path('docs/ocr-piloto-ejemplo/verdad.csv'),
            '--model' => 'claude-sonnet-5-5', '--json' => $json, '--force' => true,
        ])->expectsOutputToContain('Filas: 13 en la verdad · 12 detectadas · 1 omitidas · 0 sobrantes')->assertSuccessful();

        $report = json_decode(file_get_contents($json), true);
        $this->assertSame('claude-sonnet-5-5', $report['modelo']);
        $this->assertSame(['claude-sonnet-5-5', 'claude-sonnet-5-5'], array_column($this->fake->calls, 'model'));
        $this->assertSame(11, $report['campos']['cedula']['aciertos']);
        $this->assertSame(1, $report['campos']['cedula']['errores_sin_marcar']);
        $this->assertSame(1.0, (float) $report['campos']['celular']['precision']);
        $this->assertSame(1.0, (float) $report['campos']['autorizo']['precision']);
        $this->assertSame(1.0, (float) $report['campos']['firma']['precision']);
        $this->assertSame(OcrSettings::cost('claude-sonnet-5-5', 6000, 3000), $report['totales']['costo_usd']);
        $this->assertSame(0, DB::table('event_imports')->count());
        $this->assertSame(0, DB::table('event_import_pages')->count());
        $this->assertSame($before, DB::table('audit_logs')->count());

        config(['eventos.ocr.api_key' => '']);
        $this->artisan('eventos:ocr-piloto', ['carpeta' => base_path('docs/ocr-piloto-ejemplo'), 'verdad' => base_path('docs/ocr-piloto-ejemplo/verdad.csv'), '--force' => true])
            ->expectsOutputToContain('falta configurar la clave')->assertFailed();
    }
}
