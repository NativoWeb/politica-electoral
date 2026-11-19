<?php

namespace Tests\Feature;

use App\Console\Commands\CompromisosResumenDiario;
use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\Campaign;
use App\Models\CatalogoOpcion;
use App\Models\ConfigCambio;
use App\Models\Role;
use App\Models\User;
use App\Rules\OpcionDeCatalogo;
use App\Services\Configuracion\Ajuste;
use App\Services\Configuracion\Catalogo;
use App\Services\Configuracion\CatalogoStore;
use App\Support\Configuracion\CatalogoRegistry;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Centro de configuración (/admin/configuracion): superadmin only, lists of the forms, ajustes and their history. SQLite.
class CentroConfiguracionTest extends TestCase
{
    use BuildsEventosScenario;

    private User $super;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_19_000001_create_centro_configuracion_tables.php'))->up();
        $this->roles['R01_SUPERADMIN'] = Role::create(['code' => 'R01_SUPERADMIN', 'name' => 'Superadministrador', 'level' => 10])->id;
        $this->super = $this->eventUser(Campaign::DEFAULT_ID, ['*'], 'R01_SUPERADMIN');
        // A grupo político admin with every permission of the admin screens: still not the superadmin.
        $this->admin = $this->eventUser(Campaign::DEFAULT_ID, ['view.admin', 'manage.users', 'write.data', 'view.votantes', 'view.mapa', 'audit.view']);
    }

    private function opcion(string $catalogo, string $valor): CatalogoOpcion
    {
        return CatalogoOpcion::where('catalogo', $catalogo)->where('valor', $valor)->firstOrFail();
    }

    // --- Access ---

    public function test_only_the_superadmin_opens_and_changes_the_centro(): void
    {
        $id = $this->opcion('genero', 'Otro')->id;
        $requests = [
            ['get', '/admin/configuracion'], ['get', '/admin/configuracion/listas/genero'], ['get', '/admin/configuracion/ajustes'],
            ['get', '/admin/configuracion/historial'], ['postJson', '/admin/configuracion/listas/genero/opciones'],
            ['putJson', "/admin/configuracion/opciones/{$id}"], ['deleteJson', "/admin/configuracion/opciones/{$id}"],
            ['postJson', '/admin/configuracion/listas/genero/orden'], ['putJson', '/admin/configuracion/ajustes/mapa.tamano_pagina'],
            ['deleteJson', '/admin/configuracion/ajustes/mapa.tamano_pagina'],
        ];
        foreach ($requests as [$method, $url]) {
            $this->actingAs($this->admin)->{$method}($url, ['etiqueta' => 'X', 'valor' => 100, 'ids' => [$id]])->assertForbidden();
        }
        $this->assertSame('Otro', $this->opcion('genero', 'Otro')->etiqueta);
        $this->assertSame(0, ConfigCambio::count());

        $this->actingAs($this->super)->get('/admin/configuracion')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Configuracion/Index')
                ->has('listas', count(CatalogoRegistry::DEFINICIONES))->has('accesos', 5));
        $this->actingAs($this->super)->get('/admin/configuracion/listas/genero')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Configuracion/Lista')->has('opciones', 4));
        $this->actingAs($this->super)->get('/admin/configuracion/listas/no_existe')->assertNotFound();
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/no.existe', ['valor' => 1])->assertNotFound();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/configuracion')->assertRedirect('/login');
    }

    // --- Lists ---

    public function test_the_migration_loads_the_original_options_exactly(): void
    {
        $this->assertSame(['CC', 'CE', 'TI', 'PA', 'RC', 'NIP', 'NUIP', 'NIT'], Catalogo::valores('tipo_documento'));
        $this->assertSame(['Masculino', 'Femenino', 'Otro', 'No binario'], Catalogo::valores('genero'));
        $this->assertSame(['Soltero/a', 'Casado/a', 'Unión libre', 'Divorciado/a', 'Viudo/a'], Catalogo::valores('estado_civil'));
        $this->assertSame(['sin_llamar', 'contactado', 'confirmado', 'comprometido', 'no_responde'], Catalogo::valores('nivel_confianza'));
        $this->assertSame('Sin llamar', Catalogo::etiqueta('nivel_confianza', 'sin_llamar'));
        $this->assertSame(['rural' => 'Rural', 'urbana' => 'Urbana'], Catalogo::mapa('zona'));
        $this->assertContains('Profesional', Catalogo::valores('escolaridad', true));
        $this->assertNotContains('Profesional', Catalogo::valores('escolaridad'));
    }

    public function test_superadmin_adds_renames_switches_off_and_deletes_options(): void
    {
        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/estado_civil/opciones', ['etiqueta' => ' Separado/a '])
            ->assertCreated()->assertJsonPath('data.valor', 'Separado/a')->assertJsonPath('data.orden', 6);
        $this->assertContains('Separado/a', Catalogo::valores('estado_civil'));

        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/estado_civil/opciones', ['etiqueta' => 'separado/a'])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/estado_civil/opciones', ['etiqueta' => '  '])
            ->assertStatus(422)->assertJsonValidationErrors('etiqueta');

        $op = $this->opcion('estado_civil', 'Separado/a');
        $this->actingAs($this->super)->putJson("/admin/configuracion/opciones/{$op->id}", ['etiqueta' => 'Separado(a)', 'color' => '#AA0000'])
            ->assertOk()->assertJsonPath('data.etiqueta', 'Separado(a)')->assertJsonPath('data.valor', 'Separado/a');
        $this->actingAs($this->super)->putJson("/admin/configuracion/opciones/{$op->id}", ['color' => 'rojo'])->assertJsonValidationErrors('color');

        $this->actingAs($this->super)->putJson("/admin/configuracion/opciones/{$op->id}", ['activo' => false])->assertOk();
        $this->assertNotContains('Separado/a', Catalogo::valores('estado_civil'));
        $this->assertSame('Separado(a)', Catalogo::etiqueta('estado_civil', 'Separado/a'));

        // In use: never deleted, only switched off.
        $this->lider(Campaign::DEFAULT_ID, 'Con Estado', '1001', ['estado_civil' => 'Separado/a']);
        $this->actingAs($this->super)->deleteJson("/admin/configuracion/opciones/{$op->id}")
            ->assertStatus(422)->assertJsonPath('message', '«Separado(a)» está en uso en fichas guardadas: desactívela en lugar de borrarla.');
        DB::table('lideres')->where('cedula', '1001')->delete();
        $this->actingAs($this->super)->deleteJson("/admin/configuracion/opciones/{$op->id}")->assertOk();
        $this->assertDatabaseMissing('catalogo_opciones', ['id' => $op->id]);

        $this->assertSame(['crear', 'editar', 'desactivar', 'eliminar'], ConfigCambio::orderBy('created_at')->orderBy('id')->get()
            ->sortBy(fn ($c) => array_search($c->accion, ['crear', 'editar', 'desactivar', 'eliminar']))->pluck('accion')->values()->all());
        $this->assertSame(4, DB::table('audit_logs')->where('entity_type', 'configuracion')->count());
    }

    public function test_codes_lists_keep_their_values_and_defaults_cannot_be_switched_off(): void
    {
        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/nivel_confianza/opciones', ['etiqueta' => 'Indeciso'])
            ->assertStatus(422);
        $sinLlamar = $this->opcion('nivel_confianza', 'sin_llamar');
        $this->actingAs($this->super)->putJson("/admin/configuracion/opciones/{$sinLlamar->id}", ['activo' => false])->assertStatus(422);
        $this->actingAs($this->super)->deleteJson('/admin/configuracion/opciones/'.$this->opcion('zona', 'rural')->id)->assertStatus(422);

        // Renaming a code changes only what is shown.
        $this->actingAs($this->super)->putJson("/admin/configuracion/opciones/{$sinLlamar->id}", ['etiqueta' => 'Pendiente de llamar'])->assertOk();
        $this->assertSame('Pendiente de llamar', Catalogo::etiqueta('nivel_confianza', 'sin_llamar'));
        $this->assertContains('sin_llamar', Catalogo::valores('nivel_confianza'));

        // A new rol de miembro gets a code from its name.
        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/rol_miembro/opciones', ['etiqueta' => 'Junta directiva'])
            ->assertCreated()->assertJsonPath('data.valor', 'junta_directiva');
    }

    public function test_reorder_saves_the_new_order_and_rejects_a_stale_list(): void
    {
        $ids = CatalogoOpcion::where('catalogo', 'zona')->orderBy('orden')->pluck('id')->all();
        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/zona/orden', ['ids' => array_reverse($ids)])->assertOk();
        $this->assertSame(['urbana', 'rural'], Catalogo::valores('zona'));

        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/zona/orden', ['ids' => [$ids[0]]])->assertStatus(422);
    }

    // --- Validation of the fichas ---

    public function test_a_switched_off_option_does_not_break_old_fichas_but_is_not_accepted_for_others(): void
    {
        $user = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes', 'write.data']);
        $vieja = $this->lider(Campaign::DEFAULT_ID, 'Ficha Vieja', '2001', ['genero' => 'Otro', 'tipo_documento' => 'TE']);
        $otra = $this->lider(Campaign::DEFAULT_ID, 'Ficha Otra', '2002');
        $this->actingAs($this->super)->putJson('/admin/configuracion/opciones/'.$this->opcion('genero', 'Otro')->id, ['activo' => false])->assertOk();

        // The ficha that has it saves again with it (also TE, an Electo value that is off since the start).
        $this->actingAs($user)->putJson("/votantes/{$vieja}", ['genero' => 'Otro', 'tipo_documento' => 'TE', 'observacion' => 'Editada'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('lideres', ['id' => $vieja, 'genero' => 'Otro', 'observacion' => 'Editada']);

        // Another ficha cannot choose it, nor a value that was never an option.
        $this->actingAs($user)->putJson("/votantes/{$otra}", ['genero' => 'Otro'])->assertStatus(422)->assertJsonValidationErrors('genero');
        $this->actingAs($user)->putJson("/votantes/{$otra}", ['zona' => 'selva'])->assertStatus(422)->assertJsonValidationErrors('zona');
        $this->actingAs($user)->putJson("/votantes/{$otra}", ['genero' => 'Femenino', 'zona' => 'urbana'])->assertOk();
        $this->actingAs($user)->postJson('/mapa-politico/crear-lider', ['nombre' => 'Zz Nueva', 'municipio_id' => $this->giron, 'genero' => 'Otro'])
            ->assertStatus(422)->assertJsonValidationErrors('genero');
    }

    public function test_the_rule_accepts_empty_active_and_own_values_only(): void
    {
        $rules = OpcionDeCatalogo::enReglas(['genero' => 'nullable|string|max:20', 'zona' => 'nullable|in:rural,urbana'], [(object) ['genero' => 'Viejo']]);
        $this->assertFalse(collect($rules['zona'])->contains('in:rural,urbana'));

        $this->assertTrue(Validator::make(['genero' => null, 'zona' => ''], $rules)->passes());
        $this->assertTrue(Validator::make(['genero' => 'Viejo', 'zona' => 'rural'], $rules)->passes());
        $this->assertFalse(Validator::make(['genero' => 'Inventado'], $rules)->passes());
        $this->assertFalse(Validator::make(['genero' => ['Masculino']], $rules)->passes());
    }

    // --- Ajustes ---

    public function test_ajustes_validate_their_range_and_go_back_to_the_default(): void
    {
        $this->assertSame(200, Ajuste::int('mapa.tamano_pagina'));
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/mapa.tamano_pagina', ['valor' => 10])
            ->assertStatus(422)->assertJsonPath('errors.valor.0', 'El valor mínimo es 50.');
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/mapa.tamano_pagina', ['valor' => 9999])->assertStatus(422);
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/mapa.tamano_pagina', ['valor' => 'abc'])->assertStatus(422);
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/asistentes.edad_minima', ['valor' => 12])->assertStatus(422);
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/compromisos.hora_resumen', ['valor' => '07:10'])->assertStatus(422);
        $this->assertSame(0, ConfigCambio::count());

        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/mapa.tamano_pagina', ['valor' => 300])
            ->assertOk()->assertJsonPath('data.valor', 300)->assertJsonPath('data.cambiado', true);
        $this->assertSame(300, Ajuste::int('mapa.tamano_pagina'));

        $this->actingAs($this->super)->deleteJson('/admin/configuracion/ajustes/mapa.tamano_pagina')
            ->assertOk()->assertJsonPath('data.valor', 200)->assertJsonPath('data.cambiado', false);
        $this->assertDatabaseMissing('ajustes', ['clave' => 'mapa.tamano_pagina']);
        $this->assertSame(['editar', 'restablecer'], ConfigCambio::orderBy('created_at')->pluck('accion')->all());
    }

    public function test_ajustes_rule_the_code_that_used_constants(): void
    {
        config(['services.n8n.whatsapp_max_recipients' => 1234]);
        $this->assertSame(1234, WhatsAppController::maxRecipients());
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/whatsapp.max_destinatarios', ['valor' => 800])->assertOk();
        $this->assertSame(800, WhatsAppController::maxRecipients());

        // The module flag: the ajuste wins over the env value.
        config(['features.campanas_electorales.enabled' => false]);
        $this->assertFalse(Features::enabled('campanas'));
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/modulos.campanas_electorales', ['valor' => true])->assertOk();
        $this->assertTrue(Features::enabled('campanas'));

        // The daily e-mail goes out from the configured hour (Bogotá).
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/compromisos.hora_resumen', ['valor' => '18:45'])->assertOk();
        $this->assertTrue(CompromisosResumenDiario::toca(Carbon::parse('2026-11-19 18:45:00', 'America/Bogota')));
        $this->assertTrue(CompromisosResumenDiario::toca(Carbon::parse('2026-11-19 23:46:30', 'UTC')));
        $this->assertFalse(CompromisosResumenDiario::toca(Carbon::parse('2026-11-19 18:30:00', 'America/Bogota')));

        // The public form only accepts the configured minimum age.
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/asistentes.edad_minima', ['valor' => 16])->assertOk();
        $this->assertSame(16, AttendeeInput::minAge());
    }

    public function test_an_edit_forgets_the_cached_lists_and_ajustes(): void
    {
        $this->assertSame('Otro', Catalogo::etiqueta('genero', 'Otro'));
        $version = (int) Cache::get(CatalogoStore::VERSION_KEY, 0);
        $this->assertNotNull(Cache::get(CatalogoStore::CACHE_KEY.':'.$version));

        $this->actingAs($this->super)->putJson('/admin/configuracion/opciones/'.$this->opcion('genero', 'Otro')->id, ['etiqueta' => 'Otra identidad'])->assertOk();
        $this->assertSame($version + 1, (int) Cache::get(CatalogoStore::VERSION_KEY));
        $this->assertNull(Cache::get(CatalogoStore::CACHE_KEY.':'.($version + 1)));
        $this->app->forgetScopedInstances();
        $this->assertSame('Otra identidad', Catalogo::etiqueta('genero', 'Otro'));

        // The forms receive it in the shared prop.
        $this->actingAs($this->super)->get('/admin/configuracion')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('opcionesListas.genero.2.etiqueta', 'Otra identidad')
                ->where('ajustesPublicos.edad_minima_asistentes', 14));
    }

    // --- History ---

    public function test_revert_applies_the_previous_value_and_is_recorded(): void
    {
        $op = $this->opcion('parentesco', 'Otro');
        $this->actingAs($this->super)->putJson("/admin/configuracion/opciones/{$op->id}", ['etiqueta' => 'Otro familiar', 'activo' => false])->assertOk();
        $cambio = ConfigCambio::firstOrFail();

        $this->actingAs($this->super)->postJson("/admin/configuracion/historial/{$cambio->id}/revertir")->assertOk();
        $op->refresh();
        $this->assertSame(['Otro', true], [$op->etiqueta, $op->activo]);
        $this->assertSame(2, ConfigCambio::count());
        $revert = ConfigCambio::where('accion', 'revertir')->firstOrFail();
        $this->assertSame($cambio->id, $revert->revierte_id);
        $this->assertSame($revert->id, $cambio->fresh()->revertido_por_id);

        // Once only.
        $this->actingAs($this->super)->postJson("/admin/configuracion/historial/{$cambio->id}/revertir")
            ->assertStatus(422)->assertJsonPath('message', 'Este cambio ya se revirtió.');
        $this->actingAs($this->admin)->postJson("/admin/configuracion/historial/{$revert->id}/revertir")->assertForbidden();

        // An ajuste goes back to its previous value, or to the default when it had none.
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/votantes.export_max_filas', ['valor' => 20000])->assertOk();
        $this->actingAs($this->super)->putJson('/admin/configuracion/ajustes/votantes.export_max_filas', ['valor' => 30000])->assertOk();
        [$primero, $segundo] = ConfigCambio::where('referencia', 'votantes.export_max_filas')->orderBy('created_at')->orderBy('id')->get()->sortBy(fn ($c) => $c->despues['valor'])->values()->all();
        $this->actingAs($this->super)->postJson("/admin/configuracion/historial/{$segundo->id}/revertir")->assertOk();
        $this->assertSame(20000, Ajuste::int('votantes.export_max_filas'));
        $this->actingAs($this->super)->postJson("/admin/configuracion/historial/{$primero->id}/revertir")->assertOk();
        $this->assertSame(50000, Ajuste::int('votantes.export_max_filas'));
        $this->assertFalse(Ajuste::guardado('votantes.export_max_filas'));

        // Reverting a creation removes the option when nobody uses it; a deletion brings it back.
        $this->actingAs($this->super)->postJson('/admin/configuracion/listas/genero/opciones', ['etiqueta' => 'Prefiere no decir'])->assertCreated();
        $creacion = ConfigCambio::where('accion', 'crear')->firstOrFail();
        $this->actingAs($this->super)->postJson("/admin/configuracion/historial/{$creacion->id}/revertir")->assertOk();
        $this->assertNotContains('Prefiere no decir', Catalogo::valores('genero', true));

        $this->actingAs($this->super)->get('/admin/configuracion/historial?tipo=ajuste')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Configuracion/Historial')->has('cambios.data', 4));
    }
}
