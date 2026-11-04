<?php

namespace Tests\Feature;

use App\Http\Controllers\PersonaController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsElectoralSchema;
use Tests\TestCase;

// data:merge-persons: two persons rows of the same human become one; both candidacies (results and endorsements
// included) end up on the survivor and every other reference follows, without duplicates.
class MergePersonsTest extends TestCase
{
    use BuildsElectoralSchema;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElectoralSchema();
        $this->buildExtraSchema();

        $gu = $this->row('geographic_units', ['type' => 'department', 'canonical_name' => 'Santander']);
        $ev2023 = $this->row('electoral_events', ['name' => 'Elecciones territoriales 2023', 'election_date' => '2023-10-29']);
        $ev2026 = $this->row('electoral_events', ['name' => 'Elecciones legislativas 2026', 'election_date' => '2026-03-08']);
        $gobernacion = $this->row('offices', ['name' => 'Gobernación']);
        $camara = $this->row('corporations', ['name' => 'Cámara de Representantes']);
        $this->ids['contestGob'] = $this->row('contests', ['electoral_event_id' => $ev2023, 'office_id' => $gobernacion, 'geographic_unit_id' => $gu, 'name' => 'Gobernación Santander 2023']);
        $this->ids['contestCam'] = $this->row('contests', ['electoral_event_id' => $ev2026, 'corporation_id' => $camara, 'geographic_unit_id' => $gu, 'name' => 'Cámara Santander 2026']);
        $dignidad = $this->row('political_organizations', ['canonical_name' => 'Dignidad Y Compromiso']);
        $verde = $this->row('political_organizations', ['canonical_name' => 'Alianza Verde']);

        $a = $this->row('persons', [
            'first_name' => 'Luis Ferley', 'last_name' => 'Sierra Jaimes', 'full_name' => 'Luis Ferley Sierra Jaimes',
            'normalized_name' => 'LUIS FERLEY SIERRA JAIMES', 'identity_status' => 'unverified', 'gender' => 'M',
        ]);
        $b = $this->row('persons', [
            'first_name' => 'LUIS', 'last_name' => 'FERLEY SIERRA', 'full_name' => 'Luis Ferley Sierra',
            'normalized_name' => 'LUIS FERLEY SIERRA', 'identity_status' => 'unverified', 'gender' => 'F', 'birth_date' => '1970-05-01',
        ]);
        $other = $this->row('persons', ['full_name' => 'Otra Persona', 'normalized_name' => 'OTRA PERSONA']);
        $this->ids += ['a' => $a, 'b' => $b, 'other' => $other];

        $this->row('person_aliases', ['person_id' => $a, 'alias_name' => 'FERLEY PARRA', 'normalized_alias' => 'FERLEY PARRA', 'alias_type' => 'electoral']);
        $this->ids['aliasB'] = $this->row('person_aliases', ['person_id' => $b, 'alias_name' => 'LUIS FERLEY SIERRA', 'normalized_alias' => 'LUIS FERLEY SIERRA', 'alias_type' => 'electoral']);
        $this->ids['aliasDup'] = $this->row('person_aliases', ['person_id' => $b, 'alias_name' => 'Ferley Parra', 'normalized_alias' => 'FERLEY PARRA', 'alias_type' => 'name']);

        $this->ids['candGob'] = $this->row('candidacies', ['person_id' => $a, 'contest_id' => $this->ids['contestGob'], 'outcome' => 'not_elected']);
        $this->row('candidacy_endorsements', ['candidacy_id' => $this->ids['candGob'], 'organization_id' => $dignidad, 'endorsement_type' => 'endorsement']);
        $this->row('electoral_results', ['candidacy_id' => $this->ids['candGob'], 'contest_id' => $this->ids['contestGob'], 'metric_type' => 'votes', 'value' => 181399]);
        $this->ids['candCam'] = $this->row('candidacies', ['person_id' => $b, 'contest_id' => $this->ids['contestCam'], 'outcome' => 'pending']);
        $this->row('candidacy_endorsements', ['candidacy_id' => $this->ids['candCam'], 'organization_id' => $verde, 'endorsement_type' => 'aval']);
        foreach ([10045, 18873] as $v) {
            $this->row('electoral_results', ['candidacy_id' => $this->ids['candCam'], 'contest_id' => $this->ids['contestCam'], 'metric_type' => 'nominal_votes', 'value' => $v]);
        }

