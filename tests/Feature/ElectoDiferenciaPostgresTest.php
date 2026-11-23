<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: "Dejar el de aquí" / "Usar el de Electo" on the real schema (jsonb migracion_flags, date columns, the row
// lock) and the notice of the Mapa's ficha. Fictitious rows, rolled back.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=ElectoDiferenciaPostgresTest
class ElectoDiferenciaPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $mun;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Municipio Diferencias PG']);
        $this->user = User::create([
            'name' => 'Diferencias PG', 'email' => 'dif-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'territory_scope' => [$this->mun], 'permissions' => ['view.votantes', 'view.mapa', 'write.data'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    private function ficha(array $contradicciones, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun, 'nombre' => 'ZZDIF FRANKLIN ORTIS',
            'municipio' => 'Municipio Diferencias PG', 'tipo' => 'votante', 'cargo' => '', 'is_active' => true,
            'fecha_nacimiento' => '1990-09-15', 'migracion_flags' => json_encode(['contradicciones' => $contradicciones, 'electo_id' => 5]),
            'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);

        return $id;
    }

    public function test_the_mapa_ficha_shows_the_birth_date_and_both_actions_work(): void
    {
        $id = $this->ficha(['fecha_nacimiento' => '1990-09-16', 'nombre' => 'ZZDIF FRANKLIN ORTIZ']);

        $aviso = $this->actingAs($this->user)->getJson("/mapa-politico/persona/{$id}")->assertOk()->json('migracion.diferencias');
        $this->assertSame(['campo' => 'fecha_nacimiento', 'label' => 'Fecha de nacimiento', 'electo' => '1990-09-16', 'actual' => '1990-09-15', 'usar_electo' => true],
            collect($aviso)->firstWhere('campo', 'fecha_nacimiento'));

        $this->actingAs($this->user)->postJson("/votantes/{$id}/electo/diferencias/fecha_nacimiento", ['accion' => 'electo'])
            ->assertOk()->assertJsonPath('valor', '1990-09-16');
        $this->assertSame('1990-09-16', DB::table('lideres')->where('id', $id)->value('fecha_nacimiento'));
        $flags = json_decode(DB::table('lideres')->where('id', $id)->value('migracion_flags'), true);
        $this->assertEquals(['contradicciones' => ['nombre' => 'ZZDIF FRANKLIN ORTIZ'], 'electo_id' => 5], $flags);

        $this->actingAs($this->user)->postJson("/votantes/{$id}/electo/diferencias/nombre", ['accion' => 'aqui'])
            ->assertOk()->assertJsonPath('migracion', null);
        $this->assertSame(['electo_id' => 5], json_decode(DB::table('lideres')->where('id', $id)->value('migracion_flags'), true),
            'Other keys stay; nothing is shown any more.');
        $this->assertNull($this->actingAs($this->user)->getJson("/mapa-politico/persona/{$id}")->assertOk()->json('migracion'));
        // The "Diferencias con Electo" filter stops listing it.
        $this->assertSame(0, DB::table('lideres')->where('id', $id)->whereRaw("jsonb_exists(migracion_flags, 'contradicciones')")->count());

        $this->actingAs($this->user)->postJson("/votantes/{$id}/electo/diferencias/nombre", ['accion' => 'electo'])
            ->assertOk()->assertJsonPath('message', 'Esta diferencia ya estaba resuelta.');
        $this->assertSame('ZZDIF FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertSame(2, DB::table('audit_logs')->where('entity_id', $id)->where('action', 'ELECTO_DIFERENCIA_RESUELTA')->count());
    }

    public function test_an_invalid_date_from_electo_is_refused_on_the_real_column(): void
    {
        $id = $this->ficha(['fecha_nacimiento' => '1990-13-01']);

        $this->actingAs($this->user)->postJson("/votantes/{$id}/electo/diferencias/fecha_nacimiento", ['accion' => 'electo'])
            ->assertStatus(422)->assertJsonPath('message', 'El valor de Electo no es válido para este campo.');
        $this->assertSame('1990-09-15', DB::table('lideres')->where('id', $id)->value('fecha_nacimiento'));
    }
}
