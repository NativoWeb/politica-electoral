<?php

namespace Tests\Feature;

use App\Models\ElectoralCampaign;
use App\Support\CurrentCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsCampanasScenario;
use Tests\TestCase;

// Campañas electorales (docs/campanas-electorales.md) on SQLite with synthetic data: the flag, who sees and who
// changes a campaign (group, subgroup, superadmin's header, territory), create/edit/archive/reactivate with their
// validations, and the audit.
class CampanasElectoralesTest extends TestCase
{
    use BuildsCampanasScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampanas();
        $this->flag(true);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Gobernación 2027', 'cargo' => 'gobernacion', 'election_year' => 2027,
            'scope_level' => 'department', 'territorio_ids' => [],
        ], $over);
    }

    // ---------------------------------------------------------------- flag

    public function test_flag_off_answers_404_on_every_route_also_to_the_superadmin(): void
    {
        $c = $this->campana();
        $this->flag(false);
        $super = $this->superadmin();
        $id = $c->id;
        $requests = [
            ['get', '/campanas-electorales'], ['get', '/campanas-electorales/nueva'], ['get', '/campanas-electorales/opciones/candidatos?q=ana'],
            ['post', '/campanas-electorales'], ['get', "/campanas-electorales/{$id}"], ['get', "/campanas-electorales/{$id}/editar"],
            ['put', "/campanas-electorales/{$id}"], ['post', "/campanas-electorales/{$id}/archivar"], ['post', "/campanas-electorales/{$id}/reactivar"],
            ['put', "/campanas-electorales/{$id}/metas"], ['get', "/campanas-electorales/{$id}/metas/plantilla"],
            ['post', "/campanas-electorales/{$id}/metas/importar"], ['get', "/campanas-electorales/{$id}/historial"],
        ];
        foreach ($requests as [$method, $url]) {
            $this->actingAs($super)->json($method, $url, $method === 'get' ? [] : $this->payload())->assertNotFound();
        }
        $this->assertSame(1, ElectoralCampaign::count());
    }

    public function test_beta_list_leaves_out_everyone_else_with_404(): void
    {
        $beta = $this->manager();
        $otro = $this->manager();
        $this->flag(true, [$beta->id]);

        $this->actingAs($beta)->getJson('/campanas-electorales')->assertOk();
        $this->actingAs($otro)->getJson('/campanas-electorales')->assertNotFound();
        $this->actingAs($otro)->postJson('/campanas-electorales', $this->payload())->assertNotFound();
    }

    // ---------------------------------------------------------------- permissions

    public function test_without_permission_403_and_view_only_cannot_write(): void
    {
        $c = $this->campana();
        $sin = $this->eventUser($this->cd, ['view.mapa']);
        $viewer = $this->viewer();

        $this->actingAs($sin)->getJson('/campanas-electorales')->assertForbidden();
        $this->actingAs($sin)->getJson("/campanas-electorales/{$c->id}")->assertForbidden();

        $this->actingAs($viewer)->getJson("/campanas-electorales/{$c->id}")->assertOk()->assertJsonPath('data.can.edit', false)
            ->assertJsonPath('data.soloLectura.motivo', 'permiso');
        $this->actingAs($viewer)->postJson('/campanas-electorales', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->putJson("/campanas-electorales/{$c->id}", $this->payload(['name' => 'Otro']))->assertForbidden();
        $this->actingAs($viewer)->postJson("/campanas-electorales/{$c->id}/archivar")->assertForbidden();
        $this->actingAs($viewer)->putJson("/campanas-electorales/{$c->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 5]]])->assertForbidden();
        $this->actingAs($viewer)->getJson("/campanas-electorales/{$c->id}/metas/plantilla")->assertForbidden();
        $this->actingAs($viewer)->getJson('/campanas-electorales/opciones/candidatos?q=ana')->assertForbidden();
        $this->assertSame($c->name, $c->fresh()->name);
        $this->assertSame(0, DB::table('electoral_campaign_goals')->count());
    }

    // ---------------------------------------------------------------- create

    public function test_create_gobernacion_sets_the_departamento_owner_and_audit(): void
    {
        $user = $this->manager();
        $partido = $this->partido('Centro Democrático');

        $r = $this->actingAs($user)->postJson('/campanas-electorales', $this->payload([
            'campaign_id' => $this->campY, 'status' => 'archived', 'political_organization_id' => $partido, 'candidate_name' => '  Ana  Pérez ',
        ]))->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.campana.territorio.resumen', 'Santander · 4 municipios');

        $c = ElectoralCampaign::sole();
        $this->assertSame($this->cd, $c->campaign_id, 'campaign_id from the body is ignored');
        $this->assertSame('active', $c->status);
        $this->assertSame($user->id, $c->created_by);
        $this->assertSame([$this->santander], $c->territories->pluck('geographic_unit_id')->all());
        $this->assertSame('Ana  Pérez', $c->candidate_name);
        $this->assertSame(1.1, $c->factor_votos);
        $this->assertSame(50, $c->votos_por_lider);
        $r->assertJsonPath('data.campana.partido.nombre', 'Centro Democrático');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'electoral_campaign.created')->where('entity_id', $c->id)->count());
    }

    public function test_double_post_creates_one_campaign_and_the_second_gets_422(): void
    {
        $user = $this->manager();
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload())->assertCreated();
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload())
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Ya tienes una campaña activa con ese nombre.');
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['name' => '  gobernación   2027 ']))
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Ya tienes una campaña activa con ese nombre.');

        $this->assertSame(1, ElectoralCampaign::count());
    }

    public function test_the_same_name_in_another_group_or_archived_is_allowed(): void
    {
        $this->campana(['name' => 'Gobernación 2027'], null, $this->campY);
        $this->campana(['name' => 'Gobernación 2027'], null, $this->cd, ElectoralCampaign::ARCHIVED);

        $this->actingAs($this->manager())->postJson('/campanas-electorales', $this->payload())->assertCreated();
    }

    public function test_alcaldia_needs_exactly_one_municipio_of_santander(): void
    {
        $user = $this->manager();
        $alcaldia = fn (array $ids) => $this->payload(['name' => 'Alcaldía '.count($ids).uniqid(), 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'territorio_ids' => $ids]);

        $this->actingAs($user)->postJson('/campanas-electorales', $alcaldia([]))->assertStatus(422)->assertJsonPath('errors.territorio_ids.0', 'Elige un municipio.');
        $this->actingAs($user)->postJson('/campanas-electorales', $alcaldia([$this->giron, $this->lebrija]))
            ->assertStatus(422)->assertJsonPath('errors.territorio_ids.0', 'Para Alcaldía elige un solo municipio.');
        $this->actingAs($user)->postJson('/campanas-electorales', $alcaldia([$this->bogota]))
            ->assertStatus(422)->assertJsonPath('errors.territorio_ids.0', 'Ese territorio no está en Santander.');
        $this->actingAs($user)->postJson('/campanas-electorales', $alcaldia([$this->soto]))->assertStatus(422);
        $this->actingAs($user)->postJson('/campanas-electorales', $alcaldia([$this->comuna1]))->assertStatus(422);
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['cargo' => 'alcaldia', 'scope_level' => 'department']))
            ->assertStatus(422)->assertJsonPath('errors.scope_level.0', 'Ese alcance no corresponde al cargo.');
        $this->assertSame(0, ElectoralCampaign::count());

        $this->actingAs($user)->postJson('/campanas-electorales', $alcaldia([$this->bucaramanga]))->assertCreated()
            ->assertJsonPath('data.campana.territorio.resumen', 'Bucaramanga');
    }

    public function test_jal_takes_one_comuna_and_senado_a_list_of_municipios(): void
    {
        $user = $this->manager();
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['name' => 'JAL', 'cargo' => 'jal', 'scope_level' => 'comuna', 'territorio_ids' => [$this->giron]]))
            ->assertStatus(422)->assertJsonPath('errors.territorio_ids.0', 'Elige una comuna o corregimiento.');
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['name' => 'JAL', 'cargo' => 'jal', 'scope_level' => 'comuna', 'territorio_ids' => [$this->comuna1, $this->comuna2]]))
            ->assertStatus(422)->assertJsonPath('errors.territorio_ids.0', 'Elige una sola comuna o corregimiento.');
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['name' => 'JAL', 'cargo' => 'jal', 'scope_level' => 'comuna', 'territorio_ids' => [$this->comuna2]]))
            ->assertCreated()->assertJsonPath('data.campana.territorio.resumen', 'Comuna 2 - Nororiental · Bucaramanga');
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['name' => 'Senado', 'cargo' => 'senado', 'scope_level' => 'municipality', 'territorio_ids' => [$this->giron, $this->lebrija]]))
            ->assertCreated()->assertJsonPath('data.campana.territorio.resumen', '2 municipios');
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['name' => 'Asamblea', 'cargo' => 'asamblea', 'territorio_ids' => [$this->giron]]))
            ->assertStatus(422)->assertJsonPath('errors.territorio_ids.0', 'Para Asamblea el territorio es todo el departamento.');
    }

    public function test_field_validations(): void
    {
        $user = $this->manager();
        $post = fn (array $over) => $this->actingAs($user)->postJson('/campanas-electorales', $this->payload($over))->assertStatus(422);

        $post(['name' => '   '])->assertJsonPath('errors.name.0', 'Escribe el nombre.');
        $post(['name' => str_repeat('a', 121)])->assertJsonValidationErrors('name');
        $post(['cargo' => 'presidencia'])->assertJsonPath('errors.cargo.0', 'Elige el cargo.');
        $post(['election_year' => 2023])->assertJsonValidationErrors('election_year');
        $post(['election_year' => 2041])->assertJsonValidationErrors('election_year');
        $post(['election_date' => '2028-10-29'])->assertJsonPath('errors.election_date.0', 'La fecha debe ser del año de la elección.');
        $post(['factor_votos' => 0.49])->assertJsonValidationErrors('factor_votos');
        $post(['factor_votos' => 1.105])->assertJsonValidationErrors('factor_votos');
        $post(['factor_registro' => 3.5])->assertJsonValidationErrors('factor_registro');
        $post(['votos_por_lider' => 4])->assertJsonValidationErrors('votos_por_lider');
        $post(['votos_por_lider' => 1001])->assertJsonValidationErrors('votos_por_lider');
        $post(['political_organization_id' => '00000000-0000-4000-8000-000000000999'])->assertJsonPath('errors.political_organization_id.0', 'Elige un partido de la lista.');
        $post(['candidate_person_id' => '00000000-0000-4000-8000-000000000999'])->assertJsonPath('errors.candidate_person_id.0', 'Elige un candidato de la lista.');
        $this->assertSame(0, ElectoralCampaign::count());
    }

    public function test_a_group_without_electoral_results_cannot_pick_a_candidate_from_them(): void
    {
        $this->grupoSinResultados();
        $user = $this->manager($this->campY);
        $persona = $this->persona('Ana Candidata');

        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['candidate_person_id' => $persona]))
            ->assertStatus(422)->assertJsonValidationErrors('candidate_person_id');
        $this->actingAs($user)->getJson('/campanas-electorales/opciones/candidatos?q=ana')->assertForbidden()
            ->assertJsonPath('message', CurrentCampaign::SIN_RESULTADOS);
        $this->actingAs($user)->postJson('/campanas-electorales', $this->payload(['candidate_name' => 'Ana Candidata']))->assertCreated();
        $this->assertSame($this->campY, ElectoralCampaign::sole()->campaign_id);
    }

    public function test_the_superadmin_with_another_group_marked_cannot_create(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->withSession([CurrentCampaign::VISTA_SESSION_KEY => [$this->campX]])
            ->postJson('/campanas-electorales', $this->payload())->assertStatus(422)->assertJsonValidationErrors('grupo');
        $this->assertSame(0, ElectoralCampaign::count());
    }

    public function test_a_user_with_territory_cannot_create(): void
    {
        $this->actingAs($this->manager($this->cd, [$this->giron]))->postJson('/campanas-electorales', $this->payload())->assertForbidden();
        $this->assertSame(0, ElectoralCampaign::count());
    }

    // ---------------------------------------------------------------- isolation

    public function test_a_campaign_of_another_group_is_a_404_and_a_subgroup_is_read_only(): void
    {
        $delCd = $this->campana(['name' => 'Del CD']);
        $deX = $this->campana(['name' => 'De Alejandro'], null, $this->campX);
        $alejandro = $this->manager($this->campX);
        $cdUser = $this->manager();

        $this->actingAs($alejandro)->getJson("/campanas-electorales/{$delCd->id}")->assertNotFound();
        $this->actingAs($alejandro)->putJson("/campanas-electorales/{$delCd->id}", $this->payload())->assertNotFound();
        $this->actingAs($alejandro)->getJson("/campanas-electorales/{$delCd->id}/historial")->assertNotFound();
        $this->actingAs($alejandro)->getJson('/campanas-electorales')->assertOk()->assertJsonCount(1, 'data.campanas')
            ->assertJsonPath('data.campanas.0.name', 'De Alejandro');

        $this->actingAs($cdUser)->getJson("/campanas-electorales/{$deX->id}")->assertOk()
            ->assertJsonPath('data.can.edit', false)->assertJsonPath('data.soloLectura.motivo', 'subgrupo')
            ->assertJsonPath('data.campana.grupo.propio', false);
        $this->actingAs($cdUser)->putJson("/campanas-electorales/{$deX->id}", $this->payload(['name' => 'Cambiada']))->assertForbidden();
        $this->actingAs($cdUser)->postJson("/campanas-electorales/{$deX->id}/archivar")->assertForbidden();
        $this->actingAs($cdUser)->putJson("/campanas-electorales/{$deX->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 1]]])->assertForbidden();
        $this->assertSame('De Alejandro', $deX->fresh()->name);
        $this->assertSame('active', $deX->fresh()->status);

        $otro = $this->manager($this->campY);
        $this->actingAs($otro)->getJson("/campanas-electorales/{$deX->id}")->assertNotFound();
        $this->actingAs($otro)->getJson('/campanas-electorales/00000000-0000-4000-8000-000000000999')->assertNotFound();
    }

    public function test_the_superadmin_header_narrows_what_it_sees(): void
    {
        $delCd = $this->campana(['name' => 'Del CD']);
        $deX = $this->campana(['name' => 'De Alejandro'], null, $this->campX);
        $super = $this->superadmin();
        $soloX = [CurrentCampaign::VISTA_SESSION_KEY => [$this->campX]];

        $this->actingAs($super)->withSession($soloX)->getJson('/campanas-electorales')->assertJsonCount(1, 'data.campanas')
            ->assertJsonPath('data.campanas.0.id', $deX->id);
        $this->actingAs($super)->withSession($soloX)->getJson("/campanas-electorales/{$delCd->id}")->assertNotFound();
        $this->actingAs($super)->withSession($soloX)->getJson("/campanas-electorales/{$deX->id}")->assertOk()->assertJsonPath('data.can.edit', false);
        $this->actingAs($super)->withSession($soloX)->putJson("/campanas-electorales/{$deX->id}", $this->payload())->assertForbidden();

        $this->actingAs($super)->withSession([CurrentCampaign::VISTA_SESSION_KEY => null])->getJson('/campanas-electorales')->assertJsonCount(2, 'data.campanas');
    }

    public function test_a_user_with_territory_only_sees_campaigns_that_cross_it_read_only(): void
    {
        $alcaldiaGiron = $this->campana(['name' => 'Alcaldía Girón', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->giron]);
        $alcaldiaVelez = $this->campana(['name' => 'Alcaldía Vélez', 'cargo' => 'alcaldia', 'scope_level' => 'municipality'], [$this->velez]);
        $gob = $this->campana(['name' => 'Gobernación']);
        $jal = $this->campana(['name' => 'JAL', 'cargo' => 'jal', 'scope_level' => 'comuna'], [$this->comuna1]);
        $giron = $this->manager($this->cd, [$this->giron]);

        $this->actingAs($giron)->getJson('/campanas-electorales')->assertOk()->assertJsonCount(2, 'data.campanas')
            ->assertJsonPath('data.can.manage', false);
        $this->actingAs($giron)->getJson("/campanas-electorales/{$alcaldiaVelez->id}")->assertNotFound();
        $this->actingAs($giron)->getJson("/campanas-electorales/{$jal->id}")->assertNotFound();

        $filas = $this->actingAs($giron)->getJson("/campanas-electorales/{$gob->id}")->assertOk()
            ->assertJsonPath('data.soloLectura.motivo', 'territorio')->json('data.filas');
        $this->assertSame([$this->giron], array_column($filas, 'id'));
        $this->actingAs($giron)->putJson("/campanas-electorales/{$gob->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 5]]])
            ->assertForbidden();
        $this->actingAs($giron)->putJson("/campanas-electorales/{$alcaldiaGiron->id}", $this->payload(['name' => 'X', 'cargo' => 'alcaldia', 'scope_level' => 'municipality', 'territorio_ids' => [$this->giron]]))
            ->assertForbidden();
        $this->assertSame(0, DB::table('electoral_campaign_goals')->count());
    }

    // ---------------------------------------------------------------- edit, archive, reactivate

    public function test_update_changes_fields_and_territory_and_audits_before_and_after(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Senado', 'cargo' => 'senado', 'scope_level' => 'municipality'], [$this->giron]);

        $this->actingAs($user)->putJson("/campanas-electorales/{$c->id}", $this->payload([
            'name' => 'Senado 2030', 'cargo' => 'senado', 'scope_level' => 'municipality', 'territorio_ids' => [$this->giron, $this->lebrija],
            'election_year' => 2030, 'factor_votos' => 1.25, 'campaign_id' => $this->campY,
        ]))->assertOk()->assertJsonPath('data.campana.name', 'Senado 2030');

        $c->refresh();
        $this->assertSame($this->cd, $c->campaign_id);
        $this->assertSame(1.25, $c->factor_votos);
        $this->assertEqualsCanonicalizing([$this->giron, $this->lebrija], $c->territories->pluck('geographic_unit_id')->all());
        $log = DB::table('audit_logs')->where('action', 'electoral_campaign.updated')->sole();
        $cambios = collect(json_decode($log->metadata, true)['cambios'])->keyBy('campo');
        $this->assertSame('Senado', $cambios['name']['antes']);
        $this->assertSame('Senado 2030', $cambios['name']['despues']);
        $this->assertSame([$this->giron], $cambios['territorio']['antes']);

        $hist = $this->actingAs($user)->getJson("/campanas-electorales/{$c->id}/historial")->assertOk()->json('data');
        $this->assertSame('Campaña editada', $hist[0]['accion_label']);
    }

    public function test_reducing_the_territory_with_goals_outside_is_refused(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Senado', 'cargo' => 'senado', 'scope_level' => 'municipality'], [$this->giron, $this->lebrija]);
        DB::table('electoral_campaign_goals')->insert(['id' => (string) Str::uuid(), 'electoral_campaign_id' => $c->id,
            'geographic_unit_id' => $this->lebrija, 'votos_meta' => 10]);

        $this->actingAs($user)->putJson("/campanas-electorales/{$c->id}", $this->payload(['name' => 'Senado', 'cargo' => 'senado', 'scope_level' => 'municipality', 'territorio_ids' => [$this->giron]]))
            ->assertStatus(422)->assertJsonValidationErrors('territorio');
        $this->assertCount(2, $c->fresh()->territories);
    }

    public function test_archive_and_reactivate(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gobernacion']);

        $this->actingAs($user)->postJson("/campanas-electorales/{$c->id}/archivar")->assertOk()->assertJsonPath('data.campana.status', 'archived');
        $this->assertNotNull($c->fresh()->archived_at);
        $this->actingAs($user)->postJson("/campanas-electorales/{$c->id}/archivar")->assertStatus(422);
        $this->actingAs($user)->putJson("/campanas-electorales/{$c->id}", $this->payload(['name' => 'Otra']))->assertStatus(422);
        $this->actingAs($user)->putJson("/campanas-electorales/{$c->id}/metas", ['metas' => [['geographic_unit_id' => $this->giron, 'votos_meta' => 5]]])
            ->assertStatus(422)->assertJsonPath('message', 'La campaña está archivada: reactívala para cambiarla.');
        $this->actingAs($user)->get("/campanas-electorales/{$c->id}/editar")->assertRedirect("/campanas-electorales/{$c->id}");
        $this->actingAs($user)->getJson('/campanas-electorales')->assertJsonCount(0, 'data.campanas');
        $this->actingAs($user)->getJson('/campanas-electorales?archivadas=1')->assertJsonCount(1, 'data.campanas')
            ->assertJsonPath('data.filtros.archivadas', true);

        // Another active one took the name meanwhile: reactivating would duplicate it.
        $this->campana(['name' => 'GOBERNACION']);
        $this->actingAs($user)->postJson("/campanas-electorales/{$c->id}/reactivar")->assertStatus(422);
        ElectoralCampaign::where('name', 'GOBERNACION')->delete();
        $this->actingAs($user)->postJson("/campanas-electorales/{$c->id}/reactivar")->assertOk()->assertJsonPath('data.campana.status', 'active');
        $this->actingAs($user)->postJson("/campanas-electorales/{$c->id}/reactivar")->assertStatus(422);

        $acciones = DB::table('audit_logs')->where('entity_id', $c->id)->where('action', 'like', 'electoral_campaign.%')->orderBy('created_at')->pluck('action')->all();
        $this->assertSame(['electoral_campaign.archived', 'electoral_campaign.reactivated'], $acciones);
    }

    // ---------------------------------------------------------------- pages

    public function test_the_pages_render_with_their_props(): void
    {
        $user = $this->manager();
        $c = $this->campana(['name' => 'Gobernación']);

        $this->actingAs($user)->get('/campanas-electorales')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('CampanasElectorales/Lista')->has('campanas', 1)->where('campanas.0.metas', ['cargadas' => 0, 'total' => 4])
            ->where('can.manage', true)->where('createBlocked', null));
        $this->actingAs($user)->get('/campanas-electorales/nueva')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('CampanasElectorales/Formulario')->where('campana', null)->has('opciones.cargos', 7)
            ->has('opciones.municipios', 4)->has('opciones.comunas', 3)->where('opciones.departamento.nombre', 'Santander')
            ->where('opciones.anio.defecto', 2027)->where('puedeBuscarCandidatos', true));
        $this->actingAs($user)->get("/campanas-electorales/{$c->id}/editar")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('CampanasElectorales/Formulario')->where('campana.id', $c->id)->where('campana.territorio_ids', []));
        $this->actingAs($user)->get("/campanas-electorales/{$c->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('CampanasElectorales/Detalle')->has('filas', 7)->where('can.edit', true));

        $super = $this->superadmin();
        $this->actingAs($super)->withSession([CurrentCampaign::VISTA_SESSION_KEY => [$this->campX]])->get('/campanas-electorales')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('createBlocked', sprintf(CurrentCampaign::CREAR_EN_OTRO_GRUPO, 'Alejandro')));
    }

    public function test_the_party_choices_are_the_endorsing_organizations_once_per_name(): void
    {
        $user = $this->manager();
        $ev = $this->evento('Territoriales 2023', '2023-10-29');
        $con = $this->contienda($ev, 'offices', 'Alcaldía', $this->giron);
        $cd = $this->partido('Centro Democrático');
        $cdSinTilde = $this->partido('CENTRO DEMOCRATICO');
        $verde = $this->partido('Alianza Verde');
        $this->partido('Registrador');
        $soloSecundario = $this->partido('Movimiento Secundario');
        foreach ([$cd, $cd, $cdSinTilde, $verde] as $i => $party) {
            $this->candidatura($con, $this->persona("Candidato {$i}"), $party);
        }
        $this->candidatura($con, $this->persona('Coaval'), $soloSecundario, false);

        $partidos = null;
        $this->actingAs($user)->get('/campanas-electorales/nueva')->assertOk()->assertInertia(function (AssertableInertia $p) use (&$partidos) {
            $partidos = $p->toArray()['props']['opciones']['partidos'];
        });
        $this->assertSame([['id' => $verde, 'nombre' => 'Alianza Verde'], ['id' => $cd, 'nombre' => 'Centro Democrático']], $partidos,
            'Only endorsing organizations, by name, the variant with more endorsements');

        // The party a campaign already has is always offered when editing it.
        $c = $this->campana(['name' => 'Con Registrador', 'political_organization_id' => DB::table('political_organizations')->where('canonical_name', 'Registrador')->value('id')]);
        $this->actingAs($user)->get("/campanas-electorales/{$c->id}/editar")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('opciones.partidos', fn ($lista) => collect($lista)->contains('nombre', 'Registrador')));
    }

    public function test_candidate_search_returns_persons_with_their_candidacies(): void
    {
        $user = $this->manager();
        $ev = $this->evento('Territoriales 2023', '2023-10-29');
        $con = $this->contienda($ev, 'offices', 'Alcaldía', $this->giron);
        $ana = $this->persona('Ana María Ruiz');
        $this->candidatura($con, $ana, $this->partido('Partido Verde'));
        $this->persona('Ana Sin Candidatura');

        $data = $this->actingAs($user)->getJson('/campanas-electorales/opciones/candidatos?q=ana%25')->assertOk()->json('data');
        $this->assertSame([], $data, 'A % is searched literally');
        $data = $this->actingAs($user)->getJson('/campanas-electorales/opciones/candidatos?q=ana mar')->assertOk()->json('data');
        $this->assertSame([['id' => $ana, 'nombre' => 'Ana María Ruiz', 'candidaturas' => [
            ['cargo' => 'Alcaldía', 'anio' => 2023, 'territorio' => 'Girón', 'partido' => 'Partido Verde'],
        ]]], $data);
        $this->actingAs($user)->getJson('/campanas-electorales/opciones/candidatos?q=a')->assertOk()->assertJsonPath('data', []);
    }
}