        $this->row('person_contact_points', ['person_id' => $a, 'type' => 'mobile', 'value_raw' => '3001112233']);
        $this->ids['contactDup'] = $this->row('person_contact_points', ['person_id' => $b, 'type' => 'mobile', 'value_raw' => '3001112233']);
        $this->ids['contactNew'] = $this->row('person_contact_points', ['person_id' => $b, 'type' => 'email', 'value_raw' => 'x@example.com']);

        $this->ids['relSelf'] = $this->row('relationships', ['person_a_id' => $b, 'person_b_id' => $a, 'type' => 'political', 'is_symmetric' => true]);
        $this->row('relationships', ['person_a_id' => $a, 'person_b_id' => $other, 'type' => 'ally', 'is_symmetric' => true]);
        $this->ids['relDup'] = $this->row('relationships', ['person_a_id' => $other, 'person_b_id' => $b, 'type' => 'ally', 'is_symmetric' => true]);
        $this->ids['relMove'] = $this->row('relationships', ['person_a_id' => $b, 'person_b_id' => $other, 'type' => 'familiar', 'is_symmetric' => true]);

        $user = (string) Str::uuid();
        $this->row('favorites', ['user_id' => $user, 'favoritable_type' => 'person', 'favoritable_id' => $a]);
        $this->ids['favDup'] = $this->row('favorites', ['user_id' => $user, 'favoritable_type' => 'person', 'favoritable_id' => $b]);
        DB::table('recent_views')->insert(['id' => (string) Str::uuid(), 'user_id' => $user, 'viewable_type' => 'person', 'viewable_id' => $a, 'viewed_at' => now()]);
        DB::table('recent_views')->insert(['id' => (string) Str::uuid(), 'user_id' => $user, 'viewable_type' => 'person', 'viewable_id' => $b, 'viewed_at' => now()]);
        DB::table('recent_views')->insert(['id' => (string) Str::uuid(), 'user_id' => (string) Str::uuid(), 'viewable_type' => 'person', 'viewable_id' => $b, 'viewed_at' => now()]);

        $tag = (string) Str::uuid();
        DB::table('taggables')->insert([['tag_id' => $tag, 'taggable_type' => 'person', 'taggable_id' => $a], ['tag_id' => $tag, 'taggable_type' => 'person', 'taggable_id' => $b]]);
        DB::table('taggables')->insert(['tag_id' => (string) Str::uuid(), 'taggable_type' => 'person', 'taggable_id' => $b]);

