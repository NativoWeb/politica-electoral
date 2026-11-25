<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Support\MigracionFlags;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// "Diferente en Electo" in the ficha's notice: "Dejar el de aquí" / "Usar el de Electo" (POST
// /votantes/{id}/electo/diferencias/{campo}). SQLite, fictitious data; each test in its own process (MigracionFlags
// caches whether the column exists).
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ElectoDiferenciaTest extends TestCase
{
    use BuildsEventosScenario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        Schema::table('lideres', fn (Blueprint $t) => $t->json('migracion_flags')->nullable());
        (fn () => self::$hasColumn = null)->bindTo(null, MigracionFlags::class)();
        $this->user = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'write.data']);
    }

    private function ficha(array $contradicciones, array $extra = [], string $campaign = Campaign::DEFAULT_ID, array $flags = []): string
    {
        return $this->lider($campaign, 'FRANKLIN ORTIS', null, array_merge([
            'migracion_flags' => json_encode(['contradicciones' => $contradicciones, ...$flags]),
        ], $extra));
    }

    private function resolver(string $id, string $campo, string $accion, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->postJson("/votantes/{$id}/electo/diferencias/{$campo}", ['accion' => $accion]);
    }

    private function flags(string $id): ?array
    {
        $raw = DB::table('lideres')->where('id', $id)->value('migracion_flags');

        return $raw === null ? null : json_decode($raw, true);
    }

    public function test_dejar_el_de_aqui_keeps_the_value_and_removes_only_that_difference(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ', 'genero' => 'Femenino'], ['genero' => 'Masculino'], flags: ['electo_id' => 77]);

        $res = $this->resolver($id, 'nombre', 'aqui')->assertOk()->assertJson(['success' => true, 'campo' => 'nombre', 'valor' => null]);

        $this->assertSame('FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertSame(['contradicciones' => ['genero' => 'Femenino'], 'electo_id' => 77], $this->flags($id));
        $this->assertSame(['genero'], array_column($res->json('migracion.diferencias'), 'campo'));
    }

    public function test_usar_el_de_electo_writes_the_value_and_the_notice_disappears_when_nothing_is_left(): void
    {
        $id = $this->ficha(['nombre' => '  FRANKLIN   ORTIZ ']);

        $this->resolver($id, 'nombre', 'electo')->assertOk()
            ->assertJson(['success' => true, 'campo' => 'nombre', 'valor' => 'FRANKLIN ORTIZ', 'migracion' => null]);

        $this->assertSame('FRANKLIN ORTIZ', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertNull($this->flags($id), 'Nothing left: the column goes back to NULL.');
        $this->assertNull($this->actingAs($this->user)->getJson("/votantes/{$id}")->assertOk()->json('data.migracion'));
    }

    public function test_usar_el_de_electo_runs_the_save_rules_of_the_ficha(): void
    {
        // Saving clears what is now complete: the birth date Electo had wrong stops being pending with the new one.
        $id = $this->ficha(['fecha_nacimiento' => '1985-03-02', 'estado_civil' => 'SOLTERO/A'],
            ['fecha_nacimiento' => null, 'estado_civil' => 'Casado/a'], flags: ['fecha_invalida' => ['fecha_nacimiento']]);

        $this->resolver($id, 'fecha_nacimiento', 'electo')->assertOk()->assertJsonPath('valor', '1985-03-02');
        $this->assertSame('1985-03-02', substr(DB::table('lideres')->where('id', $id)->value('fecha_nacimiento'), 0, 10));
        $this->assertSame(['contradicciones' => ['estado_civil' => 'SOLTERO/A']], $this->flags($id));

        // A list value is taken as the list's own option (without case or accents).
        $this->resolver($id, 'estado_civil', 'electo')->assertOk()->assertJsonPath('valor', 'Soltero/a');
        $this->assertSame('Soltero/a', DB::table('lideres')->where('id', $id)->value('estado_civil'));
        $this->assertNull($this->flags($id));
    }

    public function test_an_already_resolved_difference_answers_200_without_changing_anything(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ', 'genero' => 'Femenino']);
        $this->resolver($id, 'nombre', 'aqui')->assertOk();
        $before = DB::table('lideres')->where('id', $id)->first();

        // Double click, or a second user that resolved it first.
        $this->resolver($id, 'nombre', 'electo')->assertOk()->assertJson(['success' => true, 'valor' => null, 'message' => 'Esta diferencia ya estaba resuelta.']);

        $this->assertEquals($before, DB::table('lideres')->where('id', $id)->first());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ELECTO_DIFERENCIA_RESUELTA')->count());
    }

    public function test_a_field_outside_the_list_is_rejected(): void
    {
        $id = $this->ficha(['observacion' => 'Otra nota']);

        $this->resolver($id, 'observacion', 'electo')->assertStatus(422)->assertJson(['success' => false]);
        $this->resolver($id, 'nombre', 'borrar')->assertStatus(422)->assertJsonValidationErrors('accion');
        $this->actingAs($this->user)->postJson("/votantes/{$id}/electo/diferencias/nombre", [])->assertStatus(422);

        $this->assertSame('Otra nota', $this->flags($id)['contradicciones']['observacion']);
        $this->assertSame('Nota original', DB::table('lideres')->where('id', $id)->value('observacion'));
    }

    public function test_an_invalid_electo_value_is_rejected_and_nothing_changes(): void
    {
        $id = $this->ficha([
            'fecha_nacimiento' => '1990-02-30', 'fecha_expedicion_doc' => '2999-01-01', 'genero' => 'X9', 'telefono' => '12', 'email' => 'no-es-correo',
        ], ['fecha_nacimiento' => '1990-09-15']);

        foreach (['fecha_nacimiento', 'fecha_expedicion_doc', 'genero', 'telefono', 'email'] as $campo) {
            $this->resolver($id, $campo, 'electo')->assertStatus(422)->assertJson(['success' => false, 'message' => 'El valor de Electo no es válido para este campo.']);
        }

        $this->assertCount(5, $this->flags($id)['contradicciones']);
        $this->assertSame('1990-09-15', DB::table('lideres')->where('id', $id)->value('fecha_nacimiento'));
        $this->assertSame('3000000000', DB::table('lideres')->where('id', $id)->value('telefono'));
        // The notice does not offer what the server would refuse.
        $diferencias = $this->actingAs($this->user)->getJson("/votantes/{$id}")->json('data.migracion.diferencias');
        $this->assertSame([false], array_values(array_unique(array_column($diferencias, 'usar_electo'))));
    }

    public function test_an_electo_name_with_digits_is_not_offered_nor_accepted(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ 3001234567']);

        $diferencias = $this->actingAs($this->user)->getJson("/votantes/{$id}")->json('data.migracion.diferencias');
        $this->assertSame([['nombre', false]], array_map(fn ($d) => [$d['campo'], $d['usar_electo']], $diferencias));

        $this->resolver($id, 'nombre', 'electo')->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'El valor de Electo no es válido para este campo.']);
        $this->assertSame('FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertSame(['nombre' => 'FRANKLIN ORTIZ 3001234567'], $this->flags($id)['contradicciones']);

        // «Dejar el de aquí» still resolves it.
        $this->resolver($id, 'nombre', 'aqui')->assertOk();
        $this->assertNull($this->flags($id));
    }

    public function test_municipio_and_autorizacion_are_only_kept_here(): void
    {
        $id = $this->ficha(['municipio' => 'Lebrija', 'autoriza_datos' => false], ['autoriza_datos' => true]);

        $this->resolver($id, 'municipio', 'electo')->assertStatus(422)->assertJsonPath('message', 'Este dato solo se cambia editando la ficha.');
        $this->resolver($id, 'autoriza_datos', 'electo')->assertStatus(422);
        $this->assertSame($this->giron, DB::table('lideres')->where('id', $id)->value('geographic_unit_id'));
        $this->assertEquals(1, DB::table('lideres')->where('id', $id)->value('autoriza_datos'));

        $res = $this->resolver($id, 'municipio', 'aqui')->assertOk();
        $this->assertSame([['campo' => 'autoriza_datos', 'label' => 'Autoriza datos', 'electo' => 'No', 'actual' => 'Sí', 'usar_electo' => false]],
            $res->json('migracion.diferencias'));
    }

    public function test_without_write_data_it_is_forbidden(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ']);
        $campo = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa'], 'R09_CAMPO');
        $consulta = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'write.data'], 'R10_CONSULTA_EJECUTIVA');

        $this->resolver($id, 'nombre', 'electo', $campo)->assertForbidden();
        $this->resolver($id, 'nombre', 'electo', $consulta)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/votantes/{$id}/electo/diferencias/nombre", ['accion' => 'aqui'])->assertUnauthorized();

        $this->assertSame('FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertArrayHasKey('nombre', $this->flags($id)['contradicciones']);
    }

    public function test_another_grupo_politico_does_not_see_the_ficha(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ'], campaign: $this->campY);

        $this->resolver($id, 'nombre', 'electo')->assertNotFound();
        $this->resolver('00000000-0000-4000-8000-000000000000', 'nombre', 'aqui')->assertNotFound();

        $this->assertSame('FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertArrayHasKey('nombre', $this->flags($id)['contradicciones']);
    }

    public function test_a_subgrupo_ficha_is_read_only(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ'], campaign: $this->campX);

        $this->resolver($id, 'nombre', 'aqui')->assertForbidden();
        $this->resolver($id, 'nombre', 'electo')->assertForbidden();

        $this->assertSame('FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));
        $this->assertArrayHasKey('nombre', $this->flags($id)['contradicciones']);
    }

    public function test_a_ficha_outside_the_territory_is_forbidden(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ']);
        $lebrija = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'write.data'], territory: [$this->lebrija]);
        $giron = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'write.data'], territory: [$this->giron]);

        $this->resolver($id, 'nombre', 'electo', $lebrija)->assertForbidden();
        $this->assertSame('FRANKLIN ORTIS', DB::table('lideres')->where('id', $id)->value('nombre'));

        $this->resolver($id, 'nombre', 'electo', $giron)->assertOk();
        $this->assertSame('FRANKLIN ORTIZ', DB::table('lideres')->where('id', $id)->value('nombre'));
    }

    public function test_the_audit_says_which_field_and_action_without_personal_values(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ', 'telefono' => '3109998888']);

        $this->resolver($id, 'nombre', 'electo')->assertOk();
        $this->resolver($id, 'telefono', 'aqui')->assertOk();

        $logs = DB::table('audit_logs')->where('action', 'ELECTO_DIFERENCIA_RESUELTA')->orderBy('created_at')->get();
        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing([['campo' => 'nombre', 'accion' => 'electo'], ['campo' => 'telefono', 'accion' => 'aqui']],
            $logs->map(fn ($l) => json_decode($l->metadata, true))->all());
        $this->assertSame([$id], $logs->pluck('entity_id')->unique()->values()->all());
        $this->assertSame((string) $this->user->id, (string) $logs[0]->user_id);
        // 'aqui' changes nothing: no before/after.
        $aqui = $logs->first(fn ($l) => json_decode($l->metadata, true)['accion'] === 'aqui');
        $this->assertNull($aqui->before_state);
        $this->assertNull($aqui->after_state);
        $this->assertStringNotContainsString('3109998888', DB::table('audit_logs')->get()->map(fn ($l) => json_encode($l))->implode("\n"));
    }

    public function test_usar_el_de_electo_audits_before_and_after_masked_to_undo_it(): void
    {
        $id = $this->ficha(['nombre' => 'FRANKLIN ORTIZ', 'telefono' => '3109998888', 'fecha_nacimiento' => '1985-03-02'], ['fecha_nacimiento' => '1990-09-15']);

        foreach (['nombre', 'telefono', 'fecha_nacimiento'] as $campo) {
            $this->resolver($id, $campo, 'electo')->assertOk();
        }

        $state = fn (string $campo, string $col) => json_decode(DB::table('audit_logs')->where('action', 'ELECTO_DIFERENCIA_RESUELTA')
            ->get()->first(fn ($l) => json_decode($l->metadata, true)['campo'] === $campo)->{$col}, true);
        $this->assertSame(['nombre' => 'FRANKLIN ORTIS'], $state('nombre', 'before_state'));
        $this->assertSame(['nombre' => 'FRANKLIN ORTIZ'], $state('nombre', 'after_state'));
        $this->assertSame(['telefono_last4' => '0000'], $state('telefono', 'before_state'));
        $this->assertSame(['telefono_last4' => '8888'], $state('telefono', 'after_state'));
        $this->assertSame(['fecha_nacimiento' => '[omitido]'], $state('fecha_nacimiento', 'before_state'));
        $this->assertSame(['fecha_nacimiento' => '[omitido]'], $state('fecha_nacimiento', 'after_state'));
        $all = DB::table('audit_logs')->get()->map(fn ($l) => json_encode($l))->implode("\n");
        $this->assertStringNotContainsString('3109998888', $all);
        $this->assertStringNotContainsString('1990-09-15', $all);
        $this->assertStringNotContainsString('1985-03-02', $all);
    }

    public function test_usar_el_de_electo_keeps_our_phone_and_email_as_contacts(): void
    {
        $id = $this->ficha(['telefono' => '3109998888', 'email' => 'nuevo@electo.co'], ['telefono' => '3001112222', 'email' => 'Local@Mio.co']);

        $this->resolver($id, 'telefono', 'electo')->assertOk()->assertJsonPath('valor', '3109998888');
        $this->resolver($id, 'email', 'electo')->assertOk()->assertJsonPath('valor', 'nuevo@electo.co');

        $row = DB::table('lideres')->where('id', $id)->first();
        $this->assertSame(['3109998888', 'nuevo@electo.co'], [$row->telefono, $row->email]);
        $contactos = DB::table('lider_contactos')->where('lider_id', $id)->orderBy('tipo')->get(['tipo', 'valor', 'principal', 'estado'])
            ->map(fn ($c) => (array) $c)->all();
        $this->assertEquals([
            ['tipo' => 'celular', 'valor' => '3001112222', 'principal' => 0, 'estado' => 'activo'],
            ['tipo' => 'email', 'valor' => 'Local@Mio.co', 'principal' => 0, 'estado' => 'activo'],
        ], $contactos);
    }

    public function test_a_local_phone_already_among_the_contacts_is_not_duplicated(): void
    {
        $id = $this->ficha(['telefono' => '3109998888'], ['telefono' => '300 111 2222']);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $id, 'tipo' => 'whatsapp', 'valor' => '3001112222', 'principal' => true]);

        $this->resolver($id, 'telefono', 'electo')->assertOk();

        $this->assertSame(1, DB::table('lider_contactos')->where('lider_id', $id)->count());
        $this->assertSame('3109998888', DB::table('lideres')->where('id', $id)->value('telefono'));
    }

    public function test_a_fijo_without_contacts_is_kept_as_fijo_and_an_empty_one_adds_nothing(): void
    {
        $fijo = $this->ficha(['telefono' => '3109998888'], ['telefono' => '6076543210']);
        $vacio = $this->ficha(['email' => 'nuevo@electo.co'], ['email' => null]);

        $this->resolver($fijo, 'telefono', 'electo')->assertOk();
        $this->resolver($vacio, 'email', 'electo')->assertOk();

        $this->assertSame(['fijo'], DB::table('lider_contactos')->where('lider_id', $fijo)->pluck('tipo')->all());
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $vacio)->count());
    }

    public function test_the_notice_shows_the_current_birth_date_of_the_ficha(): void
    {
        $id = $this->ficha(['fecha_nacimiento' => '1990-09-16'], ['fecha_nacimiento' => '1990-09-15']);

        $diferencia = $this->actingAs($this->user)->getJson("/votantes/{$id}")->assertOk()->json('data.migracion.diferencias.0');

        $this->assertSame(['campo' => 'fecha_nacimiento', 'label' => 'Fecha de nacimiento', 'electo' => '1990-09-16', 'actual' => '1990-09-15', 'usar_electo' => true], $diferencia);
    }
}
