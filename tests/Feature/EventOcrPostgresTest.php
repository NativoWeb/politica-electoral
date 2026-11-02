<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\Role;
use App\Models\User;
use App\Services\Eventos\Ocr\OcrClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeOcrClient;
use Tests\TestCase;

// Opt-in: the AI reading of printed sheets on the real PostgreSQL schema (CHECK of event_attendees.source with 'ocr',
// event_imports.kind, event_import_pages.status, jsonb confidence, append-only data_consents with 'operador').
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral DB_URL= php artisan test --filter=Postgres
// The API is never called (FakeOcrClient). Every change is rolled back by DatabaseTransactions.
class EventOcrPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        Storage::fake('local');
        config(['eventos.ocr.enabled' => true, 'eventos.ocr.api_key' => 'sk-test']);
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['ocr_enabled' => true]);

        $mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $mun, 'type' => 'municipality', 'canonical_name' => 'Municipio OCR PG']);
        $body = 'Texto aprobado OCR PG '.Str::random(8);
        (new ConsentText)->forceFill([
            'campaign_id' => Campaign::DEFAULT_ID, 'version' => 'pg-ocr', 'cuerpo' => $body, 'sha256' => hash('sha256', $body),
            'vigente_desde' => now()->addMinute(), 'is_active' => true, 'approved' => true,
        ])->save();

        $this->manager = User::create([
            'name' => 'OCR PG', 'email' => 'ocr-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => ['manage.eventos'],
        ]);
        $this->manager->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $this->event = new CampaignEvent([
            'event_type_id' => DB::table('event_types')->whereNull('campaign_id')->where('nombre', 'Gira')->value('id'),
            'nombre' => 'Gira OCR PG', 'starts_at' => now()->subDay(), 'sitio_encuentro' => 'Parque', 'municipio_id' => $mun,
        ]);
        $this->event->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'status' => 'cerrado', 'public_token' => Str::random(43)])->save();
    }

    public function test_read_and_confirm_on_postgresql(): void
    {
        $fake = (new FakeOcrClient)->push(FakeOcrClient::sheet([
            ['numero' => 1, 'nombres' => 'ZZOCR UNO', 'cedula' => '1195000001', 'celular' => '3195000001', 'confianza' => ['apellidos' => 0.4]],
            ['numero' => 2, 'nombres' => 'ZZOCR DOS', 'cedula' => '1195000002', 'celular' => '3195000002', 'firma_presente' => false],
        ]));
        $this->app->instance(OcrClient::class, $fake);

        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        $path = tempnam(sys_get_temp_dir(), 'ocrpg').'.png';
        imagepng($image, $path);

        $id = $this->actingAs($this->manager)->post("/eventos/{$this->event->id}/importar/ocr", ['foto' => new UploadedFile($path, 'pg.png', 'image/png', null, true)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/ocr/{$id}/procesar")->assertOk()->assertJsonPath('data.listo', true);
        $this->assertEqualsWithDelta(0.4, (float) DB::table('event_import_rows')->where('import_id', $id)->where('page_row', 1)->value(DB::raw("(confidence->>'apellidos')::numeric")), 0.001);

        $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => true])
            ->assertOk()->assertJsonPath('data.nuevos', 1);

        $attendee = DB::table('event_attendees')->where('event_id', $this->event->id)->first();
        $this->assertSame('ocr', $attendee->source);
        $this->assertSame('operador', DB::table('data_consents')->where('attendee_id', $attendee->id)->value('channel'));

        $this->expectException(QueryException::class);
        DB::table('event_import_pages')->where('import_id', $id)->update(['status' => 'leyendo']);
    }

    public function test_the_checks_refuse_unknown_kinds(): void
    {
        $this->expectException(QueryException::class);
        DB::table('event_imports')->insert([
            'id' => (string) Str::uuid(), 'event_id' => $this->event->id, 'campaign_id' => Campaign::DEFAULT_ID, 'kind' => 'fax',
            'original_name' => 'x', 'file_path' => 'x', 'file_sha256' => str_repeat('a', 64), 'headers' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