        $this->ids['nexo'] = $this->row('nexos_familiares', ['person_id' => $b, 'nombre' => 'Hermano']);
        $this->ids['figure'] = $this->row('event_figures', ['event_id' => (string) Str::uuid(), 'person_id' => $b, 'nombre' => 'Luis Ferley Sierra']);
        $this->ids['source'] = $this->row('source_records', ['target_entity' => 'person', 'target_id' => $b]);
        $this->ids['campaign'] = $this->row('campaigns', ['name' => 'Verde', 'slug' => 'verde', 'candidate_person_id' => $b]);
        DB::table('whatsapp_send_recipients')->insert(['id' => 7, 'person_id' => $b, 'name' => 'Luis', 'phone' => '3001112233']);
        $this->ids['staging'] = $this->row('import_staging_rows', ['matched_entities' => json_encode(['person' => $b, 'org' => [$verde]])]);
        $this->ids['chained'] = $this->row('persons', ['full_name' => 'Luis F Sierra', 'normalized_name' => 'LUIS F SIERRA', 'merged_into_id' => $b, 'deleted_at' => now()]);
    }

    private function buildExtraSchema(): void
    {
        Schema::table('persons', function (Blueprint $t) {
            $t->string('photo_path')->nullable();
            $t->string('gender')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('document_type')->nullable();
            $t->string('doc_hash')->nullable();
            $t->string('doc_last4')->nullable();
        });
        Schema::table('contests', fn (Blueprint $t) => $t->uuid('corporation_id')->nullable());
        Schema::table('political_organizations', fn (Blueprint $t) => $t->string('color_hex')->nullable());
        Schema::table('office_tenures', fn (Blueprint $t) => $t->uuid('corporation_id')->nullable());
        Schema::table('candidacies', fn (Blueprint $t) => $t->unique(['person_id', 'contest_id']));

        $tables = [
            'corporations' => fn (Blueprint $t) => [$t->string('name')],
            'person_facts' => fn (Blueprint $t) => [$t->uuid('person_id'), $t->string('category'), $t->string('title'), $t->date('valid_from')->nullable(), $t->date('valid_to')->nullable()],
            'relationships' => fn (Blueprint $t) => [
                $t->uuid('person_a_id'), $t->uuid('person_b_id'), $t->string('type'), $t->string('subtype')->nullable(), $t->boolean('is_symmetric')->default(true),
            ],
            'organization_memberships' => fn (Blueprint $t) => [$t->uuid('person_id'), $t->uuid('organization_id'), $t->string('role')->nullable(), $t->date('valid_from')->nullable()],
            'favorites' => fn (Blueprint $t) => [$t->uuid('user_id'), $t->string('favoritable_type'), $t->uuid('favoritable_id'), $t->unique(['user_id', 'favoritable_type', 'favoritable_id'])],
            'event_figures' => fn (Blueprint $t) => [$t->uuid('event_id'), $t->uuid('person_id')->nullable(), $t->string('nombre')],
            'document_associations' => fn (Blueprint $t) => [$t->uuid('document_id'), $t->string('associable_type'), $t->uuid('associable_id')],
            'activity_participants' => fn (Blueprint $t) => [$t->uuid('activity_id'), $t->string('participant_type'), $t->uuid('participant_id')],
            'source_records' => fn (Blueprint $t) => [$t->string('target_entity')->nullable(), $t->uuid('target_id')->nullable()],
            'import_staging_rows' => fn (Blueprint $t) => [$t->json('matched_entities')->nullable()],
            'person_merge_transactions' => fn (Blueprint $t) => [
                $t->uuid('survivor_id'), $t->uuid('absorbed_id'), $t->uuid('performed_by')->nullable(), $t->float('match_score')->nullable(),
                $t->json('match_evidence')->nullable(), $t->json('before_snapshot')->nullable(), $t->string('status')->default('completed'), $t->text('reason')->nullable(),
            ],
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->uuid('id')->primary();
                $columns($t);
                $t->timestamps();
            });
        }

        Schema::create('recent_views', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('viewable_type');
            $t->uuid('viewable_id');
            $t->timestamp('viewed_at');
        });
        Schema::create('taggables', function (Blueprint $t) {
            $t->uuid('tag_id');
            $t->string('taggable_type');
            $t->uuid('taggable_id');
        });
        Schema::create('whatsapp_send_recipients', function (Blueprint $t) {
            $t->id();
            $t->uuid('person_id');
            $t->string('name');
            $t->string('phone');
            $t->timestamps();
        });
    }

    private function snapshot(): array
    {
        $tables = ['persons', 'person_aliases', 'person_contact_points', 'candidacies', 'candidacy_endorsements', 'electoral_results', 'relationships',
            'favorites', 'recent_views', 'taggables', 'nexos_familiares', 'event_figures', 'source_records', 'campaigns', 'whatsapp_send_recipients',
            'import_staging_rows', 'person_merge_transactions', 'audit_logs'];

        return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
    }

    private function merge(array $options = [])
    {
        return $this->artisan('data:merge-persons', ['survivor' => $this->ids['a'], 'absorbed' => $this->ids['b']] + $options);
    }

    public function test_moves_both_candidacies_and_every_reference_to_the_survivor(): void
    {
        $this->merge(['--reason' => 'Confirmado por Marly'])->expectsOutput('Fusión aplicada.')->assertSuccessful();

        $a = $this->ids['a'];
        $b = $this->ids['b'];

        // Candidacies, results and endorsements intact, now both on A.
        $this->assertSame($a, DB::table('candidacies')->where('id', $this->ids['candCam'])->value('person_id'));
        $this->assertSame($a, DB::table('candidacies')->where('id', $this->ids['candGob'])->value('person_id'));
        $this->assertSame(28918, (int) DB::table('electoral_results')->where('candidacy_id', $this->ids['candCam'])->sum('value'));
        $this->assertSame(181399, (int) DB::table('electoral_results')->where('candidacy_id', $this->ids['candGob'])->sum('value'));
        $this->assertSame(2, DB::table('candidacy_endorsements')->whereIn('candidacy_id', [$this->ids['candCam'], $this->ids['candGob']])->count());

        // Aliases: B's name moved, duplicate discarded, no extra alias needed.
        $this->assertEqualsCanonicalizing(['FERLEY PARRA', 'LUIS FERLEY SIERRA'], DB::table('person_aliases')->where('person_id', $a)->pluck('normalized_alias')->all());
        $this->assertSame(0, DB::table('person_aliases')->where('person_id', $b)->count());
        $this->assertDatabaseMissing('person_aliases', ['id' => $this->ids['aliasDup']]);

        // Contact points deduplicated.
        $this->assertDatabaseMissing('person_contact_points', ['id' => $this->ids['contactDup']]);
        $this->assertSame($a, DB::table('person_contact_points')->where('id', $this->ids['contactNew'])->value('person_id'));

        // Relationships: no self-relation, no duplicate, the rest moved.
        $this->assertDatabaseMissing('relationships', ['id' => $this->ids['relSelf']]);
        $this->assertDatabaseMissing('relationships', ['id' => $this->ids['relDup']]);
        $this->assertSame($a, DB::table('relationships')->where('id', $this->ids['relMove'])->value('person_a_id'));
        $this->assertSame(0, DB::table('relationships')->whereColumn('person_a_id', 'person_b_id')->count());

        // Favorites / recent views / tags deduplicated per user / tag.
        $this->assertDatabaseMissing('favorites', ['id' => $this->ids['favDup']]);
        $this->assertSame(1, DB::table('favorites')->where('favoritable_id', $a)->count());
        $this->assertSame(2, DB::table('recent_views')->where('viewable_id', $a)->count());
        $this->assertSame(2, DB::table('taggables')->where('taggable_id', $a)->count());

        foreach (['nexos_familiares' => 'nexo', 'event_figures' => 'figure'] as $table => $k) {
            $this->assertSame($a, DB::table($table)->where('id', $this->ids[$k])->value('person_id'));
        }
        $this->assertSame($a, DB::table('source_records')->where('id', $this->ids['source'])->value('target_id'));
        $this->assertSame($a, DB::table('campaigns')->where('id', $this->ids['campaign'])->value('candidate_person_id'));
        $this->assertSame($a, DB::table('whatsapp_send_recipients')->where('id', 7)->value('person_id'));
        $this->assertSame($a, json_decode(DB::table('import_staging_rows')->where('id', $this->ids['staging'])->value('matched_entities'), true)['person']);
        $this->assertSame($a, DB::table('persons')->where('id', $this->ids['chained'])->value('merged_into_id'));

        // Nothing points at B any more.
        foreach ([['candidacies', 'person_id'], ['person_contact_points', 'person_id'], ['relationships', 'person_a_id'], ['relationships', 'person_b_id'],
            ['favorites', 'favoritable_id'], ['recent_views', 'viewable_id'], ['taggables', 'taggable_id'], ['nexos_familiares', 'person_id'],
            ['persons', 'merged_into_id']] as [$table, $column]) {
            $this->assertSame(0, DB::table($table)->where($column, $b)->count(), "{$table}.{$column}");
        }

        // Survivor keeps its name and gender, gets B's birth date; B soft-deleted and pointing at A.
        $survivor = DB::table('persons')->where('id', $a)->first();
        $this->assertSame('Luis Ferley Sierra Jaimes', $survivor->full_name);
        $this->assertSame('M', $survivor->gender);
        $this->assertSame('1970-05-01', substr((string) $survivor->birth_date, 0, 10));
        $absorbed = DB::table('persons')->where('id', $b)->first();
        $this->assertSame($a, $absorbed->merged_into_id);
        $this->assertSame('merged', $absorbed->identity_status);
        $this->assertNotNull($absorbed->deleted_at);

        // Merge transaction and audit without names.
        $tx = DB::table('person_merge_transactions')->first();
        $this->assertSame([$a, $b, 'completed', 'Confirmado por Marly'], [$tx->survivor_id, $tx->absorbed_id, $tx->status, $tx->reason]);
        $audit = DB::table('audit_logs')->where('action', 'DATA_MERGE_PERSONS')->first();
        $this->assertSame($a, $audit->entity_id);
        $this->assertSame($b, json_decode($audit->metadata, true)['absorbed_id']);
        $this->assertStringNotContainsStringIgnoringCase('ferley', $audit->metadata.$tx->before_snapshot);
    }

    public function test_adds_the_absorbed_name_as_alias_when_missing(): void
    {
        DB::table('person_aliases')->where('id', $this->ids['aliasB'])->delete();

        $this->merge()->assertSuccessful();

        $this->assertDatabaseHas('person_aliases', ['person_id' => $this->ids['a'], 'normalized_alias' => 'LUIS FERLEY SIERRA', 'alias_name' => 'Luis Ferley Sierra']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = $this->snapshot();

        $this->merge(['--dry-run' => true])->expectsOutput('DRY-RUN: no se guardó nada.')->assertSuccessful();

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_is_idempotent(): void
    {
        $this->merge()->assertSuccessful();
        $after = $this->snapshot();

        $this->merge()->expectsOutput('Ya fusionadas: nada que hacer.')->assertSuccessful();

        $this->assertEquals($after, $this->snapshot());
    }

    public function test_aborts_when_both_ran_in_the_same_contest(): void
    {
        $this->row('candidacies', ['person_id' => $this->ids['b'], 'contest_id' => $this->ids['contestGob'], 'outcome' => 'not_elected']);
        $before = $this->snapshot();

        $this->merge()->expectsOutput('Fusión abortada: no se guardó nada.')->assertFailed();

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_aborts_when_identity_documents_differ(): void
    {
        DB::table('persons')->where('id', $this->ids['a'])->update(['doc_hash' => str_repeat('a', 64)]);
        DB::table('persons')->where('id', $this->ids['b'])->update(['doc_hash' => str_repeat('b', 64)]);
        $before = $this->snapshot();

        $this->merge()->assertFailed();

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_rejects_bad_input(): void
    {
        $this->artisan('data:merge-persons', ['survivor' => 'x', 'absorbed' => $this->ids['b']])->assertFailed();
        $this->artisan('data:merge-persons', ['survivor' => $this->ids['a'], 'absorbed' => $this->ids['a']])->assertFailed();
        $this->artisan('data:merge-persons', ['survivor' => $this->ids['a'], 'absorbed' => (string) Str::uuid()])->assertFailed();
        // A survivor that was itself merged away cannot receive anything.
        $this->artisan('data:merge-persons', ['survivor' => $this->ids['chained'], 'absorbed' => $this->ids['other']])->assertFailed();
        $this->assertSame(0, DB::table('person_merge_transactions')->count());
    }

    public function test_ficha_shows_both_candidacies_and_old_link_redirects(): void
    {
        $this->merge()->assertSuccessful();

        $redirect = app(PersonaController::class)->show($this->ids['b']);
        $this->assertInstanceOf(RedirectResponse::class, $redirect);
        $this->assertStringEndsWith('/persona/'.$this->ids['a'], $redirect->getTargetUrl());

        $props = (fn () => $this->props)->call(app(PersonaController::class)->show($this->ids['a']));
        $this->assertSame('Luis Ferley Sierra Jaimes', $props['persona']['name']);
        $this->assertEqualsCanonicalizing(['Gobernación Santander 2023', 'Cámara Santander 2026'], array_column($props['candidaturas'], 'contienda'));
        $this->assertEqualsCanonicalizing([181399, 28918], array_column($props['candidaturas'], 'votos'));
        $this->assertEqualsCanonicalizing(['Dignidad Y Compromiso', 'Alianza Verde'], array_column($props['candidaturas'], 'partido'));
    }

    public function test_polymorphic_rows_of_another_entity_type_are_not_touched(): void
    {
        $user = (string) Str::uuid();
        $foreign = $this->row('favorites', ['user_id' => $user, 'favoritable_type' => 'lider', 'favoritable_id' => $this->ids['b']]);
        DB::table('taggables')->insert(['tag_id' => (string) Str::uuid(), 'taggable_type' => 'lider', 'taggable_id' => $this->ids['b']]);
        $source = $this->row('source_records', ['target_entity' => 'candidacy', 'target_id' => $this->ids['b']]);

        $this->merge()->assertSuccessful();

        $this->assertSame($this->ids['b'], DB::table('favorites')->where('id', $foreign)->value('favoritable_id'));
        $this->assertSame(1, DB::table('taggables')->where('taggable_type', 'lider')->where('taggable_id', $this->ids['b'])->count());
        $this->assertSame($this->ids['b'], DB::table('source_records')->where('id', $source)->value('target_id'));
        $this->assertSame($this->ids['a'], DB::table('source_records')->where('id', $this->ids['source'])->value('target_id'));
    }

    public function test_rows_written_after_the_plan_also_move(): void
    {
        $late = (string) Str::uuid();
        $inserted = false;
        DB::listen(function ($query) use (&$inserted, $late) {
            if (! $inserted && str_starts_with(strtolower($query->sql), 'update "candidacies"')) {
                $inserted = true;
                DB::table('person_contact_points')->insert(['id' => $late, 'person_id' => $this->ids['b'], 'type' => 'mobile', 'value_raw' => '3109998877']);
            }
        });

        $this->merge()->assertSuccessful();

        $this->assertTrue($inserted);
        $this->assertSame($this->ids['a'], DB::table('person_contact_points')->where('id', $late)->value('person_id'));
        $this->assertSame(1, json_decode(DB::table('audit_logs')->where('action', 'DATA_MERGE_PERSONS')->value('metadata'), true)['filas_tardias']);
        $snapshot = json_decode(DB::table('person_merge_transactions')->value('before_snapshot'), true);
        $this->assertSame(['person_contact_points.person_id' => [$late]], $snapshot['moved_late']);
    }
}
