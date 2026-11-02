<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Eventos\Ocr\AnthropicOcrClient;
use App\Services\Eventos\Ocr\OcrClient;
use App\Services\Eventos\Ocr\OcrException;
use App\Services\Eventos\Ocr\OcrImportService;
use App\Services\Eventos\Ocr\OcrResult;
use App\Services\Eventos\Ocr\OcrSettings;
use App\Services\Eventos\Ocr\PageImage;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsEventosScenario;
use Tests\Support\FakeOcrClient;
use Tests\TestCase;

// Phase 5 after review: oversized images and PDFs, concurrency, paid attempts and the daily cap, the API error
// mapping (with a mocked HTTP answer, never the real API), file headers, doubtful cédulas and data minimization.
class EventOcrHardeningTest extends TestCase
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
        config(['eventos.ocr.enabled' => true, 'eventos.ocr.api_key' => 'sk-test']);
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['ocr_enabled' => true]);
    }

    private function photo(int $seed = 1): UploadedFile
    {
        $image = imagecreatetruecolor(400, 300);
        imagestring($image, 5, 10, 10 + $seed, "Hoja {$seed}", imagecolorallocate($image, 255, 255, 255));
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, "hoja{$seed}.png", 'image/png', null, true);
    }

    private function file(string $content, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ocr');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function upload(UploadedFile $file, ?string $importId = null)
    {
        return $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/importar/ocr", array_filter(['foto' => $file, 'importacion' => $importId]), ['Accept' => 'application/json']);
    }

    private function process(string $id, array $body = [])
    {
        return $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/ocr/{$id}/procesar", $body);
    }

    // A valid PNG header declaring width x height, with no pixels: a few bytes that GD would expand to gigabytes.
    private static function pngHeader(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress('')).$chunk('IEND', '');
    }

    public function test_the_test_environment_never_has_a_key_nor_the_switch_on(): void
    {
        $this->assertEmpty(env('ANTHROPIC_API_KEY'));
        $this->assertFalse((bool) env('EVENTOS_OCR_ENABLED'));
    }

    public function test_an_image_with_too_many_pixels_is_refused_before_decoding_it(): void
    {
        $bytes = self::pngHeader(20000, 20000);
        $this->assertLessThan(200, strlen($bytes));
        $this->upload($this->file($bytes, 'gigante.png', 'image/png'))->assertUnprocessable()
            ->assertJsonPath('errors.foto.0', 'La foto tiene demasiada resolución para leerla (más de 24 megapíxeles). Tómela de nuevo con menos resolución.');
        $this->assertSame(0, DB::table('event_import_pages')->count());

        // 6000 x 4000 = 24 MP is still accepted by the header check.
        $path = tempnam(sys_get_temp_dir(), 'ocr');
        file_put_contents($path, self::pngHeader(6000, 4000));
        $this->assertSame('image/png', PageImage::mime($path));
    }

    public function test_a_pdf_without_countable_pages_or_too_heavy_is_refused(): void
    {
        $this->upload($this->file("%PDF-1.5\n1 0 obj << /Type /ObjStm /N 3 >> stream\nxx\nendstream endobj\n%%EOF", 'comprimido.pdf', 'application/pdf'))
            ->assertUnprocessable()->assertJsonPath('errors.foto.0', PageImage::PDF_UNREADABLE);

        $heavy = "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n".str_repeat('A', PageImage::MAX_PDF_BYTES_PER_PAGE + 1000)."\n%%EOF";
        // Checked directly: the upload limit of this PHP (2 MB) would refuse the file before.
        try {
            PageImage::pdfCheck($heavy);
            $this->fail('expected an OcrException');
        } catch (OcrException $e) {
            $this->assertSame('Este PDF pesa demasiado por página. Suba fotos de las páginas (JPG o PNG).', $e->getMessage());
        }
        $this->assertSame(1, PageImage::pdfCheck(file_get_contents(base_path('docs/ocr-piloto-ejemplo/planilla-1.pdf'))));
        $this->assertSame(0, DB::table('event_import_pages')->count());
    }

    public function test_a_page_read_twice_at_the_same_time_is_stored_once(): void
    {
        $id = $this->upload($this->photo())->json('data.id');
        $page = DB::table('event_import_pages')->where('import_id', $id)->value('id');

        // While this request waits for the AI, another one finishes the same page first.
        $this->fake->push(function () use ($page) {
            DB::table('event_import_pages')->where('id', $page)->update(['status' => 'procesada']);

            return FakeOcrClient::sheet([['numero' => 1], ['numero' => 2, 'cedula' => '1098000002']]);
        });
        $this->process($id)->assertOk();
        $this->assertSame(0, DB::table('event_import_rows')->where('import_id', $id)->count(), 'the late reading stores nothing');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_OCR_PAGINA_LEIDA')->where('metadata', 'like', '%descartada_ya_leida%')->count());

        // The database refuses a second copy of the same printed row of a page.
        $row = ['import_id' => $id, 'page_id' => $page, 'page_row' => 1, 'row_number' => 1, 'status' => 'pendiente', 'decision' => 'omitir', 'edited' => false];
        DB::table('event_import_rows')->insert(['id' => (string) Str::uuid()] + $row);
        $this->expectException(QueryException::class);
        DB::table('event_import_rows')->insert(['id' => (string) Str::uuid(), 'row_number' => 2] + $row);
    }

    public function test_a_second_request_while_the_lock_is_held_waits(): void
    {
        $id = $this->upload($this->photo())->json('data.id');
        $lock = Cache::lock('eventos-ocr|'.$id, 60);
        $this->assertTrue($lock->get());
        $this->process($id)->assertOk()->assertJsonPath('data.ocupado', true)->assertJsonPath('data.pendientes', 1);
        $this->assertSame([], $this->fake->calls);
        $lock->release();
        $this->assertGreaterThanOrEqual((int) config('eventos.ocr.timeout') + 60, OcrImportService::lockSeconds());
    }

    public function test_paid_failures_count_add_their_cost_and_stop_after_three_calls(): void
    {
        $billed = fn () => new OcrException('La respuesta de la IA quedó incompleta para esta página. Vuelva a intentar.', true, null,
            new OcrResult([], 'claude-opus-5-5', 2000, 16000));
        $this->fake->push($billed(), $billed(), $billed());
        $id = $this->upload($this->photo())->json('data.id');

        $this->process($id)->assertJsonPath('success', false)->assertJsonPath('data.errores', 1);
        $this->process($id, ['reintentar' => true])->assertJsonPath('success', false);
        $last = $this->process($id, ['reintentar' => true])->assertJsonPath('success', false)->assertJsonPath('data.rechazadas', 1);
        $this->assertStringContainsString(OcrImportService::TOO_MANY_CALLS, $last->json('message'));

        $page = DB::table('event_import_pages')->where('import_id', $id)->first();
        $this->assertSame('rechazada', $page->status);
        $this->assertSame(3, (int) $page->api_calls);
        $this->assertSame(48000, (int) $page->output_tokens);
        $this->assertEqualsWithDelta(3 * OcrSettings::cost('claude-opus-5-5', 2000, 16000), (float) $page->cost_usd, 0.00001);
        $audits = DB::table('audit_logs')->where('action', 'EVENTO_OCR_PAGINA_LEIDA')->pluck('metadata')->map(fn ($m) => json_decode($m, true));
        $this->assertSame([16000, 16000, 16000], $audits->pluck('output_tokens')->all());
        $this->assertSame(['error', 'error', 'rechazada'], $audits->pluck('resultado')->all());

        // Retrying does not bring it back: the page is refused for good.
        $this->process($id, ['reintentar' => true])->assertJsonPath('data.rechazadas', 1);
        $this->assertCount(3, $this->fake->calls);
    }

    public function test_the_daily_cap_counts_every_call_not_only_the_pages_read(): void
    {
        config(['eventos.ocr.max_pages_per_day' => 2]);
        $this->fake->push(new OcrException('No hubo conexión con el proveedor de IA. Vuelva a intentar en un momento.', true), new OcrException('No hubo conexión con el proveedor de IA. Vuelva a intentar en un momento.', true));
        $id = $this->upload($this->photo())->json('data.id');
        $this->process($id)->assertJsonPath('success', false);
        $this->process($id, ['reintentar' => true])->assertJsonPath('success', false);

        $this->process($id, ['reintentar' => true])->assertJsonPath('success', false)
            ->assertJsonPath('message', 'La campaña ya hizo 2 lecturas con IA hoy, el máximo diario. Continúe mañana.');
        $this->assertCount(2, $this->fake->calls);
    }

    public function test_api_errors_are_mapped_and_charged_answers_keep_their_tokens(): void
    {
        $mock = new MockHandler([
            new Response(404, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: x']])),
            new Response(422, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'x']])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
                'content' => [['type' => 'text', 'text' => '{"filas": [']], 'stop_reason' => 'max_tokens', 'stop_sequence' => null,
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 16000],
            ])),
        ]);
        $client = new AnthropicOcrClient(new Guzzle(['handler' => HandlerStack::create($mock)]));

        foreach ([1, 2] as $_) {
            try {
                $client->read('x', 'image/png', 1, 'claude-opus-5-5');
                $this->fail('expected an OcrException');
            } catch (OcrException $e) {
                $this->assertSame('La configuración del modelo de IA no es válida. Avise al administrador de la plataforma.', $e->getMessage());
                $this->assertFalse($e->retryable);
                $this->assertNull($e->billed);
            }
        }
        try {
            $client->read('x', 'image/png', 1, 'claude-opus-5-5');
            $this->fail('expected an OcrException');
        } catch (OcrException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(16000, $e->billed->outputTokens);
            $this->assertSame(1200, $e->billed->inputTokens);
        }
        $this->assertCount(0, $mock, 'one HTTP call per read: the SDK does not retry');
        $this->assertInstanceOf(AnthropicOcrClient::class, app()->make(AnthropicOcrClient::class));
    }

    public function test_prices_cover_the_fallback_model_and_warn_when_missing(): void
    {
        $this->assertNotNull(OcrSettings::prices('claude-opus-4-8'));
        $this->assertGreaterThan(0, OcrSettings::cost('claude-opus-4-8', 1000, 1000));
        Log::spy();
        $this->assertSame(0.0, OcrSettings::cost('modelo-desconocido', 1000, 1000));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'no tiene precio') && $context['model'] === 'modelo-desconocido');
    }

    public function test_a_photo_is_served_sandboxed_and_a_pdf_without_sandbox(): void
    {
        $id = $this->upload($this->photo())->json('data.id');
        $pdf = tempnam(sys_get_temp_dir(), 'ocr').'.pdf';
        copy(base_path('docs/ocr-piloto-ejemplo/planilla-1.pdf'), $pdf);
        $this->upload(new UploadedFile($pdf, 'p.pdf', 'application/pdf', null, true), $id)->assertCreated();
        $pages = DB::table('event_import_pages')->where('import_id', $id)->orderBy('sequence')->pluck('id');
        $url = fn ($page) => "/eventos/{$this->event->id}/importar/ocr/{$id}/paginas/{$page}/archivo";

        $image = $this->actingAs($this->manager)->get($url($pages[0]))->assertOk();
        $this->assertSame('sandbox', $image->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $image->headers->get('Cache-Control'));
        $doc = $this->actingAs($this->manager)->get($url($pages[1]))->assertOk();
        $this->assertSame('application/pdf', $doc->headers->get('Content-Type'));
        $this->assertNull($doc->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $doc->headers->get('X-Content-Type-Options'));
    }

    public function test_a_doubtful_cedula_starts_as_not_imported_until_corrected(): void
    {
        $this->fake->push(FakeOcrClient::sheet([
            ['numero' => 1, 'cedula' => '1098000001', 'confianza' => ['cedula' => 0.6]],
            ['numero' => 2, 'cedula' => '1098000002', 'confianza' => ['cedula' => 0.7]],
            ['numero' => 3, 'cedula' => '1098000003'],
        ]));
        $id = $this->upload($this->photo())->json('data.id');
        $this->process($id)->assertJsonPath('data.listo', true);
        $rows = DB::table('event_import_rows')->where('import_id', $id)->orderBy('page_row')->get()->keyBy('page_row');
        $this->assertSame(['omitir', 'omitir', 'crear'], [$rows[1]->decision, $rows[2]->decision, $rows[3]->decision]);
        $this->assertSame(1, json_decode(DB::table('event_imports')->where('id', $id)->value('counts'), true)['a_importar']);

        // Corrected → imported by default; the other one ticked by hand → kept after more corrections.
        $base = "/eventos/{$this->event->id}/importar/{$id}/filas/";
        $this->actingAs($this->manager)->patchJson($base.$rows[1]->id, ['cedula' => '1098000001'])->assertOk()->assertJsonPath('data.fila.decision', 'crear');
        $this->actingAs($this->manager)->patchJson($base.$rows[2]->id, ['decision' => 'importar'])->assertOk()->assertJsonPath('data.fila.decision', 'crear');
        $this->actingAs($this->manager)->patchJson($base.$rows[3]->id, ['barrio' => 'Otro barrio'])->assertOk();
        $this->assertSame('crear', DB::table('event_import_rows')->where('id', $rows[2]->id)->value('decision'));
        $this->assertSame(3, json_decode(DB::table('event_imports')->where('id', $id)->value('counts'), true)['a_importar']);
    }

    public function test_confirming_empties_what_was_read_of_people_who_did_not_authorize(): void
    {
        $this->fake->push(FakeOcrClient::sheet([
            ['numero' => 1, 'cedula' => '1098000001'],
            ['numero' => 2, 'nombres' => 'NO AUTORIZA', 'cedula' => '1098000002', 'autorizo' => false],
        ]));
        $id = $this->upload($this->photo())->json('data.id');
        $this->process($id)->assertJsonPath('data.listo', true);
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])
            ->assertOk()->assertJsonPath('data.nuevos', 1);

        $refused = DB::table('event_import_rows')->where('import_id', $id)->where('status', 'no_autorizo')->first();
        $this->assertNull($refused->raw);
        $this->assertNull($refused->mapped);
        $this->assertNotNull(DB::table('event_import_rows')->where('import_id', $id)->where('status', 'valida')->value('mapped'));
    }
}
