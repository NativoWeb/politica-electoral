<?php

namespace Tests\Feature;

use App\Console\Commands\ImportGarciaRovira;
use App\Models\Person;
use App\Services\Import\AlcaldiaImporter;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// After data:merge-persons the absorbed row keeps its name with merged_into_id: importing the same Excel again follows
// it to the survivor instead of creating the person again, and its votes stay counted once. Aliases never match.
class ImportAfterMergeTest extends TestCase
{
    use BuildsElectoralSchema;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElectoralSchema();

        $sanGil = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'San Gil']);
        $this->row('geographic_aliases', ['geographic_unit_id' => $sanGil, 'normalized_alias' => 'SAN GIL']);
        $this->row('offices', ['name' => 'Alcaldía']);
        $this->row('electoral_events', ['name' => 'Elecciones territoriales 2023', 'event_type' => 'territorial', 'election_date' => '2023-10-29']);

        $this->file = tempnam(sys_get_temp_dir(), 'alc').'.xlsx';
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('ALCALDIA ')->fromArray([
            [null, 'REVISADO'],
            ['PROVINCIA', 'MUNICIPIO', 'NOMBRE CANDIDATO', 'POTENCIAL', 'VOTOS', 'CARGO', 'TIPO DE AVAL', 'Aval Principal', 'CONTACTO'],
            ['GUANENTA', 'SAN GIL', 'JUAN PEREZ', null, '9000', 'ELECTO', null, null, null],
        ]);
        (new Xlsx($book))->save($this->file);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function livePersons()
    {
        return DB::table('persons')->whereNull('deleted_at')->whereNull('merged_into_id');
    }

    public function test_reimport_after_a_merge_reuses_the_survivor_without_duplicating_votes(): void
    {
        (new AlcaldiaImporter)->import($this->file);
        $absorbed = DB::table('persons')->where('normalized_name', 'JUAN PEREZ')->value('id');
        $survivor = $this->row('persons', ['full_name' => 'Juan Perez Gomez', 'normalized_name' => 'JUAN PEREZ GOMEZ', 'identity_status' => 'unverified']);
        $this->artisan('data:merge-persons', ['survivor' => $survivor, 'absorbed' => $absorbed])->assertSuccessful();

        (new AlcaldiaImporter)->import($this->file);

        $this->assertSame([$survivor], $this->livePersons()->pluck('id')->all());
        $this->assertSame(2, DB::table('persons')->count());
        $this->assertSame([$survivor], DB::table('candidacies')->pluck('person_id')->all());
        $this->assertSame([9000], DB::table('electoral_results')->pluck('value')->map(fn ($v) => (int) $v)->all());
    }

    public function test_garcia_rovira_lookup_follows_the_merge(): void
    {
        $survivor = $this->row('persons', ['full_name' => 'Juan Perez Gomez', 'normalized_name' => 'JUAN PEREZ GOMEZ']);
        $this->row('persons', ['full_name' => 'Juan Perez', 'normalized_name' => 'JUAN PEREZ', 'merged_into_id' => $survivor, 'deleted_at' => now()]);

        $found = (fn (string $name) => $this->findOrCreatePerson($name))->call(new ImportGarciaRovira, 'Juan Perez');

        $this->assertSame($survivor, $found);
        $this->assertSame(1, $this->livePersons()->count());
    }

    public function test_resolver_order_live_name_then_merged_name(): void
    {
        $live = $this->row('persons', ['full_name' => 'Ana Ruiz', 'normalized_name' => 'ANA RUIZ']);
        $other = $this->row('persons', ['full_name' => 'Beto Diaz Gil', 'normalized_name' => 'BETO DIAZ GIL']);
        $this->row('person_aliases', ['person_id' => $other, 'alias_name' => 'BETO DIAZ', 'normalized_alias' => 'BETO DIAZ']);
        $end = $this->row('persons', ['full_name' => 'Caro Paz Mesa', 'normalized_name' => 'CARO PAZ MESA']);
        $middle = $this->row('persons', ['full_name' => 'Caro P Mesa', 'normalized_name' => 'CARO P MESA', 'merged_into_id' => $end, 'deleted_at' => now()]);
        $this->row('persons', ['full_name' => 'Caro Paz', 'normalized_name' => 'CARO PAZ', 'merged_into_id' => $middle, 'deleted_at' => now()]);
        // A merged row with the same name as a live one never wins over it.
        $this->row('persons', ['full_name' => 'Ana Ruiz', 'normalized_name' => 'ANA RUIZ', 'merged_into_id' => $other, 'deleted_at' => now()]);

        $this->assertSame($live, Person::resolveIdByNormalizedName('ANA RUIZ'));
        // An alias alone never identifies a person (short Gobernación aliases would catch namesakes).
        $this->assertNull(Person::resolveIdByNormalizedName('BETO DIAZ'));
        $this->assertSame($end, Person::resolveIdByNormalizedName('CARO PAZ'));
        $this->assertNull(Person::resolveIdByNormalizedName('NADIE'));
        $this->assertSame($end, Person::resolveId($middle));
        $this->assertSame('no-uuid', Person::resolveId('no-uuid'));
    }

    public function test_a_name_that_only_exists_as_another_persons_alias_creates_a_new_person(): void
    {
        $candidate = $this->row('persons', ['full_name' => 'Juan Perez Torres', 'normalized_name' => 'JUAN PEREZ TORRES']);
        $this->row('person_aliases', ['person_id' => $candidate, 'alias_name' => 'JUAN PEREZ', 'normalized_alias' => 'JUAN PEREZ', 'alias_type' => 'electoral']);

        (new AlcaldiaImporter)->import($this->file);

        $created = DB::table('persons')->where('normalized_name', 'JUAN PEREZ')->value('id');
        $this->assertNotNull($created);
        $this->assertNotSame($candidate, $created);
        $this->assertSame([$created], DB::table('candidacies')->pluck('person_id')->all());
        $this->assertSame(0, DB::table('candidacies')->where('person_id', $candidate)->count());
    }
}
