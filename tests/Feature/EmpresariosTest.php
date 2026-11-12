<?php

namespace Tests\Feature;

use App\Actions\Votantes\MergeLideres;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Eventos\FichaActivity;
use App\Support\Nit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// Empresarios y gremios (docs/empresarios.md): CRUD, NIT, members, empresa ↔ gremio, actas of an organization with
// compromisos, Excel load, export, permissions and campaign/territory isolation. SQLite.
// Each test in its own process: the suite already runs close to the 256 MB limit of the shared process.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class EmpresariosTest extends TestCase
{
    use BuildsEventosScenario;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        (require database_path('migrations/2026_11_10_000001_create_event_actas_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_created_flags_to_event_acta_participantes.php'))->up();
        (require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php'))->up();
        Storage::fake('local');
        $this->manager = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios', 'view.votantes']);
    }

    private function catalog(string $tipo, string $nombre): string
    {
        return DB::table('org_catalogos')->whereNull('campaign_id')->where('tipo', $tipo)->where('nombre', $nombre)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tipo' => 'empresa',
            'nombre' => 'Lácteos del Valle SAS',
            'nit' => '890.903.938-8',
            'representante_nombre' => 'Rosa Representante',
            'representante_celular' => '3001112233',
            'telefono' => '6076543210',
            'correo' => 'contacto@lacteos.test',
            'sector_economico_id' => $this->catalog('sector_economico', 'Industria/Manufactura'),
            'sector_id' => $this->catalog('sector', 'Privado'),
            'municipio_id' => $this->giron,
            'empleados' => 25,
        ], $overrides);
    }

    private function createOrg(array $overrides = [], ?User $user = null): string
    {
        return $this->actingAs($user ?? $this->manager)->postJson('/empresarios', $this->payload($overrides))->assertCreated()->json('data.id');
    }

    private function org(string $campaignId, string $nombre, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('organizaciones')->insert(array_merge([
            'id' => $id, 'campaign_id' => $campaignId, 'tipo' => 'empresa', 'nombre' => $nombre, 'municipio_id' => $this->giron,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    public function test_crud_and_listing(): void
    {
        $id = $this->createOrg();
        $this->assertDatabaseHas('organizaciones', ['id' => $id, 'nit' => '890903938', 'nit_dv' => '8', 'campaign_id' => Campaign::DEFAULT_ID, 'departamento' => 'Santander']);

        $this->actingAs($this->manager)->get('/empresarios')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Empresarios/Index')->has('data', 1)->where('data.0.nombre', 'Lácteos del Valle SAS')
            ->where('data.0.nit', '890.903.938-8')->where('data.0.sector_economico', 'Industria/Manufactura')
            ->where('data.0.municipio', 'Girón')->where('can.manage', true));

        $this->actingAs($this->manager)->getJson("/empresarios/{$id}")->assertOk()->assertJsonPath('data.can.edit', true)
            ->assertJsonPath('data.sector', 'Privado')->assertJsonPath('data.empleados', 25);

        $this->actingAs($this->manager)->putJson("/empresarios/{$id}", $this->payload(['nombre' => 'Lácteos del Valle', 'empleados' => null, 'empleados_rango' => '11-50']))
            ->assertOk()->assertJsonPath('data.nombre', 'Lácteos del Valle')->assertJsonPath('data.empleados_rango', '11-50');

        $this->actingAs($this->manager)->get('/empresarios?buscar=valle')->assertInertia(fn ($p) => $p->has('data', 1));
        $this->actingAs($this->manager)->get('/empresarios?tipo=gremio')->assertInertia(fn ($p) => $p->has('data', 0));
        $this->actingAs($this->manager)->get('/empresarios?buscar=890903')->assertInertia(fn ($p) => $p->has('data', 1));

        $this->actingAs($this->manager)->deleteJson("/empresarios/{$id}")->assertOk();
        $this->assertSoftDeleted('organizaciones', ['id' => $id]);
        $this->actingAs($this->manager)->get('/empresarios')->assertInertia(fn ($p) => $p->has('data', 0));
        $this->actingAs($this->manager)->getJson("/empresarios/{$id}")->assertNotFound();
    }

    public function test_the_metropolitan_area_is_one_province_option_with_all_its_municipios(): void
    {
        // Tona is in the stored province "Soto" but is not one of the metropolitan area's named municipios.
        $tona = $this->geo('municipality', 'Tona', $this->soto);
        $this->org(Campaign::DEFAULT_ID, 'En Tona', ['municipio_id' => $tona]);
        $this->org(Campaign::DEFAULT_ID, 'En Vélez', ['municipio_id' => $this->velez]);

        $this->actingAs($this->manager)->get('/empresarios')->assertInertia(fn ($p) => $p
            ->where('provincias', fn ($list) => collect($list)->filter(fn ($n) => in_array($n, ['Soto', 'Área Metropolitana', 'Area Metropolitana'], true))->values()->all() === ['Área Metropolitana']));
        $this->actingAs($this->manager)->get('/empresarios?provincia='.urlencode('Área Metropolitana'))
            ->assertInertia(fn ($p) => $p->has('data', 1)->where('data.0.nombre', 'En Tona'));
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['nombre' => '', 'tipo' => 'otra', 'correo' => 'no-es-correo', 'empleados' => -3]))
            ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'tipo', 'correo', 'empleados']);
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['municipio_id' => (string) Str::uuid()]))
            ->assertStatus(422)->assertJsonValidationErrors(['municipio_id']);
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['sector_id' => $this->catalog('sector_economico', 'Comercio')]))
            ->assertStatus(422)->assertJsonValidationErrors(['sector_id']);
        $this->assertSame(0, DB::table('organizaciones')->count());
    }

    public function test_nit_format_verification_digit_and_uniqueness_per_campaign(): void
    {
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['nit' => '890903938-3']))
            ->assertStatus(422)->assertJsonPath('errors.nit.0', 'El dígito de verificación del NIT no corresponde: revise el número.');
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['nit' => '12AB']))->assertStatus(422)->assertJsonValidationErrors(['nit']);
        $this->assertSame('890903938-8', Nit::unglue('8909039388'));
        $this->assertSame('8909039381', Nit::unglue('8909039381'), 'A last digit that is not the DV: a 10-digit NIT');

        $this->createOrg(['nit' => '890903938']);
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['nombre' => 'Otra', 'nit' => '890.903.938-8']))
            ->assertStatus(422)->assertJsonValidationErrors(['nit']);

        // Another campaign may hold the same NIT.
        $other = $this->eventUser($this->campY, ['manage.empresarios']);
        $this->createOrg(['nombre' => 'Otra', 'sector_economico_id' => null, 'sector_id' => null], $other);
        $this->assertSame(2, DB::table('organizaciones')->where('nit', '890903938')->count());
    }

    public function test_members_link_existing_ficha_or_create_one_and_show_in_the_persona_ficha(): void
    {
        $id = $this->createOrg();
        $existing = $this->lider(Campaign::DEFAULT_ID, 'Pedro Socio', '1098000111');
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['lider_id' => $existing, 'rol' => 'socio'])->assertCreated();
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['lider_id' => $existing, 'rol' => 'socio'])
            ->assertStatus(422)->assertJsonValidationErrors(['rol']);

        $before = DB::table('lideres')->count();
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['nombre' => 'Nueva Gerente', 'telefono' => '3115556677', 'municipio' => 'Lebrija', 'rol' => 'gerente'])
            ->assertCreated()->assertJsonPath('message', 'Miembro agregado: se creó su ficha en Votantes.');
        $this->assertSame($before + 1, DB::table('lideres')->count());
        $this->assertDatabaseHas('lideres', ['nombre' => 'Nueva Gerente', 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->lebrija]);

        // Same cédula in the campaign → linked, no new ficha.
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['nombre' => 'Pedro S.', 'cedula' => '1.098.000.111', 'rol' => 'afiliado'])->assertCreated();
        $this->assertSame($before + 1, DB::table('lideres')->count());

        // A ficha of another campaign cannot be linked.
        $foreign = $this->lider($this->campY, 'Ajeno', '555000');
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['lider_id' => $foreign, 'rol' => 'socio'])
            ->assertStatus(422)->assertJsonValidationErrors(['lider_id']);

        $this->actingAs($this->manager)->getJson("/empresarios/{$id}")->assertJsonCount(3, 'data.miembros');
        $this->actingAs($this->manager)->get('/empresarios')->assertInertia(fn ($p) => $p->where('data.0.miembros', 2));
        $this->actingAs($this->manager)->getJson("/empresarios/persona/{$existing}")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre', 'Lácteos del Valle SAS');

        $miembro = DB::table('organizacion_miembros')->where('lider_id', $existing)->where('rol', 'socio')->value('id');
        $this->actingAs($this->manager)->putJson("/empresarios/miembros/{$miembro}", ['rol' => 'empleado', 'nota' => 'Turno noche'])->assertOk();
        $this->actingAs($this->manager)->deleteJson("/empresarios/miembros/{$miembro}")->assertOk();
        $this->assertDatabaseMissing('organizacion_miembros', ['id' => $miembro]);
        $this->assertDatabaseHas('lideres', ['id' => $existing]);
    }

    public function test_representative_linked_to_a_ficha_becomes_a_member(): void
    {
        $rep = $this->lider(Campaign::DEFAULT_ID, 'Rosa Representante', '1098222333');
        $id = $this->createOrg(['representante_lider_id' => $rep]);
        $this->assertDatabaseHas('organizacion_miembros', ['organizacion_id' => $id, 'lider_id' => $rep, 'rol' => 'representante_legal']);

        $this->actingAs($this->manager)->putJson("/empresarios/{$id}", $this->payload(['representante_lider_id' => null]))->assertOk();
        $this->assertDatabaseMissing('organizacion_miembros', ['organizacion_id' => $id, 'lider_id' => $rep]);
    }

    public function test_empresa_belongs_to_gremios_and_the_gremio_lists_its_empresas(): void
    {
        $gremio = $this->createOrg(['tipo' => 'gremio', 'nombre' => 'Fenalco Santander', 'nit' => '860034313-7']);
        $empresa = $this->createOrg(['gremio_ids' => [$gremio]]);
        $this->assertDatabaseHas('organizacion_gremios', ['empresa_id' => $empresa, 'gremio_id' => $gremio]);

        $this->actingAs($this->manager)->getJson("/empresarios/{$gremio}")->assertJsonCount(1, 'data.empresas')->assertJsonPath('data.empresas.0.id', $empresa);
        $this->actingAs($this->manager)->getJson("/empresarios/{$empresa}")->assertJsonPath('data.gremios.0.nombre', 'Fenalco Santander');
        $this->actingAs($this->manager)->get("/empresarios?gremio={$gremio}")->assertInertia(fn ($p) => $p->has('data', 2)->where('data.1.gremios', ['Fenalco Santander']));
        $this->actingAs($this->manager)->get('/empresarios')->assertInertia(fn ($p) => $p->where('data.0.empresas', 1));

        // An empresa is not a gremio, and another campaign's gremio is refused.
        $otherGremio = $this->org($this->campY, 'Gremio ajeno', ['tipo' => 'gremio']);
        $this->actingAs($this->manager)->putJson("/empresarios/{$empresa}", $this->payload(['gremio_ids' => [$otherGremio]]))
            ->assertStatus(422)->assertJsonValidationErrors(['gremio_ids']);
        $this->actingAs($this->manager)->putJson("/empresarios/{$empresa}", $this->payload(['gremio_ids' => []]))->assertOk();
        $this->assertDatabaseMissing('organizacion_gremios', ['empresa_id' => $empresa]);
    }

    public function test_acta_of_an_organization_with_compromisos_and_follow_up(): void
    {
        $id = $this->createOrg();
        $already = $this->lider(Campaign::DEFAULT_ID, 'Ya Miembro', '1098333444');
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['lider_id' => $already, 'rol' => 'socio'])->assertCreated();

        $this->actingAs($this->manager)->get("/empresarios/{$id}/actas/nueva")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Eventos/Acta')->where('origen.tipo', 'organizacion')->where('can.edit', true)->where('asistentes', []));

        $acta = $this->actingAs($this->manager)->postJson("/empresarios/{$id}/actas", [
            'titulo' => 'Reunión de junta',
            'fecha' => '2026-11-10',
            'participantes' => [
                ['nombre' => 'Ya Miembro', 'cedula' => '1098333444'],
                ['nombre' => 'Persona Nueva', 'telefono' => '3201234567', 'municipio' => 'Girón'],
            ],
            'compromisos' => [['descripcion' => 'Enviar propuesta', 'fecha_limite' => '2026-11-01', 'responsables' => [['nombre' => 'Texto Responsable']]]],
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('event_actas', ['id' => $acta, 'organizacion_id' => $id, 'event_id' => null, 'estado' => 'borrador']);
        $this->assertDatabaseHas('event_compromisos', ['acta_id' => $acta, 'organizacion_id' => $id, 'event_id' => null]);

        $confirm = $this->actingAs($this->manager)->postJson("/empresarios/{$id}/actas/{$acta}/confirmar")->assertOk();
        $confirm->assertJsonPath('resumen.fichas_creadas', 1)->assertJsonPath('resumen.vinculados', 1)->assertJsonPath('resumen.miembros_nuevos', 1);
        $nueva = DB::table('lideres')->where('nombre', 'Persona Nueva')->value('id');
        $this->assertDatabaseHas('organizacion_miembros', ['organizacion_id' => $id, 'lider_id' => $nueva, 'rol' => 'otro']);
        $this->assertSame(1, DB::table('organizacion_miembros')->where('lider_id', $already)->count(), 'Already a member: no extra membership');
        $this->assertSame(0, DB::table('event_attendees')->count(), 'An organization has no attendees');

        // Compromisos screen: origin filter, follow-up with manage.empresarios.
        $compromiso = DB::table('event_compromisos')->where('acta_id', $acta)->value('id');
        $this->actingAs($this->manager)->get('/eventos/compromisos?origen=organizacion')->assertOk()->assertInertia(fn ($p) => $p
            ->has('compromisos.data', 1)->where('compromisos.data.0.origen', 'organizacion')
            ->where('compromisos.data.0.organizacion.nombre', 'Lácteos del Valle SAS')->where('compromisos.data.0.editable', true)
            ->where('compromisos.data.0.vencido', true));
        $this->actingAs($this->manager)->get('/eventos/compromisos?origen=evento')->assertInertia(fn ($p) => $p->has('compromisos.data', 0));
        $this->actingAs($this->manager)->putJson("/eventos/compromisos/{$compromiso}/estado", ['estado' => 'cumplido'])->assertOk();
        $this->assertDatabaseHas('event_compromisos', ['id' => $compromiso, 'estado' => 'cumplido']);
        $this->actingAs($this->manager)->getJson('/eventos/compromisos/alertas')->assertOk();

        // A viewer of events only does not see the organization's compromisos.
        $eventos = $this->eventUser(Campaign::DEFAULT_ID, ['view.eventos', 'manage.eventos']);
        $this->actingAs($eventos)->get('/eventos/compromisos')->assertInertia(fn ($p) => $p->has('compromisos.data', 0));
        $this->actingAs($eventos)->getJson("/eventos/compromisos/{$compromiso}")->assertNotFound();
        // view.empresarios only: sees it, cannot change it.
        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.empresarios']);
        $this->actingAs($viewer)->getJson("/eventos/compromisos/{$compromiso}")->assertOk()->assertJsonPath('data.editable', false);
        $this->actingAs($viewer)->putJson("/eventos/compromisos/{$compromiso}/estado", ['estado' => 'pendiente'])->assertForbidden();

        // Confirmed: read-only; downloads work.
        $this->actingAs($this->manager)->putJson("/empresarios/{$id}/actas/{$acta}", ['titulo' => 'Cambio'])->assertStatus(422);
        $this->actingAs($this->manager)->get("/empresarios/{$id}/actas/{$acta}/acta.docx")->assertOk();
        $this->actingAs($this->manager)->getJson("/empresarios/{$id}/actas")->assertJsonPath('data.0.estado', 'confirmada');
    }

    public function test_deleting_a_confirmed_organization_acta_undoes_its_members_and_fichas(): void
    {
        $id = $this->createOrg();
        $deleter = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios', 'view.empresarios', 'delete.actas']);
        $acta = $this->actingAs($deleter)->postJson("/empresarios/{$id}/actas", ['titulo' => 'Junta', 'participantes' => [['nombre' => 'Temporal Uno']]])->json('data.id');
        $this->actingAs($deleter)->postJson("/empresarios/{$id}/actas/{$acta}/confirmar")->assertOk();
        $ficha = DB::table('lideres')->where('nombre', 'Temporal Uno')->value('id');
        $this->assertNotNull($ficha);

        $this->actingAs($this->manager)->deleteJson("/empresarios/{$id}/actas/{$acta}")->assertForbidden();
        $this->actingAs($deleter)->getJson("/empresarios/{$id}/actas/{$acta}/eliminar")->assertOk()->assertJsonPath('data.miembros', 1)->assertJsonPath('data.fichas_borrar', 1);
        $this->actingAs($deleter)->deleteJson("/empresarios/{$id}/actas/{$acta}")->assertOk();
        $this->assertDatabaseMissing('event_actas', ['id' => $acta]);
        $this->assertDatabaseMissing('lideres', ['id' => $ficha]);
        $this->assertSame(0, DB::table('organizacion_miembros')->where('organizacion_id', $id)->count());
    }

    public function test_an_undo_keeps_a_ficha_that_is_member_of_an_organization(): void
    {
        $id = $this->createOrg();
        $ficha = $this->lider(Campaign::DEFAULT_ID, 'Con Empresa', null);
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['lider_id' => $ficha, 'rol' => 'socio'])->assertCreated();
        $this->assertSame([], app(FichaActivity::class)->free([$ficha]));
    }

    public function test_merging_two_fichas_moves_their_memberships(): void
    {
        $id = $this->createOrg();
        $a = $this->lider(Campaign::DEFAULT_ID, 'Juan Pérez', '1098111222');
        $b = $this->lider(Campaign::DEFAULT_ID, 'Juan Perez Gomez', null);
        foreach ([[$a, 'socio'], [$b, 'socio'], [$b, 'gerente']] as [$lider, $rol]) {
            $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['lider_id' => $lider, 'rol' => $rol])->assertCreated();
        }
        // The rest of what MergeLideres moves, minimal.
        foreach (['lider_info_politica' => ['lider_id'], 'lider_seguimientos' => ['lider_id'], 'lider_municipios_influencia' => ['lider_id', 'geographic_unit_id'],
            'nexos_familiares' => ['person_id'], 'whatsapp_send_recipients' => ['person_id'], 'favorites' => ['favoritable_id', 'user_id'],
            'recent_views' => ['viewable_id'], 'taggables' => ['taggable_id', 'tag_id']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t) use ($columns) {
                    $t->uuid('id')->primary();
                    foreach ($columns as $c) {
                        $t->uuid($c)->nullable();
                    }
                });
            }
        }
        $this->actingAs($this->manager);
        request()->setUserResolver(fn () => $this->manager);
        $kept = app(MergeLideres::class)->handle($a, $b);
        $roles = DB::table('organizacion_miembros')->where('organizacion_id', $id)->orderBy('rol')->get(['lider_id', 'rol']);
        $this->assertSame(['gerente', 'socio'], $roles->pluck('rol')->all(), 'The same role is kept once');
        $this->assertSame([$kept->id], $roles->pluck('lider_id')->unique()->values()->all());
    }

    public function test_the_actas_migration_is_reversible_and_event_actas_keep_their_event(): void
    {
        $migration = require database_path('migrations/2026_11_13_000002_add_organizacion_to_event_actas.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('event_actas', 'organizacion_id'));
        $this->assertFalse(Schema::hasColumn('event_acta_participantes', 'created_miembro'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('event_compromisos', 'organizacion_id'));

        $orgs = require database_path('migrations/2026_11_13_000001_create_organizaciones_tables.php');
        $migration->down();
        $orgs->down();
        $this->assertFalse(Schema::hasTable('organizaciones'));
        $orgs->up();
        $migration->up();
        $this->assertSame(18, DB::table('org_catalogos')->count());
    }

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'org').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'empresas.xlsx', null, null, true);
    }

    public function test_excel_load_with_review_duplicates_and_gremio(): void
    {
        $existing = $this->createOrg(['correo' => null, 'telefono' => null]);
        $this->actingAs($this->manager)->get('/empresarios/plantilla.xlsx')->assertOk();

        $upload = $this->actingAs($this->manager)->post('/empresarios/carga', ['archivo' => $this->xlsx([
            ['Tipo', 'Nombre o razón social', 'NIT', 'Representante legal', 'Celular', 'Correo', 'Sector económico', 'Municipio', 'Número de empleados', 'Gremio al que pertenece'],
            ['Gremio', 'Asociación de Comerciantes', '860034313-7', '', '', '', 'Comercio', 'Girón', '', ''],
            ['Empresa', 'Nueva Tienda', '', 'Ana Dueña', '3001234567', '', 'Comercio', 'Lebrija', '11-50', 'Asociación de Comerciantes'],
            ['Empresa', 'Lácteos del Valle SAS', '890903938', '', '6070000000', 'nuevo@lacteos.test', '', 'Girón', '', ''],
            ['Empresa', 'Con Error', '123-4', '', '', '', 'Sector Inventado', 'Ciudad X', 'muchos', ''],
        ])], ['Accept' => 'application/json'])->assertCreated();
        $importId = $upload->json('data.id');

        $page = $this->actingAs($this->manager)->get("/empresarios/carga/{$importId}")->assertOk()->assertInertia(fn ($p) => $p
            ->component('Empresarios/Importar')->where('carga.conteo.crear', 2)->where('carga.conteo.completar', 1)->where('carga.conteo.error', 1));
        $rows = DB::table('organizacion_import_rows')->where('import_id', $importId)->orderBy('row_number')->get();
        $this->assertSame($existing, $rows[2]->existente_id);
        $errores = json_decode($rows[3]->errores, true);
        $this->assertArrayHasKey('nit', $errores);
        $this->assertArrayHasKey('sector_economico_id', $errores);
        $this->assertArrayHasKey('municipio_id', $errores);
        $this->assertArrayHasKey('empleados', $errores);

        // Correct the error row.
        $this->actingAs($this->manager)->patchJson("/empresarios/carga/{$importId}/filas/{$rows[3]->id}", ['datos' => [
            'nit' => '899999068-1', 'sector_economico' => 'Minería y energía', 'municipio' => 'Vélez', 'empleados' => '40',
        ]])->assertOk()->assertJsonPath('data.carga.conteo.error', 0);

        $this->actingAs($this->manager)->postJson("/empresarios/carga/{$importId}/confirmar")->assertOk()
            ->assertJsonPath('data.creadas', 3)->assertJsonPath('data.completadas', 1)->assertJsonPath('data.gremios', 1);

        // The existing one: only empty fields filled, nothing overwritten.
        $this->assertDatabaseHas('organizaciones', ['id' => $existing, 'correo' => 'nuevo@lacteos.test', 'telefono' => '6070000000', 'nombre' => 'Lácteos del Valle SAS']);
        $tienda = DB::table('organizaciones')->where('nombre', 'Nueva Tienda')->first();
        $this->assertSame('11-50', $tienda->empleados_rango);
        $gremio = DB::table('organizaciones')->where('nombre', 'Asociación de Comerciantes')->value('id');
        $this->assertDatabaseHas('organizacion_gremios', ['empresa_id' => $tienda->id, 'gremio_id' => $gremio]);
        $this->actingAs($this->manager)->postJson("/empresarios/carga/{$importId}/confirmar")->assertStatus(422);
    }

    public function test_excel_load_skip_choice_and_discard(): void
    {
        $existing = $this->createOrg(['correo' => null]);
        $id = $this->actingAs($this->manager)->post('/empresarios/carga', ['archivo' => $this->xlsx([
            ['Tipo', 'Nombre', 'NIT', 'Correo'],
            ['empresa', 'Repetida', '890903938-8', 'x@y.test'],
        ])], ['Accept' => 'application/json'])->json('data.id');
        $row = DB::table('organizacion_import_rows')->where('import_id', $id)->first();
        $this->actingAs($this->manager)->patchJson("/empresarios/carga/{$id}/filas/{$row->id}", ['accion' => 'saltar'])->assertOk()->assertJsonPath('data.carga.conteo.saltar', 1);
        $this->actingAs($this->manager)->postJson("/empresarios/carga/{$id}/confirmar")->assertOk()->assertJsonPath('data.saltadas', 1);
        $this->assertDatabaseHas('organizaciones', ['id' => $existing, 'correo' => null]);

        $other = $this->actingAs($this->manager)->post('/empresarios/carga', ['archivo' => $this->xlsx([['Tipo', 'Nombre'], ['empresa', 'Descartada']])], ['Accept' => 'application/json'])->json('data.id');
        $this->actingAs($this->manager)->deleteJson("/empresarios/carga/{$other}")->assertOk();
        $this->assertDatabaseMissing('organizaciones', ['nombre' => 'Descartada']);
        $this->actingAs($this->manager)->post('/empresarios/carga', ['archivo' => $this->xlsx([['Columna', 'Otra'], ['a', 'b']])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['archivo']);
    }

    public function test_excel_load_name_match_with_another_nit_is_an_error_and_nit_dv_completes_only_the_same_nit(): void
    {
        $this->createOrg(['nit' => '890903938', 'correo' => null]);
        $id = $this->actingAs($this->manager)->post('/empresarios/carga', ['archivo' => $this->xlsx([
            ['Tipo', 'Nombre', 'NIT', 'Municipio', 'Correo'],
            ['empresa', 'Lácteos del Valle SAS', '860034313', 'Girón', 'a@b.test'],
            ['empresa', 'Otra Con DV', '8909039388', 'Lebrija', 'c@d.test'],
        ])], ['Accept' => 'application/json'])->json('data.id');
        $rows = DB::table('organizacion_import_rows')->where('import_id', $id)->orderBy('row_number')->get();
        $this->assertSame('error', $rows[0]->accion);
        $this->assertStringContainsString('otro NIT', json_decode($rows[0]->errores, true)['nit']);
        // "8909039388" is the NIT 890903938 with its digit glued: the same organization.
        $this->assertSame('completar', $rows[1]->accion);
        $this->actingAs($this->manager)->postJson("/empresarios/carga/{$id}/confirmar")->assertOk()->assertJsonPath('data.completadas', 1);
        $org = DB::table('organizaciones')->where('nit', '890903938')->first();
        $this->assertSame('8', $org->nit_dv);
        $this->assertSame('Lácteos del Valle SAS', $org->nombre);
        $this->assertSame('c@d.test', $org->correo);
        $this->assertSame(1, DB::table('organizaciones')->count());
    }

    public function test_a_load_is_only_seen_by_its_uploader_or_a_user_without_territory(): void
    {
        $id = $this->actingAs($this->manager)->post('/empresarios/carga', ['archivo' => $this->xlsx([['Tipo', 'Nombre'], ['empresa', 'Una']])], ['Accept' => 'application/json'])->json('data.id');
        $field = $this->eventUser(Campaign::DEFAULT_ID, ['view.empresarios', 'manage.empresarios'], 'R09_CAMPO', [$this->giron]);
        $this->actingAs($field)->get("/empresarios/carga/{$id}")->assertNotFound();
        $this->actingAs($field)->postJson("/empresarios/carga/{$id}/confirmar")->assertNotFound();
        $admin = $this->eventUser(Campaign::DEFAULT_ID, ['manage.empresarios']);
        $this->actingAs($admin)->get("/empresarios/carga/{$id}")->assertOk();
    }

    public function test_the_linked_representative_is_kept_when_a_territory_user_edits(): void
    {
        $rep = $this->lider(Campaign::DEFAULT_ID, 'Rep Vélez', '1098555666', ['geographic_unit_id' => $this->velez, 'municipio' => 'Vélez']);
        $id = $this->createOrg(['representante_lider_id' => $rep]);
        $field = $this->eventUser(Campaign::DEFAULT_ID, ['view.empresarios', 'manage.empresarios'], 'R09_CAMPO', [$this->giron]);
        $payload = $this->payload(['nombre' => 'Editada', 'nit' => '890903938-8']);
        unset($payload['representante_lider_id']);
        $this->actingAs($field)->putJson("/empresarios/{$id}", $payload)->assertOk();
        $this->actingAs($field)->putJson("/empresarios/{$id}", $payload + ['representante_lider_id' => $rep])->assertOk();
        $this->assertDatabaseHas('organizaciones', ['id' => $id, 'nombre' => 'Editada', 'representante_lider_id' => $rep]);
        $this->assertDatabaseHas('organizacion_miembros', ['organizacion_id' => $id, 'lider_id' => $rep, 'rol' => 'representante_legal']);
    }

    public function test_a_gremio_turned_empresa_loses_its_affiliated_empresas(): void
    {
        $gremio = $this->createOrg(['tipo' => 'gremio', 'nombre' => 'Gremio', 'nit' => null]);
        $empresa = $this->createOrg(['gremio_ids' => [$gremio]]);
        $this->assertDatabaseHas('organizacion_gremios', ['gremio_id' => $gremio]);
        $this->actingAs($this->manager)->putJson("/empresarios/{$gremio}", $this->payload(['tipo' => 'empresa', 'nombre' => 'Gremio', 'nit' => null]))->assertOk();
        $this->assertDatabaseMissing('organizacion_gremios', ['gremio_id' => $gremio]);
        $this->assertDatabaseHas('organizaciones', ['id' => $empresa]);
    }

    public function test_export_excel_and_pdf_of_the_filtered_list(): void
    {
        $this->createOrg();
        $this->createOrg(['tipo' => 'gremio', 'nombre' => 'Gremio X', 'nit' => null]);
        $this->actingAs($this->manager)->get('/empresarios/exportar.xlsx?tipo=empresa')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=empresarios-y-gremios.xlsx');
        $this->actingAs($this->manager)->get('/empresarios/exportar.pdf')->assertOk();
        $log = DB::table('audit_logs')->where('action', 'ORGANIZACIONES_EXPORTADAS')->first();
        $this->assertSame(1, json_decode($log->metadata, true)['filas']);
    }

    public function test_permissions(): void
    {
        $id = $this->createOrg();
        $nobody = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa']);
        $this->actingAs($nobody)->get('/empresarios')->assertRedirect('/');
        $this->actingAs($nobody)->getJson("/empresarios/{$id}")->assertForbidden();

        $viewer = $this->eventUser(Campaign::DEFAULT_ID, ['view.empresarios']);
        $this->actingAs($viewer)->get('/empresarios')->assertOk()->assertInertia(fn ($p) => $p->where('can.manage', false)->has('data', 1));
        $this->actingAs($viewer)->getJson("/empresarios/{$id}")->assertOk()->assertJsonPath('data.can.edit', false);
        $this->actingAs($viewer)->postJson('/empresarios', $this->payload(['nit' => null]))->assertForbidden();
        $this->actingAs($viewer)->putJson("/empresarios/{$id}", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/empresarios/{$id}")->assertForbidden();
        $this->actingAs($viewer)->get('/empresarios/plantilla.xlsx')->assertRedirect('/');
        $this->actingAs($viewer)->postJson('/empresarios/carga', [])->assertForbidden();
        $this->actingAs($viewer)->get("/empresarios/{$id}/actas/nueva")->assertRedirect('/');

        $this->actingAs($viewer)->get('/')->assertRedirect('/empresarios');
    }

    public function test_campaign_and_territory_isolation(): void
    {
        $sub = $this->org($this->campX, 'De la subcampaña');
        $foreign = $this->org($this->campY, 'De otra campaña');
        $velez = $this->org(Campaign::DEFAULT_ID, 'En Vélez', ['municipio_id' => $this->velez]);

        $this->actingAs($this->manager)->get('/empresarios')->assertInertia(fn ($p) => $p->has('data', 2));
        $this->actingAs($this->manager)->getJson("/empresarios/{$sub}")->assertOk()->assertJsonPath('data.can.edit', false);
        $this->actingAs($this->manager)->putJson("/empresarios/{$sub}", $this->payload(['nit' => null]))->assertForbidden();
        $this->actingAs($this->manager)->postJson("/empresarios/{$sub}/miembros", ['nombre' => 'X', 'rol' => 'otro'])->assertForbidden();
        $this->actingAs($this->manager)->getJson("/empresarios/{$foreign}")->assertNotFound();
        $this->actingAs($this->manager)->deleteJson("/empresarios/{$foreign}")->assertNotFound();

        // Subcampaign user sees only its own.
        $subUser = $this->eventUser($this->campX, ['view.empresarios', 'manage.empresarios']);
        $this->actingAs($subUser)->get('/empresarios')->assertInertia(fn ($p) => $p->has('data', 1)->where('data.0.nombre', 'De la subcampaña'));
        $this->actingAs($subUser)->getJson("/empresarios/{$velez}")->assertNotFound();

        // Territory: only Girón (the subcampaign's organization there is visible; the one in Vélez is not).
        $field = $this->eventUser(Campaign::DEFAULT_ID, ['view.empresarios', 'manage.empresarios'], 'R09_CAMPO', [$this->giron]);
        $this->actingAs($field)->get('/empresarios')->assertInertia(fn ($p) => $p->has('data', 1)->where('data.0.nombre', 'De la subcampaña'));
        $this->actingAs($field)->getJson("/empresarios/{$velez}")->assertForbidden();
        $this->actingAs($field)->postJson('/empresarios', $this->payload(['nit' => null, 'municipio_id' => $this->velez]))
            ->assertStatus(422)->assertJsonValidationErrors(['municipio_id']);
        $this->actingAs($field)->postJson('/empresarios', $this->payload(['nit' => null, 'municipio_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['municipio_id']);
        $this->actingAs($field)->postJson('/empresarios', $this->payload(['nit' => null]))->assertCreated();
    }

    public function test_audit_has_no_personal_data(): void
    {
        $id = $this->createOrg();
        $this->actingAs($this->manager)->postJson("/empresarios/{$id}/miembros", ['nombre' => 'Nombre Secreto', 'cedula' => '1098765432', 'rol' => 'socio'])->assertCreated();
        $all = DB::table('audit_logs')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('Nombre Secreto', $all);
        $this->assertStringNotContainsString('1098765432', $all);
        $this->assertStringNotContainsString('Rosa Representante', $all);
        $this->assertStringNotContainsString('3001112233', $all);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'ORGANIZACION_CREADA')->exists());
    }

    public function test_catalog_values_of_the_campaign(): void
    {
        $res = $this->actingAs($this->manager)->postJson('/empresarios/catalogos', ['tipo' => 'sector_economico', 'nombre' => 'Economía naranja'])->assertCreated();
        $nuevo = collect($res->json('data.sector_economico'))->firstWhere('nombre', 'Economía naranja');
        $this->assertTrue($nuevo['editable']);
        $this->actingAs($this->manager)->postJson('/empresarios/catalogos', ['tipo' => 'sector_economico', 'nombre' => 'comercio'])->assertStatus(422);
        $shared = $this->catalog('sector', 'Mixto');
        $this->actingAs($this->manager)->putJson("/empresarios/catalogos/{$shared}", ['nombre' => 'Mixto', 'is_active' => false])->assertForbidden();
        $this->actingAs($this->manager)->putJson("/empresarios/catalogos/{$nuevo['id']}", ['nombre' => 'Economía naranja', 'is_active' => false])->assertOk();
        $this->actingAs($this->manager)->postJson('/empresarios', $this->payload(['nit' => null, 'sector_economico_id' => $nuevo['id']]))->assertStatus(422);
    }
}
