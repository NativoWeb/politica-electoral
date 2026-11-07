<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\User;
use App\Services\Eventos\Ocr\OcrClient;
use App\Services\Eventos\Ocr\PageImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsEventosScenario;
use Tests\Support\FakeOcrClient;
use Tests\TestCase;

// Phase 5 after QA: memory of the image preparation at the pixel limit, a local failure spends no attempt,
// messages of the OCR confirmation and of an empty reading, and what counts as "doubtful".
class EventOcrQaFixesTest extends TestCase
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

    private function upload(UploadedFile $file)
    {
        return $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/importar/ocr", ['foto' => $file], ['Accept' => 'application/json']);
    }

    private function process(string $id, array $body = [])
    {
        return $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/ocr/{$id}/procesar", $body);
    }

    private static function pngHeader(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress('')).$chunk('IEND', '');
    }

    // Worst case at the limit: a 24 MP JPEG decoded, scaled, ROTATED (as if EXIF said so) and reduced for the QR, in a
    // separate PHP with the 256 MB of a reading request. Its peak must leave a wide margin. Only meaningful with PHP's
    // bundled GD, whose pixels count in memory_limit; a system GD (production: libgd 2.3.3) allocates them outside it.
    public function test_preparing_a_photo_at_the_pixel_limit_fits_in_the_memory_of_a_request(): void
    {
        $this->assertSame(24_000_000, PageImage::MAX_PIXELS);
        $gd = (string) (gd_info()['GD Version'] ?? '');
        if (! str_starts_with($gd, 'bundled')) {
            $this->markTestSkipped("GD del sistema ({$gd}): reserva los píxeles fuera de memory_limit y memory_get_peak_usage no los mide (ver §13).");
        }

        $dir = sys_get_temp_dir();
        $jpgBase = tempnam($dir, 'mp');
        $scriptBase = tempnam($dir, 'mem');
        $jpg = $jpgBase.'.jpg';
        $script = $scriptBase.'.php';
        try {
            $make = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', '$i=imagecreatetruecolor(6000,4000);'
                .'for($k=0;$k<400;$k++){imagefilledrectangle($i,mt_rand(0,6000),mt_rand(0,4000),mt_rand(0,6000),mt_rand(0,4000),imagecolorallocate($i,mt_rand(0,255),mt_rand(0,255),mt_rand(0,255)));}'
                .'imagejpeg($i,$argv[1],90);', $jpg]);
            $make->setTimeout(120)->mustRun();

            file_put_contents($script, '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'
                .'$app = require '.var_export(base_path('bootstrap/app.php'), true).';'
                .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
                .'$c = file_get_contents($argv[1]); gc_collect_cycles(); $base = memory_get_peak_usage(true);'
                .'[[$api, $mime], $qr] = App\Services\Eventos\Ocr\PageImage::prepare($c, "image/jpeg", 90);'
                .'echo json_encode(["base" => $base, "peak" => memory_get_peak_usage(true), "mime" => $mime, "bytes" => strlen($api)]);');
            $run = new Process([PHP_BINARY, '-d', 'memory_limit=256M', $script, $jpg], base_path());
            $run->setTimeout(120)->mustRun();
            $result = json_decode($run->getOutput(), true);
        } finally {
            foreach ([$jpgBase, $jpg, $scriptBase, $script] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertLessThanOrEqual(3_700_000, $result['bytes']);
        $used = ($result['peak'] - $result['base']) / 1048576;
        $this->assertLessThan(130, $used, "preparing used {$used} MB");
        $this->assertLessThan(200, $result['peak'] / 1048576, 'peak of the whole process under 200 of the 256 MB');
        $this->assertFalse(is_file($jpg) || is_file($script) || is_file($jpgBase) || is_file($scriptBase));
    }

    public function test_the_pixel_limit_is_24_megapixels(): void
    {
        $this->upload(new UploadedFile($this->write(self::pngHeader(5000, 5000)), 'g.png', 'image/png', null, true))
            ->assertUnprocessable()->assertJsonPath('errors.foto.0', 'La foto tiene demasiada resolución para leerla (más de 24 megapíxeles). Tómela de nuevo con menos resolución.');
        $this->assertSame('image/png', PageImage::mime($this->write(self::pngHeader(6000, 4000))));
    }

    public function test_a_photo_that_fails_to_prepare_spends_no_attempt_nor_the_daily_cap(): void
    {
        $id = $this->upload($this->photo())->json('data.id');
        $page = DB::table('event_import_pages')->where('import_id', $id)->first();
        Storage::disk('local')->put($page->file_path, 'ya no es una imagen');

        $this->process($id)->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'La imagen está dañada o no se puede abrir. Tómela de nuevo.');
        $page = DB::table('event_import_pages')->where('id', $page->id)->first();
        $this->assertSame('error', $page->status);
        $this->assertSame(0, (int) $page->api_calls);
        $this->assertNull($page->last_call_at);
        $this->assertSame([], $this->fake->calls);
    }

    public function test_confirming_an_ocr_load_needs_no_approved_text(): void
    {
        $this->fake->push(FakeOcrClient::sheet([['numero' => 1]]));
        $id = $this->upload($this->photo())->json('data.id');
        $this->process($id)->assertJsonPath('data.listo', true);
        DB::table('consent_texts')->update(['is_active' => false]);

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertOk()->assertJsonPath('data.nuevos', 1);
        $this->assertSame(1, DB::table('event_attendees')->count());
        $this->assertSame(0, DB::table('data_consents')->count());
    }

    public function test_the_end_of_an_empty_reading_says_so(): void
    {
        $this->fake->push(FakeOcrClient::sheet([]));
        $id = $this->upload($this->photo(1))->json('data.id');
        $this->process($id)->assertJsonPath('data.listo', true)
            ->assertJsonPath('message', 'Se leyeron las páginas pero no tienen filas escritas. Nada se registró.');

        $this->fake->push(FakeOcrClient::sheet([], ['es_planilla' => false]));
        $id2 = $this->upload($this->photo(2))->json('data.id');
        $this->process($id2)->assertJsonPath('data.listo', true)
            ->assertJsonPath('message', 'No se pudo leer ninguna página: revise el aviso de cada página. Nada se registró.');
    }

    public function test_doubts_in_autorizo_or_firma_are_not_counted_as_doubtful(): void
    {
        $this->fake->push(FakeOcrClient::sheet([
            ['numero' => 1, 'cedula' => '1098000001', 'autorizo' => false, 'confianza' => ['apellidos' => 0.3]],
            ['numero' => 2, 'cedula' => '1098000002', 'confianza' => ['autorizo' => 0.5]],
            ['numero' => 3, 'cedula' => '1098000003', 'confianza' => ['barrio' => 0.4]],
        ]));
        $id = $this->upload($this->photo())->json('data.id');
        $this->process($id)->assertJsonPath('data.listo', true);

        $this->actingAs($this->manager)->get("/eventos/{$this->event->id}/importar/ocr/{$id}")
            ->assertInertia(fn ($page) => $page->where('paginas.0.dudosas', 2));
    }

    private function write(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ocr');
        file_put_contents($path, $bytes);

        return $path;
    }
}
