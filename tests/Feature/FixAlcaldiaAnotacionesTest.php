<?php

namespace Tests\Feature;

use App\Console\Commands\FixAlcaldiaAnotaciones;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// data:fix-alcaldia-anotaciones: the Alcaldía 2023 "candidacies" imported from campaign notes (rows without votes)
// become lideres, junk rows go away, and nothing outside the Excel list is touched.
class FixAlcaldiaAnotacionesTest extends TestCase
{
    use BuildsElectoralSchema;

    private array $gu = [];

    private string $alcaldia;

    private string $asamblea;

    private string $event;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElectoralSchema();

        $guanenta = $this->row('geographic_units', ['type' => 'province', 'canonical_name' => 'Guanentá']);
        $mares = $this->row('geographic_units', ['type' => 'province', 'canonical_name' => 'Mares']);
        foreach (['San Gil' => $guanenta, 'Jordán' => $guanenta, 'Páramo' => $guanenta, 'Concepción' => null, 'Sabana de Torres' => $mares] as $name => $parent) {
            $this->gu[$name] = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => $name, 'parent_id' => $parent]);
        }
        $this->alcaldia = $this->row('offices', ['name' => 'Alcaldía']);
        $this->asamblea = $this->row('offices', ['name' => 'Asamblea']);
        $this->event = $this->row('electoral_events', ['name' => 'Elecciones territoriales 2023', 'election_date' => '2023-10-29']);
        $contestAsamblea = $this->row('contests', ['electoral_event_id' => $this->event, 'office_id' => $this->asamblea, 'name' => 'Asamblea 2023']);

        // Wilmer: fake Alcaldía San Gil (party edited to CD) + a real Asamblea candidacy with votes; ficha already in lideres.
        $cd = $this->row('political_organizations', ['canonical_name' => 'Centro Democrático']);
        $wilmer = $this->person('Wilmer  Jimenez', 'WILMER JIMENEZ');
        $this->ids['wilmer'] = $wilmer;
        $this->ids['wilmerFake'] = $this->fakeCandidacy($wilmer, 'San Gil', $cd);
        $real = $this->row('candidacies', ['person_id' => $wilmer, 'contest_id' => $contestAsamblea, 'outcome' => 'not_elected']);
        $this->row('electoral_results', ['candidacy_id' => $real, 'contest_id' => $contestAsamblea, 'value' => 1200, 'metric_type' => 'votes']);
        $this->ids['wilmerReal'] = $real;
        $this->ids['wilmerLider'] = $this->lider('Wilmer Jimenez', 'San Gil', 'Nota previa', 'Centro Democrático');

        // Beto Morales renamed by a user; import alias keeps the Excel name. Not in lideres.
        $exalcalde = $this->row('political_organizations', ['canonical_name' => 'Exalcalde']);
        $this->row('organization_aliases', ['organization_id' => $exalcalde, 'normalized_alias' => 'EXALCALDE']);
        $this->ids['exalcalde'] = $exalcalde;
        $beto = $this->person('GUILLERMO ALBERTO MORALES PEÑA', 'GUILLERMO ALBERTO MORALES PENA', 'BETO MORALES');
        $this->ids['beto'] = $beto;
        $this->ids['betoFake'] = $this->fakeCandidacy($beto, 'Concepción', $exalcalde);

        // "NO TENEMOS": one person with fake candidacies in Jordán and Páramo.
        $nada = $this->person('No Tenemos', 'NO TENEMOS');
        $this->ids['nada'] = $nada;
        $this->ids['nadaJordan'] = $this->fakeCandidacy($nada, 'Jordán');
        $this->ids['nadaParamo'] = $this->fakeCandidacy($nada, 'Páramo');

        // Ricardo: lider with a fuller name in the same municipio (approximate match); his note-party is unique to him.
        $familiar = $this->row('political_organizations', ['canonical_name' => 'Familiar Cesar Cediel']);
        $this->ids['familiar'] = $familiar;
        $ricardo = $this->person('Ricardo Silvestre Cediel', 'RICARDO SILVESTRE CEDIEL');
        $this->ids['ricardo'] = $ricardo;
        $this->fakeCandidacy($ricardo, 'Sabana de Torres', $familiar);
        $this->ids['ricardoLider'] = $this->lider('Ricardo Alberto Silvestre Cediel', 'Sabana de Torres', null);

        // Juan Carlos: only a first name; "Juan Carlos Gomez Castro" must NOT be taken as him. His nexo moves to the new lider.
        $secPlan = $this->row('political_organizations', ['canonical_name' => 'Sec Planeacion']);
        $this->ids['secPlan'] = $secPlan;
        $juan = $this->person('Juan Carlos', 'JUAN CARLOS');
        $this->ids['juan'] = $juan;
        $this->fakeCandidacy($juan, 'Sabana de Torres', $secPlan);
        $this->ids['otherJuan'] = $this->lider('Juan Carlos Gomez Castro', 'Sabana de Torres', null);
        $this->ids['juanNexo'] = $this->row('nexos_familiares', ['person_id' => $juan, 'nombre' => 'Hermana', 'campaign_id' => Campaign::DEFAULT_ID]);

        // A note-party still written in a lider's partido is kept.
        $this->ids['exAlcaldesa'] = $this->row('political_organizations', ['canonical_name' => 'Ex Alcaldesa']);
        $this->lider('Alguien', 'San Gil', null, 'Ex Alcaldesa');

        // Out of scope: another San Gil Alcaldía candidacy without results, and a real one with votes.
        $this->ids['otro'] = $this->fakeCandidacy($this->person('Otro Aspirante', 'OTRO ASPIRANTE'), 'San Gil');
        $realAlcalde = $this->fakeCandidacy($this->person('Alcalde Real', 'ALCALDE REAL'), 'San Gil');
        $this->row('electoral_results', ['candidacy_id' => $realAlcalde, 'value' => 9000, 'metric_type' => 'votes']);
        $this->ids['realAlcalde'] = $realAlcalde;
    }

    private function person(string $fullName, string $normalized, ?string $alias = null): string
    {
        $id = $this->row('persons', ['full_name' => $fullName, 'normalized_name' => $normalized]);
        $this->row('person_aliases', ['person_id' => $id, 'alias_name' => $alias ?? $normalized, 'normalized_alias' => $alias ?? $normalized, 'alias_type' => 'electoral']);

        return $id;
    }

    private function fakeCandidacy(string $personId, string $municipio, ?string $orgId = null): string
    {
        $contest = DB::table('contests')->where('geographic_unit_id', $this->gu[$municipio])->where('office_id', $this->alcaldia)->value('id')
            ?? $this->row('contests', ['electoral_event_id' => $this->event, 'office_id' => $this->alcaldia, 'geographic_unit_id' => $this->gu[$municipio], 'name' => "Alcaldía {$municipio} 2023"]);
        $id = $this->row('candidacies', ['person_id' => $personId, 'contest_id' => $contest, 'outcome' => 'pending']);
        if ($orgId) {
            $this->row('candidacy_endorsements', ['candidacy_id' => $id, 'organization_id' => $orgId, 'endorsement_type' => 'aval']);
        }

        return $id;
    }

    private function lider(string $nombre, string $municipio, ?string $observacion, ?string $partido = null): string
    {
        return $this->row('lideres', [
            'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => $nombre, 'municipio' => $municipio, 'geographic_unit_id' => $this->gu[$municipio],
            'cargo' => 'Líder', 'tipo' => 'directorio', 'observacion' => $observacion, 'partido' => $partido,
        ]);
    }

    private function snapshot(): array
    {
        return collect(['candidacies', 'candidacy_endorsements', 'persons', 'person_aliases', 'lideres', 'political_organizations', 'organization_aliases', 'nexos_familiares', 'audit_logs'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    public function test_moves_real_people_to_lideres_and_removes_fake_candidacies(): void
    {
        $this->artisan('data:fix-alcaldia-anotaciones')->assertSuccessful();

        // Fake candidacies gone; the real Asamblea one and the out-of-scope ones untouched.
        foreach (['wilmerFake', 'betoFake', 'nadaJordan', 'nadaParamo'] as $k) {
            $this->assertDatabaseMissing('candidacies', ['id' => $this->ids[$k]]);
        }
        $this->assertDatabaseMissing('candidacy_endorsements', ['candidacy_id' => $this->ids['betoFake']]);
        $this->assertDatabaseHas('candidacies', ['id' => $this->ids['wilmerReal']]);
        $this->assertDatabaseHas('candidacies', ['id' => $this->ids['otro']]);
        $this->assertDatabaseHas('candidacies', ['id' => $this->ids['realAlcalde']]);

        // Wilmer keeps his ficha (note appended) and his person (real Asamblea candidacy).
        $wilmer = DB::table('lideres')->where('id', $this->ids['wilmerLider'])->first();
        $this->assertSame('Nota previa | Posible aspirante Alcaldía — Ex candidato Asamblea', $wilmer->observacion);
        $this->assertSame('Centro Democrático', $wilmer->partido);
        $this->assertSame(1, DB::table('lideres')->where('geographic_unit_id', $this->gu['San Gil'])->whereRaw('UPPER(nombre) LIKE ?', ['WILMER%'])->count());
        $this->assertDatabaseHas('persons', ['id' => $this->ids['wilmer']]);

        // Beto becomes a new CD lider under his current (edited) name; person and alias deleted.
        $beto = DB::table('lideres')->where('nombre', 'GUILLERMO ALBERTO MORALES PEÑA')->first();
        $this->assertNotNull($beto);
        $this->assertSame(Campaign::DEFAULT_ID, $beto->campaign_id);
        $this->assertSame($this->gu['Concepción'], $beto->geographic_unit_id);
        $this->assertSame('Concepción', $beto->municipio);
        $this->assertSame('directorio', $beto->tipo);
        $this->assertNull($beto->partido);
        $this->assertSame('Posible aspirante Alcaldía — Ex alcalde', $beto->observacion);
        $this->assertDatabaseMissing('persons', ['id' => $this->ids['beto']]);
        $this->assertDatabaseMissing('person_aliases', ['person_id' => $this->ids['beto']]);

        // Ricardo: approximate match keeps the existing ficha, no duplicate.
        $this->assertSame('Posible aspirante Alcaldía — Familiar de César Cediel', DB::table('lideres')->where('id', $this->ids['ricardoLider'])->value('observacion'));
        $this->assertSame(0, DB::table('lideres')->where('nombre', 'Ricardo Silvestre Cediel')->count());

        // Juan Carlos: new lider (not merged into Juan Carlos Gomez Castro), his nexo follows him.
        $juan = DB::table('lideres')->where('nombre', 'Juan Carlos')->first();
        $this->assertNotNull($juan);
        $this->assertSame('Mares', $juan->provincia);
        $this->assertNull(DB::table('lideres')->where('id', $this->ids['otherJuan'])->value('observacion'));
        $this->assertSame($juan->id, DB::table('nexos_familiares')->where('id', $this->ids['juanNexo'])->value('person_id'));
        $this->assertDatabaseMissing('persons', ['id' => $this->ids['juan']]);

        // Junk: no lider created, person gone.
        $this->assertSame(0, DB::table('lideres')->whereRaw('UPPER(nombre) = ?', ['NO TENEMOS'])->count());
        $this->assertDatabaseMissing('persons', ['id' => $this->ids['nada']]);

        // Note-parties without use deleted; still-used one and real party kept.
        foreach (['exalcalde', 'familiar', 'secPlan'] as $k) {
            $this->assertDatabaseMissing('political_organizations', ['id' => $this->ids[$k]]);
        }
        $this->assertDatabaseMissing('organization_aliases', ['organization_id' => $this->ids['exalcalde']]);
        $this->assertDatabaseHas('political_organizations', ['id' => $this->ids['exAlcaldesa']]);
        $this->assertDatabaseHas('political_organizations', ['canonical_name' => 'Centro Democrático']);

        // Audit without names.
        $audit = DB::table('audit_logs')->where('action', 'DATA_FIX_ALCALDIA_CANDIDATURA')->get();
        $this->assertCount(4 + 2, $audit); // wilmer, beto, 2x no tenemos, ricardo, juan
        $this->assertStringNotContainsString('MORALES', DB::table('audit_logs')->pluck('metadata')->implode(' '));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'DATA_FIX_ALCALDIA_ANOTACIONES')->count());
    }

    public function test_is_idempotent(): void
    {
        $this->artisan('data:fix-alcaldia-anotaciones')->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('data:fix-alcaldia-anotaciones')->expectsOutput('Nada que corregir.')->assertSuccessful();

        $this->assertEquals($after, $this->snapshot());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan('data:fix-alcaldia-anotaciones', ['--dry-run' => true])
            ->expectsOutput('DRY-RUN: no se guardó nada.')
            ->assertSuccessful();

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_person_referenced_elsewhere_is_kept(): void
    {
        $this->row('person_contact_points', ['person_id' => $this->ids['beto'], 'value_raw' => '3000000000']);

        $this->artisan('data:fix-alcaldia-anotaciones')->assertSuccessful();

        $this->assertDatabaseMissing('candidacies', ['id' => $this->ids['betoFake']]);
        $this->assertDatabaseHas('persons', ['id' => $this->ids['beto']]);
    }

    public function test_approximate_name_rule(): void
    {
        $this->assertTrue(FixAlcaldiaAnotaciones::approxSameName('Diego Plata', 'Diego Fernando Plata Alvarez'));
        $this->assertTrue(FixAlcaldiaAnotaciones::approxSameName('Diomedez Muñoz', 'Diomedes Muñoz Bache'));
        $this->assertTrue(FixAlcaldiaAnotaciones::approxSameName('Roberto Almeida', 'Roberto Almeyda Pedraza'));
        $this->assertFalse(FixAlcaldiaAnotaciones::approxSameName('Juan Carlos', 'Juan Carlos Gomez Castro'));
        $this->assertFalse(FixAlcaldiaAnotaciones::approxSameName('Isaac Gomez', 'Isaac Gonzalez Sepulveda'));
        $this->assertFalse(FixAlcaldiaAnotaciones::approxSameName('Elkin', 'Elkin Perez Suarez'));
        $this->assertFalse(FixAlcaldiaAnotaciones::approxSameName('Alfonso Rodriguez', 'Pablo Herlynson Alfonso Romero Carreño'));
    }
}
