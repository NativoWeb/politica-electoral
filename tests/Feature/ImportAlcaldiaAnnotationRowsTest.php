<?php

namespace Tests\Feature;

use App\Console\Commands\ImportGarciaRovira;
use App\Services\Import\AlcaldiaImporter;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// Rows of the ALCALDIA sheet without votes are campaign notes ("SAN GIL | WILMER JIMENEZ | EX CANDIDATO ASAMBLEA"),
// not 2023 candidates: the importers skip them with a warning instead of creating a candidacy and a note-"party".
class ImportAlcaldiaAnnotationRowsTest extends TestCase
{
    use BuildsElectoralSchema;

    private string $file;

    private string $sanGil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElectoralSchema();

        $this->sanGil = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'San Gil']);
        $this->row('geographic_aliases', ['geographic_unit_id' => $this->sanGil, 'normalized_alias' => 'SAN GIL']);
        $this->row('offices', ['name' => 'Alcaldía']);
        $this->row('electoral_events', ['name' => 'Elecciones territoriales 2023', 'event_type' => 'territorial', 'election_date' => '2023-10-29']);
        $this->file = tempnam(sys_get_temp_dir(), 'alc').'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_alcaldia_importer_skips_rows_without_votes(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('ALCALDIA ')->fromArray([
            [null, 'REVISADO CON OSCAR'],
            ['PROVINCIA', 'MUNICIPIO', 'NOMBRE CANDIDATO', 'POTENCIAL', 'VOTOS', 'CARGO', 'TIPO DE AVAL', 'Aval Principal', 'CONTACTO'],
            ['GUANENTA', 'SAN GIL', 'ALCALDE ELECTO', null, '9000', 'ELECTO', null, 'CD', null],
            [null, 'SAN GIL', 'CANDIDATO SIN PROVINCIA', null, '1603', null, null, null, null],
            [null, 'SAN GIL', 'WILMER  JIMENEZ', null, null, null, null, 'EX CANDIDATO ASAMBLEA', null],
        ]);
        (new Xlsx($book))->save($this->file);

        $stats = (new AlcaldiaImporter)->import($this->file);

        $this->assertSame(2, DB::table('candidacies')->count());
        $this->assertSame(2, DB::table('electoral_results')->count());
        $this->assertSame(0, DB::table('persons')->where('normalized_name', 'WILMER JIMENEZ')->count());
        $this->assertSame(0, DB::table('political_organizations')->where('canonical_name', 'Ex Candidato Asamblea')->count());
        $this->assertSame(['Fila 5: sin votos (SAN GIL), no es una candidatura; se omite'], $stats['warnings']);
        $this->assertSame([], $stats['errors']);
    }

    public function test_garcia_rovira_skips_rows_without_votes(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('ALCALDIA ');
        $sheet->fromArray([
            ['REVISADO CON OSCAR'],
            ['MUNICIPIO', 'NOMBRE CANDIDATO', 'POTENCIAL', 'VOTOS', 'CARGO', 'TIPO DE AVAL', 'Aval Principal', 'CONTACTO'],
            ['SAN GIL', 'CANDIDATO REAL', null, '798', 'ELECTO', null, null, null],
            ['SAN GIL', 'BETO MORALES', null, null, null, null, 'EX ALCALDE', null],
        ]);

        $command = new ImportGarciaRovira;
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $sanGil = $this->sanGil;
        (function () use ($sanGil) {
            $this->municipios = ['SAN GIL' => $sanGil];
        })->call($command);

        (fn ($ws) => $this->importAlcaldiaSheet($ws))->call($command, $sheet);

        $this->assertSame(1, DB::table('candidacies')->count());
        $this->assertSame(0, DB::table('persons')->where('normalized_name', 'BETO MORALES')->count());
        $this->assertSame(0, DB::table('political_organizations')->count());
        $this->assertStringContainsString('fila 4: sin votos (SAN GIL)', $output->fetch());
    }
}
