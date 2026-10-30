<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// ETL commands over lideres work on one campaign (--campaign) and never on the others.
class CampaignEtlCommandsTest extends TestCase
{
    use WithCampaignColumns;

    private string $campX;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('canonical_name');
            $t->uuid('parent_id')->nullable();
        });
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['nombre', 'provincia', 'municipio', 'cargo', 'tipo', 'telefono', 'cedula', 'email', 'observacion', 'barrio'] as $col) {
                $t->string($col)->nullable();
            }
            $t->uuid('geographic_unit_id')->nullable();
            $t->timestamps();
        });
        Schema::create('cargo_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->integer('display_order')->default(0);
            $t->boolean('is_highlighted')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $this->addCampaignColumns();

        $this->campX = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $this->campX, 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID]);
        DB::table('geographic_units')->insert(['id' => (string) Str::uuid(), 'type' => 'municipality', 'canonical_name' => 'Girón']);
    }

    protected function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function lider(string $campaignId, string $nombre, string $cargo = 'Líder'): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert(['id' => $id, 'campaign_id' => $campaignId, 'nombre' => $nombre, 'municipio' => 'Girón', 'cargo' => $cargo]);

        return $id;
    }

    private function directorioExcel(): string
    {
        $book = new Spreadsheet;
        $coord = $book->getActiveSheet()->setTitle('COORD Y DIRECTORIO');
        $coord->fromArray([['', 'PROVINCIA', 'MUNICIPIO', 'NOMBRE', 'CARGO', 'TEL', 'CEDULA', 'EMAIL'], ['']], null, 'A1');
        $coord->fromArray(['', 'Soto', 'Girón', 'NUEVA DEL EXCEL', 'COORDINADOR', '300', '9001', 'n@x.co'], null, 'A3');
        $veeduria = $book->createSheet()->setTitle('VEEDURIA');
        $veeduria->fromArray([['', 'PROVINCIA', 'MUNICIPIO', 'NOMBRE', 'CEDULA', 'TEL', 'OBS'], ['', 'Soto', 'Girón', 'VEEDOR DEL EXCEL', '9002', '301', '']], null, 'A1');

        $this->file = tempnam(sys_get_temp_dir(), 'directorio').'.xlsx';
        (new Xlsx($book))->save($this->file);

        return $this->file;
    }

    public function test_import_lideres_never_deletes_without_replace(): void
    {
        $cd = $this->lider(Campaign::DEFAULT_ID, 'Ana CD');
        $x = $this->lider($this->campX, 'Beto X');

        $this->artisan('import:lideres', ['file' => $this->directorioExcel(), '--campaign' => 'alejandro'])
            ->expectsOutputToContain('ya tiene 1 líderes')
            ->assertFailed();

        $this->assertDatabaseHas('lideres', ['id' => $cd]);
        $this->assertDatabaseHas('lideres', ['id' => $x]);
        $this->assertSame(2, DB::table('lideres')->count());
    }

    public function test_import_lideres_replace_asks_and_can_be_cancelled(): void
    {
        $x = $this->lider($this->campX, 'Beto X');

        $this->artisan('import:lideres', ['file' => $this->directorioExcel(), '--campaign' => 'alejandro', '--replace' => true])
            ->expectsConfirmation('Se borrarán los 1 líderes de esta campaña (y solo de esta). ¿Continuar?', 'no')
            ->assertFailed();

        $this->assertDatabaseHas('lideres', ['id' => $x]);
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_import_lideres_replace_only_touches_the_given_campaign(): void
    {
        $cd = $this->lider(Campaign::DEFAULT_ID, 'Ana CD');
        $x = $this->lider($this->campX, 'Beto X');

        $this->artisan('import:lideres', ['file' => $this->directorioExcel(), '--campaign' => 'alejandro', '--replace' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('lideres', ['id' => $cd, 'campaign_id' => Campaign::DEFAULT_ID]);
        $this->assertDatabaseMissing('lideres', ['id' => $x]);
        $this->assertSame(
            ['Nueva Del Excel', 'Veedor Del Excel'],
            DB::table('lideres')->where('campaign_id', $this->campX)->orderBy('nombre')->pluck('nombre')->all()
        );
    }

    public function test_import_lideres_defaults_to_the_root_campaign_when_it_is_empty(): void
    {
        $x = $this->lider($this->campX, 'Beto X');

        $this->artisan('import:lideres', ['file' => $this->directorioExcel()])->assertSuccessful();

        $this->assertSame(2, DB::table('lideres')->where('campaign_id', Campaign::DEFAULT_ID)->count());
        $this->assertDatabaseHas('lideres', ['id' => $x]);
    }

    public function test_an_unknown_campaign_stops_the_command(): void
    {
        $this->artisan('import:lideres', ['file' => $this->directorioExcel(), '--campaign' => 'no-existe'])
            ->expectsOutputToContain('Campaña no encontrada')
            ->assertFailed();
        $this->artisan('lideres:normalizar-cargos', ['--campaign' => 'no-existe'])->assertFailed();
    }

    public function test_normalizar_cargos_only_changes_the_given_campaign(): void
    {
        // Categories the command would otherwise create relying on PostgreSQL's uuid default.
        foreach (['Líder', 'Exconcejal', 'Exalcalde'] as $name) {
            DB::table('cargo_categories')->insert(['id' => (string) Str::uuid(), 'name' => $name]);
        }
        $cd = $this->lider(Campaign::DEFAULT_ID, 'Ana CD', 'lider centro');
        $x = $this->lider($this->campX, 'Beto X', 'lider centro');

        $this->artisan('lideres:normalizar-cargos', ['--campaign' => $this->campX])->assertSuccessful();

        $this->assertSame('Líder', DB::table('lideres')->where('id', $x)->value('cargo'));
        $this->assertSame('lider centro', DB::table('lideres')->where('id', $cd)->value('cargo'));
        $backup = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'lideres_cargo_backup_%'"))->first()->name;
        $this->assertSame([$x], DB::table($backup)->pluck('id')->all());
    }
}
