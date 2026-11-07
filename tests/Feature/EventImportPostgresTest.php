<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// Opt-in: Excel imports of attendees on the real PostgreSQL schema (jsonb staging, CHECK constraints, source 'excel',
// append-only data_consents with the 'operador' channel) and the 2000-row budget.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral DB_URL= php artisan test --filter=Postgres
// Every change is rolled back by DatabaseTransactions.
class EventImportPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $mun;

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
        $prov = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $prov, 'type' => 'province', 'canonical_name' => 'Provincia Import PG']);
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Municipio Import PG', 'parent_id' => $prov]);

        $body = 'Texto aprobado import PG '.Str::random(8);
        (new ConsentText)->forceFill([
            'campaign_id' => Campaign::DEFAULT_ID, 'version' => 'pg-import', 'cuerpo' => $body, 'sha256' => hash('sha256', $body),
            'vigente_desde' => now()->addMinute(), 'is_active' => true, 'approved' => true,
        ])->save();

        $this->manager = User::create([
            'name' => 'Import PG', 'email' => 'import-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => ['manage.eventos'],
        ]);
        $this->manager->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $this->event = new CampaignEvent([
            'event_type_id' => DB::table('event_types')->whereNull('campaign_id')->where('nombre', 'Gira')->value('id'),
            'nombre' => 'Gira import PG', 'starts_at' => now()->subDay(), 'sitio_encuentro' => 'Parque', 'municipio_id' => $this->mun,
        ]);
        $this->event->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'status' => 'cerrado', 'public_token' => Str::random(43)])->save();
    }

    private function file(int $count, int $offset = 0, bool $allYes = false): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Asistentes');
        $sheet->fromArray(['Nombres', 'Apellidos', 'Cédula', 'Celular', 'Teléfono fijo', 'Fecha de nacimiento (DD/MM/AAAA)', 'Dirección', 'Barrio', 'Autorizó (SÍ/NO)'], null, 'A1');
        for ($i = 1; $i <= $count; $i++) {
            $n = $i + $offset;
            $r = $i + 1;
            $sheet->setCellValueExplicit("A{$r}", "Zzimport{$n}", DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$r}", 'Prueba Ñandú', DataType::TYPE_STRING);
            $sheet->setCellValue("C{$r}", 1190000000 + $n);
            $sheet->setCellValueExplicit("D{$r}", '3'.str_pad((string) $n, 9, '0', STR_PAD_LEFT), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$r}", '02/04/1980', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$r}", 'San Martín', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$r}", $allYes || $n % 2 ? 'SÍ' : 'NO', DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'pgimp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'asistentes.xlsx', null, null, true);
    }

    private function run3(UploadedFile $file, bool $declared): array
    {
        $id = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar", ['archivo' => $file])->assertCreated()->json('data.id');
        $mapping = json_decode(DB::table('event_imports')->where('id', $id)->value('column_mapping'), true);
        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/importar/{$id}/columnas", ['columnas' => $mapping])->assertOk();
        $result = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar", ['declaracion' => $declared])
            ->assertOk()->json('data');

        return [$id, $result];
    }

    // Since 2026-11-07 «Autorizó» is ignored and nothing goes to data_consents (§4 of docs/eventos-api.md).
    public function test_excel_attendees_pass_the_constraints_without_any_consent(): void
    {
        [$id, $result] = $this->run3($this->file(4), false);
        $this->assertSame(4, $result['nuevos'], 'Every valid row, whatever «Autorizó» says');
        $this->assertSame(4, DB::table('event_attendees')->where('import_id', $id)->where('autoriza_datos', false)->where('link_status', 'pendiente')->count());

        $this->assertSame(4, DB::table('event_attendees')->where('event_id', $this->event->id)->where('source', 'excel')->count());
        $this->assertSame(0, DB::table('data_consents')->where('event_id', $this->event->id)->count());
        $this->assertSame('1190000001', DB::table('event_attendees')->where('event_id', $this->event->id)->orderBy('cedula')->value('cedula'));
        $this->assertSame('Prueba Ñandú', json_decode(DB::table('event_import_rows')->where('import_id', $id)->orderBy('row_number')->value('mapped'), true)['apellidos']);

        $again = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")->json('data');
        $this->assertSame(0, $again['nuevos']);

        try {
            DB::transaction(fn () => DB::table('event_imports')->where('id', $id)->update(['status' => 'otro']));
            $this->fail('The status check should reject it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('event_imports_status_check', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('event_attendees')->where('event_id', $this->event->id)->limit(1)->update(['source' => 'fax']));
            $this->fail('The source check should reject it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('event_attendees_source_check', $e->getMessage());
        }
    }

    public function test_two_thousand_rows_on_postgresql(): void
    {
        $file = $this->file(2000, 100, true);
        memory_reset_peak_usage();
        $start = microtime(true);
        $id = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar", ['archivo' => $file])->assertCreated()->json('data.id');
        $upload = microtime(true) - $start;
        $mapping = json_decode(DB::table('event_imports')->where('id', $id)->value('column_mapping'), true);
        $this->actingAs($this->manager)->putJson("/eventos/{$this->event->id}/importar/{$id}/columnas", ['columnas' => $mapping])
            ->assertOk()->assertJsonPath('data.counts.a_importar', 2000);
        $review = microtime(true) - $start - $upload;
        $result = $this->actingAs($this->manager)->postJson("/eventos/{$this->event->id}/importar/{$id}/confirmar")
            ->assertOk()->json('data');
        $confirm = microtime(true) - $start - $upload - $review;
        $peakMb = memory_get_peak_usage(true) / 1048576;

        fwrite(STDERR, sprintf("\n[2000 filas PostgreSQL] subir %.1f s, revisar %.1f s, confirmar %.1f s; pico de memoria %.0f MB\n", $upload, $review, $confirm, $peakMb));
        $this->assertSame(2000, $result['nuevos']);
        $this->assertSame(2000, DB::table('event_attendees')->where('event_id', $this->event->id)->where('link_status', 'pendiente')->count());
        $this->assertSame(0, DB::table('data_consents')->where('event_id', $this->event->id)->count());
        $this->assertLessThan(200, $peakMb);
        $this->assertLessThan(30, $upload + $review + $confirm);
    }
}
