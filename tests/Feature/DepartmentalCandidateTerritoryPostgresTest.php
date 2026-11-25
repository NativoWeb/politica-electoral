<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Configuracion\Catalogo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Saving the ficha of a candidate of a departmental contest (Asamblea) from the Mapa Político: the candidate has no
// lider yet, so one is created, through queries that only run on PostgreSQL (ilike).
// Run against a LOCAL database: RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=DepartmentalCandidateTerritoryPostgresTest
// Every change is rolled back by DatabaseTransactions.
class DepartmentalCandidateTerritoryPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $dept;

    private string $provincia;

    private string $munA;

    private string $munB;

    private string $otherDeptMun;

    private string $personId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->dept = $this->geo('department', 'Depto Prueba');
        $this->provincia = $this->geo('province', 'Provincia Prueba', $this->dept);
        $this->munA = $this->geo('municipality', 'Municipio Prueba A', $this->provincia);
        $this->munB = $this->geo('municipality', 'Capitanejo Prueba', $this->provincia);
        $this->otherDeptMun = $this->geo('municipality', 'Bogotá Prueba', $this->geo('department', 'Otro Depto Prueba'));
        $this->personId = $this->asambleaCandidate('Didier Moreno Prueba', [$this->munA, $this->munB]);
    }

    private function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent]);

        return $id;
    }

    private function asambleaCandidate(string $name, array $votesIn): string
    {
        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => 'Asamblea prueba', 'geographic_unit_id' => $this->dept,
            'electoral_event_id' => DB::table('electoral_events')->value('id'),
            'corporation_id' => DB::table('corporations')->where('name', 'Asamblea')->value('id'),
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert([
            'id' => $personId, 'first_name' => 'Didier', 'last_name' => 'Moreno', 'full_name' => $name,
            'normalized_name' => mb_strtolower($name),
        ]);
        $candidacyId = (string) Str::uuid();
        DB::table('candidacies')->insert(['id' => $candidacyId, 'person_id' => $personId, 'contest_id' => $contestId]);
        foreach ($votesIn as $geoId) {
            DB::table('electoral_results')->insert([
                'id' => (string) Str::uuid(), 'contest_id' => $contestId, 'candidacy_id' => $candidacyId,
                'geographic_unit_id' => $geoId, 'grain' => 'municipality', 'metric_type' => 'votes', 'value' => 10,
            ]);
        }

        return $personId;
    }

    private function user(array $scope): User
    {
        return User::create([
            'name' => 'Campo PG', 'email' => 'campo-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R09_CAMPO')->value('id'), 'territory_scope' => $scope,
            'permissions' => ['view.mapa', 'view.votantes', 'write.data'],
        ]);
    }

    private function fichas()
    {
        return DB::table('lideres')->where('nombre', 'ilike', 'Didier Moreno Prueba');
    }

    public function test_vivian_saves_the_ficha_of_an_asamblea_candidate_in_the_municipio_she_chose(): void
    {
        $vivian = $this->user([$this->munA, $this->munB, $this->otherDeptMun]);

        $this->actingAs($vivian)->putJson("/mapa-politico/persona/{$this->personId}", [
            'nombre' => 'Didier Moreno Prueba', 'municipio_id' => $this->munB, 'municipio' => 'Capitanejo Prueba',
            'cedula' => '91999000111', 'telefono' => '3001112233',
        ])->assertOk()->assertJsonPath('success', true);

        $lider = $this->fichas()->first();
        $this->assertNotNull($lider);
        $this->assertSame($this->munB, $lider->geographic_unit_id);
        $this->assertSame('Capitanejo Prueba', $lider->municipio);
        $this->assertSame('Provincia Prueba', $lider->provincia);
        $this->assertSame('91999000111', $lider->cedula);
        $this->assertSame($vivian->campaign_id ?? $lider->campaign_id, $lider->campaign_id);

        // A second save updates the same ficha, it does not create another.
        $this->actingAs($vivian)->putJson("/mapa-politico/persona/{$this->personId}", [
            'nombre' => 'Didier Moreno Prueba', 'municipio_id' => $this->munB, 'telefono' => '3009998877',
        ])->assertOk();
        $this->assertSame(1, $this->fichas()->count());
        $this->assertSame('3009998877', $this->fichas()->value('telefono'));
    }

    public function test_the_first_save_that_creates_the_ficha_keeps_every_extended_field(): void
    {
        $opcion = fn (string $catalogo) => Catalogo::valores($catalogo)[0];
        $extended = [
            'tipo_documento' => $opcion('tipo_documento'), 'fecha_expedicion_doc' => '2001-02-03', 'genero' => $opcion('genero'),
            'fecha_nacimiento' => '1980-05-06', 'estado_civil' => $opcion('estado_civil'), 'escolaridad' => $opcion('escolaridad'),
            'nivel_confianza' => $opcion('nivel_confianza'),
            'departamento_votacion' => 'Depto Prueba', 'municipio_votacion' => 'Capitanejo Prueba',
            'puesto_votacion' => 'Escuela Central', 'direccion_puesto' => 'Calle 1 # 2-3', 'mesa_votacion' => '7',
            'militante' => true, 'autoriza_datos' => true, 'verificado' => true, 'empresario' => true,
            'reservista' => true, 'funcionario' => true, 'exfuncionario' => true, 'gran_elector' => true,
            'convenio' => 'Convenio Prueba', 'tipo_hoja_vida' => 'Completa', 'fecha_registro_hv' => '2026-01-02',
            'facebook' => 'didier.fb', 'twitter' => '@didier', 'instagram' => '@didier.ig',
        ];
        $form = ['nombre' => 'Didier Moreno Prueba', 'municipio_id' => $this->munB, 'cedula' => '91999000222',
            'direccion' => 'Carrera 4', 'barrio' => 'Centro', 'zona' => $opcion('zona'), 'profesion' => 'Docente'] + $extended;

        $this->actingAs($this->user([$this->munA, $this->munB]))
            ->putJson("/mapa-politico/persona/{$this->personId}", $form)
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $this->fichas()->count());
        $lider = (array) $this->fichas()->first();
        $this->assertSame($this->munB, $lider['geographic_unit_id']);
        foreach (array_merge(['cedula', 'direccion', 'barrio', 'zona', 'profesion'], array_keys($extended)) as $field) {
            $expected = $form[$field];
            $actual = $lider[$field];
            if (is_bool($expected)) {
                $this->assertSame($expected, (bool) $actual, $field);
            } else {
                $this->assertSame((string) $expected, substr((string) $actual, 0, strlen((string) $expected)), $field);
            }
        }
    }

    public function test_a_user_with_one_municipio_where_the_candidate_had_votes_can_edit_it(): void
    {
        $this->actingAs($this->user([$this->munA]))->putJson("/mapa-politico/persona/{$this->personId}", [
            'nombre' => 'Didier Moreno Prueba', 'municipio_id' => $this->munA, 'telefono' => '3001234567',
        ])->assertOk();

        $this->assertSame($this->munA, $this->fichas()->value('geographic_unit_id'));
    }

    public function test_a_user_whose_municipio_gave_no_votes_gets_403(): void
    {
        $sinVotos = $this->geo('municipality', 'Sin Votos Prueba', $this->provincia);

        $this->actingAs($this->user([$sinVotos]))->putJson("/mapa-politico/persona/{$this->personId}", [
            'nombre' => 'Didier Moreno Prueba', 'municipio_id' => $sinVotos,
        ])->assertForbidden()->assertJsonPath('message', 'Este registro está fuera de tu territorio.');

        $this->assertSame(0, $this->fichas()->count());
    }

    public function test_choosing_a_municipio_outside_the_territory_gets_403_with_a_clear_message(): void
    {
        $this->actingAs($this->user([$this->munA]))->putJson("/mapa-politico/persona/{$this->personId}", [
            'nombre' => 'Didier Moreno Prueba', 'municipio_id' => $this->munB,
        ])->assertForbidden()->assertJsonPath('message', 'Ese municipio está fuera de tu territorio.');

        $this->assertSame(0, $this->fichas()->count());
    }

    public function test_a_scoped_user_without_a_municipio_is_asked_for_one(): void
    {
        $this->actingAs($this->user([$this->munA, $this->munB]))
            ->putJson("/mapa-politico/persona/{$this->personId}", ['nombre' => 'Didier Moreno Prueba'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('municipio_id');

        $this->assertSame(0, $this->fichas()->count());
    }

    public function test_covering_the_whole_department_reaches_a_candidate_without_results(): void
    {
        $sinResultados = $this->asambleaCandidate('Sin Resultados Prueba', []);
        $vivian = $this->user([$this->munA, $this->munB, $this->otherDeptMun]);

        $this->actingAs($vivian)->getJson("/mapa-politico/persona/{$sinResultados}")->assertOk()->assertJsonPath('id', $sinResultados);
        $this->actingAs($vivian)->putJson("/mapa-politico/persona/{$sinResultados}", [
            'nombre' => 'Sin Resultados Prueba', 'municipio_id' => $this->munA,
        ])->assertOk();
        $this->assertSame($this->munA, DB::table('lideres')->where('nombre', 'ilike', 'Sin Resultados Prueba')->value('geographic_unit_id'));

        $this->actingAs($this->user([$this->munA]))->getJson("/mapa-politico/persona/{$sinResultados}")->assertForbidden();
    }

    public function test_an_unrestricted_admin_still_saves_without_choosing_a_municipio(): void
    {
        $admin = User::create([
            'name' => 'Admin PG', 'email' => 'admin-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R01_SUPERADMIN')->value('id'), 'territory_scope' => null,
        ]);

        $this->actingAs($admin)->putJson("/mapa-politico/persona/{$this->personId}", [
            'nombre' => 'Didier Moreno Prueba', 'telefono' => '3005550000',
        ])->assertOk();

        $this->assertSame('3005550000', $this->fichas()->value('telefono'));
    }
}
