<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: the campaign isolation paths that only run on PostgreSQL (ILIKE, real schema, no column default).
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=CampaignIsolationPostgresTest
// Every change is rolled back by DatabaseTransactions.
class CampaignIsolationPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $mun;

    private string $campX;

    private string $liderA;

    private string $liderB;

    private string $liderC;

    private User $userCd;

    private User $userX;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Municipio Campañas PG']);

        $this->campX = $this->campaign('Subcampaña PG', Campaign::DEFAULT_ID);
        $campY = $this->campaign('Independiente PG', null);

        $this->liderA = $this->lider(Campaign::DEFAULT_ID, 'Zzcamp Ana CD');
        $this->liderB = $this->lider($this->campX, 'Zzcamp Beto X');
        $this->liderC = $this->lider($campY, 'Zzcamp Ceci Y');

        $this->userCd = $this->user(Campaign::DEFAULT_ID);
        $this->userX = $this->user($this->campX);
    }

    private function campaign(string $name, ?string $parent): string
    {
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'parent_id' => $parent,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function lider(string $campaignId, string $nombre, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => $campaignId, 'geographic_unit_id' => $this->mun, 'nombre' => $nombre,
            'municipio' => 'Municipio Campañas PG', 'cargo' => 'Líder', 'is_active' => true,
            'cedula' => '77'.random_int(10000000, 99999999), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    private function user(string $campaignId): User
    {
        $user = User::create([
            'name' => 'Campaña PG', 'email' => 'camp-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'territory_scope' => [$this->mun], 'permissions' => ['view.votantes', 'view.mapa', 'write.data'],
        ]);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    // An electoral person who ran for Alcaldía in the test municipio, endorsed by an existing party.
    private function candidate(string $fullName): object
    {
        $eventId = DB::table('contests')->whereNotNull('electoral_event_id')->value('electoral_event_id');
        $orgs = DB::table('political_organizations')->orderBy('canonical_name')->limit(2)->get(['id', 'canonical_name']);
        $this->assertNotNull($eventId);
        $this->assertCount(2, $orgs);

        $contestId = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $contestId, 'name' => 'Alcaldía campañas', 'electoral_event_id' => $eventId,
            'office_id' => DB::table('offices')->where('name', 'Alcaldía')->value('id'), 'geographic_unit_id' => $this->mun,
        ]);
        $personId = (string) Str::uuid();
        DB::table('persons')->insert([
            'id' => $personId, 'first_name' => 'Zzcamp', 'last_name' => 'Candidato',
            'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        ]);
        $candidacyId = (string) Str::uuid();
        DB::table('candidacies')->insert(['id' => $candidacyId, 'person_id' => $personId, 'contest_id' => $contestId]);
        DB::table('candidacy_endorsements')->insert([
            'id' => (string) Str::uuid(), 'candidacy_id' => $candidacyId, 'organization_id' => $orgs[0]->id,
            'is_primary' => true, 'endorsement_type' => 'endorsement', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $personId, 'candidacy' => $candidacyId, 'org' => $orgs[0], 'otherOrg' => $orgs[1]];
    }

    private function endorsedOrg(string $candidacyId): ?string
    {
        return DB::table('candidacy_endorsements')->where('candidacy_id', $candidacyId)->where('is_primary', true)->value('organization_id');
    }

    // --- 6. Shared electoral data is only edited by the platform ---

    public function test_another_campaign_editing_a_candidate_only_touches_its_own_lider(): void
    {
        $p = $this->candidate('Zzcamp Pedro Candidato');

        $this->actingAs($this->userX)->putJson("/mapa-politico/persona/{$p->id}", [
            'nombre' => 'Zzcamp Pedro Renombrado', 'partido' => $p->otherOrg->canonical_name, 'telefono' => '3009990000',
        ])->assertOk();

        $this->assertSame('Zzcamp Pedro Candidato', DB::table('persons')->where('id', $p->id)->value('full_name'));
        $this->assertSame($p->org->id, $this->endorsedOrg($p->candidacy));
        $this->assertDatabaseHas('lideres', ['campaign_id' => $this->campX, 'telefono' => '3009990000', 'geographic_unit_id' => $this->mun]);
        $this->assertDatabaseMissing('lideres', ['campaign_id' => Campaign::DEFAULT_ID, 'telefono' => '3009990000']);
    }

    public function test_the_root_campaign_keeps_renaming_candidates_and_their_party(): void
    {
        $p = $this->candidate('Zzcamp Pedro Candidato');

        $this->actingAs($this->userCd)->putJson("/mapa-politico/persona/{$p->id}", [
            'nombre' => 'Zzcamp Pedro Renombrado', 'partido' => $p->otherOrg->canonical_name,
        ])->assertOk();

        $this->assertSame('Zzcamp Pedro Renombrado', DB::table('persons')->where('id', $p->id)->value('full_name'));
        $this->assertSame($p->otherOrg->id, $this->endorsedOrg($p->candidacy));
        $this->assertDatabaseHas('lideres', ['campaign_id' => Campaign::DEFAULT_ID, 'nombre' => 'Zzcamp Pedro Renombrado']);
    }

    public function test_a_candidate_opens_as_a_lider_only_of_a_visible_campaign(): void
    {
        $p = $this->candidate('Zzcamp Ana CD');

        $this->actingAs($this->userCd)->getJson("/mapa-politico/persona/{$p->id}")
            ->assertOk()->assertJsonPath('source', 'lider')->assertJsonPath('id', $this->liderA);

        $cedulaA = DB::table('lideres')->where('id', $this->liderA)->value('cedula');
        $this->actingAs($this->userX)->getJson("/mapa-politico/persona/{$p->id}")
            ->assertOk()->assertJsonPath('source', 'persona')->assertJsonPath('id', $p->id)
            ->assertJsonMissing(['cedula' => $cedulaA]);
    }

    // --- 3/4. ILIKE listings, referido search and offline packages ---

    public function test_mapa_politico_search_follows_campaign_visibility(): void
    {
        $names = fn (User $user) => collect($this->actingAs($user)->get('/mapa-politico?tipo=lideres&search=Zzcamp')
            ->assertOk()->viewData('page')['props']['data'])->pluck('nombre')->sort()->values()->all();

        $this->assertSame(['Zzcamp Beto X'], $names($this->userX));
        $this->assertSame(['Zzcamp Ana CD', 'Zzcamp Beto X'], $names($this->userCd));
    }

    public function test_search_for_referidos_only_offers_the_own_campaign(): void
    {
        $this->actingAs($this->userCd)->getJson('/mapa-politico/search-lideres?q=Zzcamp')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->liderA);
        $this->actingAs($this->userX)->getJson('/mapa-politico/search-lideres?q=Zzcamp')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->liderB);
    }

    public function test_offline_package_follows_campaign_visibility(): void
    {
        DB::table('nexos_familiares')->insert([
            ['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'person_id' => $this->liderA, 'nombre' => 'Nexo de A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'campaign_id' => DB::table('lideres')->where('id', $this->liderC)->value('campaign_id'), 'person_id' => $this->liderC, 'nombre' => 'Nexo de C', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $x = $this->actingAs($this->userX)->getJson("/api/offline/download/{$this->mun}")->assertOk()->json();
        $this->assertSame([$this->liderB], array_column($x['lideres'], 'id'));
        $this->assertSame([], $x['nexos']);

        $cd = $this->actingAs($this->userCd)->getJson("/api/offline/download/{$this->mun}")->assertOk()->json();
        $this->assertEqualsCanonicalizing([$this->liderA, $this->liderB], array_column($cd['lideres'], 'id'));
        $this->assertSame(['Nexo de A'], array_column($cd['nexos'], 'nombre'));
    }

    // --- 8. Without a default, an insert that forgets the campaign fails ---

    public function test_lideres_and_nexos_without_campaign_are_rejected(): void
    {
        foreach (['lideres', 'nexos_familiares'] as $table) {
            $this->assertNull(DB::selectOne(
                "SELECT column_default FROM information_schema.columns WHERE table_name = ? AND column_name = 'campaign_id'", [$table]
            )->column_default, $table);
        }

        try {
            DB::transaction(fn () => DB::table('lideres')->insert([
                'id' => (string) Str::uuid(), 'nombre' => 'Sin campaña', 'municipio' => 'X', 'cargo' => '',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('A lider without campaign_id was stored.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('campaign_id', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('nexos_familiares')->insert([
                'id' => (string) Str::uuid(), 'person_id' => $this->liderA, 'nombre' => 'Sin campaña',
            ]));
            $this->fail('A nexo without campaign_id was stored.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('campaign_id', $e->getMessage());
        }
    }

    public function test_web_creates_still_work_without_a_default(): void
    {
        $this->actingAs($this->userX)
            ->postJson('/mapa-politico/crear-lider', ['nombre' => 'Zzcamp Nuevo X', 'municipio_id' => $this->mun])
            ->assertOk()->assertJsonPath('success', true);
        $id = $this->actingAs($this->userX)
            ->postJson('/votantes', ['nombre' => 'Zzcamp Votante X', 'municipio_id' => $this->mun])
            ->assertCreated()->json('id');
        $this->actingAs($this->userX)
            ->postJson("/mapa-politico/persona/{$id}/nexos", ['nombre' => 'Zzcamp Nexo X'])->assertOk();

        $this->assertDatabaseHas('lideres', ['nombre' => 'Zzcamp Nuevo X', 'campaign_id' => $this->campX]);
        $this->assertDatabaseHas('lideres', ['id' => $id, 'campaign_id' => $this->campX]);
        $this->assertDatabaseHas('nexos_familiares', ['person_id' => $id, 'campaign_id' => $this->campX]);
    }
}
