<?php

namespace Tests\Feature;

use App\Actions\Votantes\UpdateVotante;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// "Unificar con otra persona" from the ficha: lider + lider, candidate + lider (link by name) and candidate +
// candidate, with the cédula choice, campaign/territory/electoral rights and the merge_history kept for "Separar".
class UnifyRecordsTest extends TestCase
{
    use BuildsElectoralSchema;

    private array $geo = [];

    private array $contest = [];

    private string $campX;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildElectoralSchema();
        $this->buildExtraSchema();

        $this->geo['santander'] = $this->row('geographic_units', ['type' => 'department', 'canonical_name' => 'Santander']);
        $this->geo['soto'] = $this->row('geographic_units', ['type' => 'province', 'canonical_name' => 'Soto', 'parent_id' => $this->geo['santander']]);
        $this->geo['giron'] = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'Girón', 'parent_id' => $this->geo['soto']]);
        $this->geo['lebrija'] = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'Lebrija', 'parent_id' => $this->geo['soto']]);
        $this->geo['boyaca'] = $this->row('geographic_units', ['type' => 'department', 'canonical_name' => 'Boyacá']);
        $this->geo['tunja'] = $this->row('geographic_units', ['type' => 'municipality', 'canonical_name' => 'Tunja', 'parent_id' => $this->geo['boyaca']]);

        $event = $this->row('electoral_events', ['name' => 'Territoriales 2023', 'election_date' => '2023-10-29']);
        foreach (['concejoGiron' => ['Concejo Girón 2023', 'giron'], 'asamblea' => ['Asamblea Santander 2023', 'santander'], 'concejoTunja' => ['Concejo Tunja 2023', 'tunja']] as $key => [$name, $geo]) {
            $this->contest[$key] = $this->row('contests', ['electoral_event_id' => $event, 'geographic_unit_id' => $this->geo[$geo], 'name' => $name]);
        }

        $this->campX = $this->row('campaigns', ['name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID]);
    }

    private function buildExtraSchema(): void
    {
        Schema::table('lideres', function (Blueprint $t) {
            $t->string('telefono')->nullable();
            $t->string('nivel_confianza')->nullable();
            $t->integer('votos')->default(0);
        });
        Schema::table('persons', fn (Blueprint $t) => $t->string('doc_hash')->nullable());
        Schema::table('candidacies', fn (Blueprint $t) => $t->unique(['person_id', 'contest_id']));
        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('tipo')->nullable();
            $t->string('valor')->nullable();
            $t->timestamps();
        });
        foreach (['lider_info_politica', 'lider_seguimientos', 'event_attendees', 'event_organizers'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('lider_id');
            });
        }
        Schema::create('lider_municipios_influencia', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->uuid('geographic_unit_id');
        });
        Schema::create('whatsapp_send_recipients', function (Blueprint $t) {
            $t->id();
            $t->uuid('person_id');
        });
        Schema::create('favorites', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('favoritable_id');
        });
        Schema::create('recent_views', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewable_id');
        });
        Schema::create('taggables', function (Blueprint $t) {
            $t->uuid('tag_id');
            $t->uuid('taggable_id');
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code');
            $t->string('name');
            $t->string('description')->nullable();
            $t->integer('level')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->uuid('role_id')->nullable();
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        $migration = require database_path('migrations/2026_11_12_000001_create_merge_history_table.php');
        $migration->up();
    }

    private function user(string $campaignId = Campaign::DEFAULT_ID, array $permissions = ['view.mapa', 'view.votantes', 'write.data'], ?array $territory = null): User
    {
        $role = Role::firstOrCreate(['code' => 'R02_ADMIN_FUNCIONAL'], ['name' => 'Administrador de campaña', 'level' => 9]);
        $user = User::create([
            'name' => 'Usuario', 'email' => Str::random(8).'@test.local', 'password' => 'secret123', 'role_id' => $role->id,
            'permissions' => $permissions, 'territory_scope' => $territory,
        ]);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    private function lider(string $nombre, string $geo, array $extra = []): string
    {
        return $this->row('lideres', $extra + [
            'nombre' => $nombre, 'municipio' => DB::table('geographic_units')->where('id', $this->geo[$geo])->value('canonical_name'),
            'geographic_unit_id' => $this->geo[$geo], 'cargo' => 'Líder', 'campaign_id' => Campaign::DEFAULT_ID,
        ]);
    }

    private function candidate(string $nombre, string $contest, array $extra = []): string
    {
        $id = $this->row('persons', $extra + ['full_name' => $nombre, 'normalized_name' => Str::upper(Str::ascii($nombre)), 'identity_status' => 'unverified']);
        $this->row('candidacies', ['person_id' => $id, 'contest_id' => $this->contest[$contest]]);

        return $id;
    }

    private function unify(User $user, string $id, string $con, array $extra = [])
    {
        return $this->actingAs($user)->postJson("/mapa-politico/persona/{$id}/unificar-con", ['con' => $con] + $extra);
    }

    public function test_two_lideres_become_one_and_the_previous_state_is_kept_in_merge_history(): void
    {
        $a = $this->lider('Deisy Villamizar', 'giron', ['telefono' => '3001112233']);
        $b = $this->lider('Deisy Paola Villamizar Bohada', 'giron', ['cedula' => '1098151229', 'cargo' => 'Concejal', 'telefono' => '3009998877']);
        $this->row('lider_contactos', ['lider_id' => $b, 'tipo' => 'WhatsApp', 'valor' => '3105554433']);

        $res = $this->unify($this->user(), $a, $b)->assertOk()->assertJson(['success' => true, 'caso' => 'lideres']);

        $kept = $res->json('id');
        $this->assertSame(1, DB::table('lideres')->whereIn('id', [$a, $b])->count());
        $this->assertSame('Deisy Paola Villamizar Bohada', $res->json('final.nombre'));
        $this->assertSame('1098151229', $res->json('final.cedula'));
        $this->assertEqualsCanonicalizing(['3001112233', '3009998877', '3105554433'], $res->json('final.telefonos'));
        $this->assertEqualsCanonicalizing(['Líder', 'Concejal'], $res->json('final.cargos'));

        $history = DB::table('merge_history')->first();
        $this->assertSame('lideres', $history->type);
        $this->assertSame($kept, $history->kept_id);
        $this->assertSame($kept === $a ? $b : $a, $history->absorbed_id);
        $this->assertSame(Campaign::DEFAULT_ID, $history->campaign_id);
        $snapshot = json_decode($history->snapshot, true);
        $this->assertEqualsCanonicalizing([$a, $b], array_column($snapshot['lideres'], 'id'));
        $this->assertCount(1, $snapshot['referencias']['lider_contactos.lider_id']);

        $audit = DB::table('audit_logs')->where('action', 'MERGE_RECORDS')->first();
        $this->assertSame($kept, $audit->entity_id);
        $this->assertStringNotContainsString('Deisy', $audit->metadata);
        $this->assertStringNotContainsString('1098151229', $audit->metadata);
    }

    public function test_different_cedulas_ask_which_one_is_right(): void
    {
        $a = $this->lider('Carlos Rueda', 'giron', ['cedula' => '91234567']);
        $b = $this->lider('Carlos Rueda Diaz', 'giron', ['cedula' => '91234576']);
        $user = $this->user();

        $this->actingAs($user)->postJson("/mapa-politico/persona/{$a}/unificar-con/vista-previa", ['con' => $b])
            ->assertOk()->assertJson(['cedulas' => ['91234567', '91234576']]);
        $this->assertSame(2, DB::table('lideres')->count(), 'the preview writes nothing');
        $this->assertSame(0, DB::table('merge_history')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'MERGE_RECORDS')->count());

        $this->unify($user, $a, $b)->assertStatus(422)->assertJsonFragment(['message' => 'Los dos registros tienen cédulas distintas: elige cuál es la correcta.']);
        $this->assertSame(2, DB::table('lideres')->count());

        $this->unify($user, $a, $b, ['cedula' => '91234576'])->assertOk()->assertJsonPath('final.cedula', '91234576');
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_a_departmental_candidate_takes_the_name_of_a_lider_inside_the_department(): void
    {
        $lider = $this->lider('Pedro Gomez Rey', 'lebrija');
        $person = $this->candidate('PEDRO GOMEZ', 'asamblea');

        $res = $this->unify($this->user(), $person, $lider)->assertOk()->assertJson(['caso' => 'vinculo', 'id' => $lider]);

        $this->assertSame('Pedro Gomez Rey', DB::table('persons')->where('id', $person)->value('full_name'));
        $this->assertTrue(DB::table('person_aliases')->where('person_id', $person)->where('alias_name', 'PEDRO GOMEZ')->exists());
        $this->assertContains($person, app(UpdateVotante::class)->linkedPersonIds(DB::table('lideres')->find($lider))->all());
        $this->assertSame('Asamblea Santander 2023', $res->json('final.candidaturas.0.cargo'));
        $this->assertSame(2023, $res->json('final.candidaturas.0.anio'));

        $history = DB::table('merge_history')->first();
        $this->assertSame(['vinculo', $lider, $person], [$history->type, $history->kept_id, $history->absorbed_id]);
        $this->assertSame('PEDRO GOMEZ', json_decode($history->snapshot, true)['person']['full_name']);
    }

    public function test_the_link_works_from_either_record(): void
    {
        $lider = $this->lider('Juan Perez', 'giron');
        $person = $this->candidate('JUAN PEREZ PEÑA', 'concejoGiron');

        $this->unify($this->user(), $lider, $person)->assertOk()->assertJson(['caso' => 'vinculo', 'id' => $lider]);
        $this->assertSame('Juan Perez', DB::table('persons')->where('id', $person)->value('full_name'));
    }

    public function test_a_candidate_of_another_department_explains_why_not(): void
    {
        $lider = $this->lider('Luis Mora', 'giron');
        $person = $this->candidate('LUIS MORA', 'concejoTunja');

        $res = $this->unify($this->user(), $person, $lider)->assertStatus(422);

        $this->assertStringContainsString('Tunja', $res->json('message'));
        $this->assertStringContainsString('Girón', $res->json('message'));
        $this->assertSame('LUIS MORA', DB::table('persons')->where('id', $person)->value('full_name'));
        $this->assertSame(0, DB::table('merge_history')->count());
    }

    public function test_a_namesake_lider_blocks_the_link_and_nothing_changes(): void
    {
        $lider = $this->lider('Ana Diaz', 'giron');
        $this->lider('Ana Diaz', 'lebrija');
        $person = $this->candidate('ANA DIAZ SUAREZ', 'asamblea');

        $res = $this->unify($this->user(), $person, $lider)->assertStatus(422);

        $this->assertStringContainsString('mismo nombre', $res->json('message'));
        $this->assertSame('ANA DIAZ SUAREZ', DB::table('persons')->where('id', $person)->value('full_name'));
        $this->assertFalse(DB::table('person_aliases')->exists());
        $this->assertSame(0, DB::table('merge_history')->count());
    }

    public function test_two_candidates_become_one_person(): void
    {
        $a = $this->candidate('Rosa Elena Pinto', 'concejoGiron');
        $b = $this->candidate('ROSA PINTO', 'asamblea');

        $res = $this->unify($this->user(), $a, $b)->assertOk()->assertJson(['caso' => 'personas', 'id' => $a]);

        $this->assertSame($a, DB::table('persons')->where('id', $b)->value('merged_into_id'));
        $this->assertSame(2, DB::table('candidacies')->where('person_id', $a)->count());
        $this->assertCount(2, $res->json('final.candidaturas'));
        $history = DB::table('merge_history')->first();
        $this->assertSame(['personas', $a, $b, null], [$history->type, $history->kept_id, $history->absorbed_id, $history->campaign_id]);
        $this->assertEqualsCanonicalizing([$a, $b], array_column(json_decode($history->snapshot, true)['persons'], 'id'));
    }

    public function test_two_candidates_of_the_same_contest_or_with_different_documents_are_not_merged(): void
    {
        $a = $this->candidate('Mario Silva', 'concejoGiron');
        $b = $this->candidate('MARIO SILVA', 'concejoGiron');
        $this->unify($this->user(), $a, $b)->assertStatus(422);

        $c = $this->candidate('Eva Leon', 'concejoGiron', ['doc_hash' => 'aaa']);
        $d = $this->candidate('EVA LEON', 'asamblea', ['doc_hash' => 'bbb']);
        $this->unify($this->user(), $c, $d)->assertStatus(422)->assertJsonFragment(['success' => false]);

        $this->assertSame(0, DB::table('persons')->whereNotNull('merged_into_id')->count());
        $this->assertSame(0, DB::table('merge_history')->count());
    }

    public function test_without_write_data_it_is_forbidden(): void
    {
        $a = $this->lider('Uno', 'giron');
        $b = $this->lider('Uno Dos', 'giron');
        $reader = $this->user(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes']);

        $this->unify($reader, $a, $b)->assertForbidden();
        $this->actingAs($reader)->getJson("/mapa-politico/persona/{$a}/unificar-con/buscar?q=Uno")->assertForbidden();
        $this->assertSame(2, DB::table('lideres')->count());
    }

    public function test_a_subcampaign_cannot_unite_candidates_nor_records_of_another_campaign(): void
    {
        $userX = $this->user($this->campX);
        $own = $this->lider('Marta Gil', 'giron', ['campaign_id' => $this->campX]);
        $cd = $this->lider('Marta Gil', 'lebrija');
        $a = $this->candidate('Pablo Rios', 'concejoGiron');
        $b = $this->candidate('PABLO RIOS', 'asamblea');

        $this->unify($userX, $a, $b)->assertForbidden()->assertJsonFragment(['success' => false]);
        $this->unify($userX, $own, $a)->assertForbidden();
        // The parent campaign's lideres are invisible to a subcampaign: the same answer as a missing record.
        $this->unify($userX, $own, $cd)->assertNotFound();
        $this->unify($userX, $cd, $own)->assertNotFound();
        // The parent campaign sees the subcampaign's lider, read-only.
        $this->unify($this->user(), $cd, $own)->assertForbidden();

        $this->assertSame(0, DB::table('persons')->whereNotNull('merged_into_id')->count());
        $this->assertSame(2, DB::table('lideres')->count());
        $this->assertSame('Pablo Rios', DB::table('persons')->where('id', $a)->value('full_name'));
    }

    public function test_records_outside_the_users_territory_are_refused(): void
    {
        $inside = $this->lider('Nora Paez', 'lebrija');
        $outside = $this->lider('Nora Paez Cruz', 'giron');
        $user = $this->user(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes', 'write.data'], [$this->geo['lebrija']]);

        $this->unify($user, $inside, $outside)->assertForbidden();
        $this->unify($user, $outside, $inside)->assertForbidden();
        $this->assertSame(2, DB::table('lideres')->count());
    }

    public function test_search_finds_lideres_and_candidates_but_never_the_record_itself(): void
    {
        $self = $this->lider('Sofia Ardila', 'giron', ['telefono' => '3157778899']);
        $other = $this->lider('Sofia Ardila Mejia', 'giron', ['cedula' => '63555111']);
        $this->lider('Sofia Ardila', 'giron', ['campaign_id' => $this->campX]);
        $person = $this->candidate('SOFIA ARDILA PRADA', 'asamblea');
        $party = $this->row('political_organizations', ['canonical_name' => 'Centro Democrático']);
        $this->row('candidacy_endorsements', ['candidacy_id' => DB::table('candidacies')->where('person_id', $person)->value('id'), 'organization_id' => $party, 'is_primary' => true]);

        $res = $this->actingAs($this->user())->getJson("/mapa-politico/persona/{$self}/unificar-con/buscar?q=sofia ardila")->assertOk();
        $found = collect($res->json('data'));

        $this->assertEqualsCanonicalizing([$other, $person], $found->pluck('id')->all());
        $candidato = $found->firstWhere('id', $person);
        $this->assertSame('candidato', $candidato['tipo']);
        $this->assertSame(['cargo' => 'Asamblea Santander 2023', 'territorio' => 'Santander', 'anio' => 2023, 'partido' => 'Centro Democrático'], $candidato['candidatura']);

        $byCedula = $this->actingAs($this->user())->getJson("/mapa-politico/persona/{$self}/unificar-con/buscar?q=63555")->json('data');
        $this->assertSame([$other], array_column($byCedula, 'id'));

        $this->actingAs($this->user())->getJson("/mapa-politico/persona/{$self}/unificar-con/buscar?q=s")->assertOk()->assertJson(['data' => []]);

        $userX = $this->user($this->campX);
        $own = DB::table('lideres')->where('campaign_id', $this->campX)->value('id');
        $forX = $this->actingAs($userX)->getJson("/mapa-politico/persona/{$own}/unificar-con/buscar?q=sofia")->json('data');
        $this->assertSame([], $forX, 'a subcampaign sees neither the parent campaign lideres nor candidates');
    }
}
