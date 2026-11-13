<?php

namespace Tests\Feature;

use App\Actions\Eventos\RegisterAttendee;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\EventAttendee;
use App\Models\Lider;
use App\Models\Organizacion;
use App\Models\Role;
use App\Models\User;
use App\Services\Geo\BarrioMatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// Barrio → comuna on SQLite (docs/geografia.md): the matcher's exact steps (pg_trgm similarity and the PostGIS load are
// in GeoComunasPostgresTest), the automatic assignment when a record is saved, the manual comuna of a ficha, the
// aliases that reassign in batch, the queue of texts without comuna and geo:asignar-comunas, with permissions,
// territory and grupo político.
class ComunasTest extends TestCase
{
    use WithCampaignColumns;

    private const SUB = 'aaaaaaaa-0000-4000-8000-000000000002';

    private const OTHER = 'aaaaaaaa-0000-4000-8000-000000000003';

    private array $geo = [];

    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->addCampaignColumns();
        (require database_path('migrations/2026_11_14_000001_create_barrios_and_comunas.php'))->up();
        (require database_path('migrations/2026_11_14_000002_add_comuna_to_fichas.php'))->up();
        $this->seedGeo();
    }

    private function createSchema(): void
    {
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
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable();
            $t->string('type', 30);
            $t->string('canonical_name', 150);
            $t->string('official_code', 20)->nullable();
            $t->smallInteger('level')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 80)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->json('metadata')->nullable();
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('correlation_id', 50)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('municipio')->default('');
            $t->string('provincia')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('barrio')->nullable();
            $t->string('cargo')->nullable();
            $t->string('tipo')->nullable();
            $t->string('cedula')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('municipio_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('event_attendees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('campaign_id');
            $t->uuid('lider_id')->nullable();
            $t->string('nombres')->default('');
            $t->string('apellidos')->default('');
            $t->string('cedula')->nullable();
            $t->string('celular')->nullable();
            $t->string('fijo')->nullable();
            $t->date('fecha_nacimiento')->nullable();
            $t->string('direccion')->nullable();
            $t->string('barrio')->nullable();
            $t->string('barrio_normalizado')->nullable();
            $t->boolean('autoriza_datos')->default(false);
            $t->string('source')->default('excel');
            $t->uuid('import_id')->nullable();
            $t->uuid('registered_by')->nullable();
            $t->uuid('client_uuid')->nullable();
            $t->string('ip')->nullable();
            $t->text('user_agent')->nullable();
            $t->string('link_status')->default('pendiente');
            $t->timestamps();
        });
        Schema::create('organizaciones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('municipio_id')->nullable();
            $t->string('nombre')->default('');
            $t->string('barrio')->nullable();
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
    }

    private function unit(string $key, string $type, string $name, ?string $parent, ?string $code = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert([
            'id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent ? $this->geo[$parent] : null,
            'official_code' => $code, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->geo[$key] = $id;
    }

    private function barrio(string $nombre, string $norm, string $comuna, string $municipio = 'bga'): string
    {
        $id = (string) Str::uuid();
        DB::table('barrios')->insert([
            'id' => $id, 'municipio_id' => $this->geo[$municipio], 'comuna_unit_id' => $this->geo[$comuna],
            'nombre' => $nombre, 'nombre_normalizado' => $norm, 'fuente' => 'amb', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedGeo(): void
    {
        DB::table('campaigns')->insert([
            ['id' => self::SUB, 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID, 'whatsapp_enabled' => false],
            ['id' => self::OTHER, 'name' => 'Otro grupo', 'slug' => 'otro', 'parent_id' => null, 'whatsapp_enabled' => false],
        ]);
        foreach (['R01_SUPERADMIN', 'R09_CAMPO', 'R04_DIRECTIVO'] as $code) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => 1])->id;
        }

        $this->unit('dept', 'department', 'Santander', null, '68');
        $this->unit('bga', 'municipality', 'Bucaramanga', 'dept', '68001');
        $this->unit('florida', 'municipality', 'Floridablanca', 'dept', '68276');
        $this->unit('lapaz', 'municipality', 'La Paz', 'dept', '68397');
        $this->unit('c08', 'comuna', 'Comuna 8 - Suroccidente', 'bga', '68001-C08');
        $this->unit('c10', 'comuna', 'Comuna 10 - Provenza', 'bga', '68001-C10');
        $this->unit('r1', 'corregimiento', 'Corregimiento 1', 'bga', '68001-R1');
        $this->unit('otra', 'comuna', 'Comuna 1 de otro municipio', 'florida', '68276-C01');

        $this->geo['b_cordoncillo'] = $this->barrio('CORDONCILLO I', 'CORDONCILLO 1', 'c08');
        $this->geo['b_diamante'] = $this->barrio('DIAMANTE II', 'DIAMANTE 2', 'c10');
        $this->geo['b_rincon'] = $this->barrio('RINCON DE LA PAZ', 'RINCON DE LA PAZ', 'c10');
        $this->geo['b_florida'] = $this->barrio('CANAVERAL', 'CANAVERAL', 'otra', 'florida');
    }

    private function user(array $permissions, string $role = 'R09_CAMPO', ?array $territory = null, string $campaign = Campaign::DEFAULT_ID): User
    {
        $user = User::create([
            'name' => 'Comunas', 'email' => 'comunas-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => $this->roles[$role], 'permissions' => $permissions, 'territory_scope' => $territory,
        ]);
        $user->forceFill(['campaign_id' => $campaign])->save();

        return $user->fresh();
    }

    private function writer(?array $territory = null): User
    {
        return $this->user(['view.mapa', 'view.votantes', 'write.data', 'view.admin'], 'R09_CAMPO', $territory);
    }

    private function lider(?string $barrio = null, string $municipio = 'bga', string $campaign = Campaign::DEFAULT_ID, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'nombre' => 'Ficha '.Str::random(4), 'municipio' => 'X', 'geographic_unit_id' => $this->geo[$municipio],
            'barrio' => $barrio, 'campaign_id' => $campaign, 'created_at' => now(), 'updated_at' => now(),
        ] + $extra);

        return $id;
    }

    private function comunaOf(string $table, string $id): array
    {
        $row = DB::table($table)->where('id', $id)->first(['comuna_id', 'comuna_source']);

        return [$row->comuna_id, $row->comuna_source];
    }

    // ---- matcher ----

    public function test_cordoncillo_i_and_cordoncillo_1_are_the_same_barrio(): void
    {
        $matcher = app(BarrioMatcher::class);

        foreach (['CORDONCILLO I', 'Cordoncillo 1', 'B/ cordoncillo 1, Bucaramanga', 'Barrio Cordoncillo I casa 12'] as $text) {
            $match = $matcher->match($this->geo['bga'], Campaign::DEFAULT_ID, $text);
            $this->assertSame(BarrioMatcher::ASIGNADO, $match['status'], $text);
            $this->assertSame($this->geo['c08'], $match['comuna_id'], $text);
            $this->assertSame('nombre', $match['metodo'], $text);
        }
    }

    public function test_a_text_that_names_another_municipio_is_not_assigned(): void
    {
        $matcher = app(BarrioMatcher::class);

        $this->assertSame(BarrioMatcher::OTRO_MUNICIPIO, $matcher->match($this->geo['bga'], Campaign::DEFAULT_ID, 'LA PAZ, FLORIDABLANCA')['status']);
        // "La Paz" is a municipio but also part of a Bucaramanga barrio name: that barrio is still found.
        $this->assertSame($this->geo['c10'], $matcher->match($this->geo['bga'], Campaign::DEFAULT_ID, 'Rincón de la Paz')['comuna_id']);
        $this->assertSame(BarrioMatcher::SIN_MATCH, $matcher->match($this->geo['bga'], Campaign::DEFAULT_ID, 'Balcones de Provenza')['status']);
        $this->assertSame(BarrioMatcher::VACIO, $matcher->match($this->geo['bga'], Campaign::DEFAULT_ID, '  ')['status']);
        // A barrio of another municipio never counts.
        $this->assertSame(BarrioMatcher::SIN_MATCH, $matcher->match($this->geo['bga'], Campaign::DEFAULT_ID, 'CANAVERAL')['status']);
    }

    // ---- automatic assignment when saving ----

    public function test_saving_a_ficha_assigns_its_comuna_and_never_overwrites_a_manual_one(): void
    {
        $lider = (new Lider)->forceFill(['nombre' => 'Ana', 'municipio' => 'Bucaramanga', 'geographic_unit_id' => $this->geo['bga'],
            'barrio' => 'Cordoncillo I', 'campaign_id' => Campaign::DEFAULT_ID]);
        $lider->save();
        $this->assertSame([$this->geo['c08'], 'auto'], $this->comunaOf('lideres', $lider->id));

        $lider->update(['barrio' => 'Diamante 2']);
        $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('lideres', $lider->id));

        DB::table('lideres')->where('id', $lider->id)->update(['comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual']);
        $lider = Lider::find($lider->id);
        $lider->update(['barrio' => 'Cordoncillo 1']);
        $this->assertSame([$this->geo['r1'], 'manual'], $this->comunaOf('lideres', $lider->id));

        // Another municipio without comunas: the comuna (even a manual one) no longer applies.
        $lider->update(['geographic_unit_id' => $this->geo['lapaz']]);
        $this->assertSame([null, null], $this->comunaOf('lideres', $lider->id));
    }

    public function test_a_municipio_without_comunas_works_as_before(): void
    {
        $lider = (new Lider)->forceFill(['nombre' => 'Bea', 'municipio' => 'La Paz', 'geographic_unit_id' => $this->geo['lapaz'],
            'barrio' => 'Centro', 'campaign_id' => Campaign::DEFAULT_ID]);
        $lider->save();

        $this->assertSame([null, null], $this->comunaOf('lideres', $lider->id));
    }

    public function test_attendees_and_organizations_get_the_comuna_of_their_municipio(): void
    {
        $event = (string) Str::uuid();
        DB::table('events')->insert(['id' => $event, 'campaign_id' => Campaign::DEFAULT_ID, 'municipio_id' => $this->geo['bga']]);
        $attendee = (new EventAttendee)->forceFill(['id' => (string) Str::uuid(), 'event_id' => $event, 'campaign_id' => Campaign::DEFAULT_ID,
            'nombres' => 'X', 'barrio' => 'DIAMANTE II', 'link_status' => 'pendiente']);
        $attendee->save();
        $org = (new Organizacion)->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'municipio_id' => $this->geo['bga'], 'nombre' => 'Org', 'barrio' => 'B/ Cordoncillo 1']);
        $org->save();

        $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('event_attendees', $attendee->id));
        $this->assertSame([$this->geo['c08'], 'auto'], $this->comunaOf('organizaciones', $org->id));
    }

    // ---- PATCH /votantes/{id}/comuna ----

    public function test_choosing_the_comuna_of_a_ficha_makes_it_manual_and_it_can_go_back_to_automatic(): void
    {
        $id = $this->lider('CORDONCILLO I');
        $user = $this->writer();

        $this->actingAs($user)->patchJson("/votantes/{$id}/comuna", ['comuna_id' => $this->geo['r1']])
            ->assertOk()->assertJson(['success' => true, 'comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual', 'comuna_nombre' => 'Corregimiento 1'])
            ->assertJsonCount(3, 'comunas');
        $this->assertSame([$this->geo['r1'], 'manual'], $this->comunaOf('lideres', $id));

        $this->actingAs($user)->patchJson("/votantes/{$id}/comuna", ['comuna_id' => null])
            ->assertOk()->assertJson(['comuna_id' => null, 'comuna_source' => 'manual']);

        $this->actingAs($user)->patchJson("/votantes/{$id}/comuna", ['automatica' => true])
            ->assertOk()->assertJson(['comuna_id' => $this->geo['c08'], 'comuna_source' => 'auto']);
        $this->assertSame([$this->geo['c08'], 'auto'], $this->comunaOf('lideres', $id));

        $audits = DB::table('audit_logs')->where('action', 'geo.ficha_comuna')->where('entity_id', $id)->orderBy('created_at')->get();
        $this->assertCount(3, $audits);
        $this->assertSame(['comuna_id' => null, 'comuna_source' => null], json_decode($audits[0]->before_state, true));
        $this->assertSame(['comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual'], json_decode($audits[0]->after_state, true));
    }

    public function test_a_bulk_attendee_import_gets_the_comuna_of_each_row(): void
    {
        $event = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('events')->insert([
            ['id' => $event, 'campaign_id' => Campaign::DEFAULT_ID, 'municipio_id' => $this->geo['bga']],
            ['id' => $other, 'campaign_id' => Campaign::DEFAULT_ID, 'municipio_id' => $this->geo['lapaz']],
        ]);
        $row = fn (string $cedula, ?string $barrio) => ['cedula' => $cedula, 'nombres' => 'N', 'apellidos' => 'A', 'celular' => null,
            'barrio' => $barrio, 'client_uuid' => (string) Str::uuid()];
        $register = app(RegisterAttendee::class);

        $results = $register->handleMany(CampaignEvent::find($event), [
            'a' => $row('1001', 'Cordoncillo I'),
            'b' => $row('1002', 'LA PAZ, FLORIDABLANCA'),
            'c' => $row('1003', null),
            'd' => $row('1004', 'B/ Diamante 2'),
        ], 'excel', null, null, null);

        $this->assertSame([$this->geo['c08'], 'auto'], $this->comunaOf('event_attendees', $results['a']['attendee_id']));
        $this->assertSame([null, null], $this->comunaOf('event_attendees', $results['b']['attendee_id']));
        $this->assertSame([null, null], $this->comunaOf('event_attendees', $results['c']['attendee_id']));
        $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('event_attendees', $results['d']['attendee_id']));

        $elsewhere = $register->handleMany(CampaignEvent::find($other), ['x' => $row('2001', 'Cordoncillo I')], 'excel', null, null, null);
        $this->assertSame([null, null], $this->comunaOf('event_attendees', $elsewhere['x']['attendee_id']));
    }

    public function test_the_comuna_must_be_of_the_fichas_municipio(): void
    {
        $user = $this->writer();

        $this->actingAs($user)->patchJson('/votantes/'.$this->lider('X').'/comuna', ['comuna_id' => $this->geo['otra']])
            ->assertStatus(422)->assertJsonPath('errors.comuna_id.0', 'Esa comuna no es del municipio de la ficha.');
        $this->actingAs($user)->patchJson('/votantes/'.$this->lider('X', 'lapaz').'/comuna', ['comuna_id' => null])
            ->assertStatus(422)->assertJsonPath('errors.comuna_id.0', 'El municipio de esta ficha no tiene comunas.');
        $this->actingAs($user)->patchJson('/votantes/'.Str::uuid().'/comuna', ['comuna_id' => null])->assertNotFound();
    }

    public function test_the_comuna_of_a_ficha_follows_permissions_territory_and_grupo_politico(): void
    {
        $own = $this->lider('X');
        $body = ['comuna_id' => $this->geo['c10']];

        $this->actingAs($this->user(['view.gobernador', 'write.data']))->patchJson("/votantes/{$own}/comuna", $body)->assertForbidden();
        $this->actingAs($this->user(['view.mapa', 'view.votantes'], 'R04_DIRECTIVO'))->patchJson("/votantes/{$own}/comuna", $body)->assertForbidden();
        $this->actingAs($this->writer([$this->geo['florida']]))->patchJson("/votantes/{$own}/comuna", $body)->assertForbidden();
        // A subgrupo's ficha is read-only for the parent group; another group's ficha does not exist for it.
        $this->actingAs($this->writer())->patchJson('/votantes/'.$this->lider('X', 'bga', self::SUB).'/comuna', $body)->assertForbidden();
        $this->actingAs($this->writer())->patchJson('/votantes/'.$this->lider('X', 'bga', self::OTHER).'/comuna', $body)->assertNotFound();

        $this->assertSame([null, null], $this->comunaOf('lideres', $own));
    }

    // ---- aliases ----

    public function test_an_alias_reassigns_every_record_of_the_group_with_that_text(): void
    {
        $same = [$this->lider('BALCONES DE PROVENZA'), $this->lider('Barrio Balcones de Provenza'), $this->lider('balcones de provenza, casa 4')];
        $inactive = $this->lider('BALCONES DE PROVENZA', 'bga', Campaign::DEFAULT_ID, ['is_active' => false]);
        $manual = $this->lider('BALCONES DE PROVENZA', 'bga', Campaign::DEFAULT_ID, ['comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual']);
        $sub = $this->lider('BALCONES DE PROVENZA', 'bga', self::SUB);
        $elsewhere = $this->lider('BALCONES DE PROVENZA', 'lapaz');
        $event = (string) Str::uuid();
        DB::table('events')->insert(['id' => $event, 'campaign_id' => Campaign::DEFAULT_ID, 'municipio_id' => $this->geo['bga']]);
        $attendee = (string) Str::uuid();
        DB::table('event_attendees')->insert(['id' => $attendee, 'event_id' => $event, 'campaign_id' => Campaign::DEFAULT_ID, 'barrio' => 'B/ Balcones de Provenza']);
        $org = (string) Str::uuid();
        DB::table('organizaciones')->insert(['id' => $org, 'campaign_id' => Campaign::DEFAULT_ID, 'municipio_id' => $this->geo['bga'], 'barrio' => 'BALCONES DE PROVENZA']);
        $user = $this->writer();

        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', [
            'municipio_id' => $this->geo['bga'], 'texto' => 'Balcones de Provenza', 'barrio_id' => $this->geo['b_diamante'],
        ])->assertOk()
            ->assertJson(['success' => true, 'texto' => 'BALCONES DE PROVENZA', 'reasignadas' => ['lideres' => 4, 'event_attendees' => 1, 'organizaciones' => 1]])
            ->assertJsonPath('message', 'Asignado a Comuna 10 - Provenza: 6 registros.');

        foreach ([...$same, $inactive] as $id) {
            $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('lideres', $id));
        }
        $this->assertSame([$this->geo['r1'], 'manual'], $this->comunaOf('lideres', $manual));
        $this->assertSame([null, null], $this->comunaOf('lideres', $sub));
        $this->assertSame([null, null], $this->comunaOf('lideres', $elsewhere));
        $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('event_attendees', $attendee));
        $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('organizaciones', $org));

        $alias = DB::table('barrio_aliases')->first();
        $this->assertSame(Campaign::DEFAULT_ID, $alias->campaign_id);
        $this->assertSame($this->geo['b_diamante'], $alias->barrio_id);
        $audit = DB::table('audit_logs')->where('action', 'geo.barrio_alias')->first();
        $this->assertSame($alias->id, $audit->entity_id);
        $this->assertSame(['lideres' => 4, 'event_attendees' => 1, 'organizaciones' => 1], json_decode($audit->metadata, true)['reasignadas']);

        // The alias also places new fichas of the group, not those of another group.
        $new = (new Lider)->forceFill(['nombre' => 'Nueva', 'municipio' => 'Bucaramanga', 'geographic_unit_id' => $this->geo['bga'],
            'barrio' => 'BALCONES DE PROVENZA TORRE 3', 'campaign_id' => Campaign::DEFAULT_ID]);
        $new->save();
        $this->assertSame([$this->geo['c10'], 'auto'], $this->comunaOf('lideres', $new->id));
        $this->assertSame(BarrioMatcher::SIN_MATCH, app(BarrioMatcher::class)->match($this->geo['bga'], self::SUB, 'BALCONES DE PROVENZA')['status']);

        // Assigning it again to a comuna replaces the alias.
        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', [
            'municipio_id' => $this->geo['bga'], 'texto' => 'BALCONES DE PROVENZA', 'comuna_id' => $this->geo['c08'],
        ])->assertOk()->assertJsonPath('reasignadas.lideres', 5);
        $this->assertSame(1, DB::table('barrio_aliases')->count());
        $this->assertNull(DB::table('barrio_aliases')->value('barrio_id'));
    }

    public function test_an_alias_is_validated_against_the_municipio(): void
    {
        $user = $this->writer();
        $base = ['municipio_id' => $this->geo['bga'], 'texto' => 'Algo'];

        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', $base + ['barrio_id' => $this->geo['b_florida']])
            ->assertStatus(422)->assertJsonPath('errors.barrio_id.0', 'Ese barrio no es de este municipio.');
        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', $base + ['comuna_id' => $this->geo['otra']])
            ->assertStatus(422)->assertJsonPath('errors.comuna_id.0', 'Esa comuna no es de este municipio.');
        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', $base + ['barrio_id' => $this->geo['b_cordoncillo'], 'comuna_id' => $this->geo['c10']])
            ->assertStatus(422)->assertJsonPath('errors.comuna_id.0', 'El barrio elegido es de otra comuna.');
        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', ['municipio_id' => $this->geo['bga'], 'texto' => 'Bucaramanga', 'comuna_id' => $this->geo['c08']])
            ->assertStatus(422)->assertJsonPath('errors.texto.0', 'El texto no tiene un nombre de barrio.');
        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', $base)->assertStatus(422)->assertJsonValidationErrors(['barrio_id', 'comuna_id']);
        $this->actingAs($user)->postJson('/admin/territorio/barrios/alias', ['municipio_id' => $this->geo['lapaz'], 'texto' => 'Centro', 'comuna_id' => $this->geo['c08']])
            ->assertNotFound();

        $this->assertSame(0, DB::table('barrio_aliases')->count());
    }

    public function test_aliases_need_admin_a_write_role_and_the_territory(): void
    {
        $body = ['municipio_id' => $this->geo['bga'], 'texto' => 'Balcones', 'comuna_id' => $this->geo['c10']];

        $this->actingAs($this->user(['view.mapa', 'view.votantes', 'write.data']))->postJson('/admin/territorio/barrios/alias', $body)->assertForbidden();
        $this->actingAs($this->user(['view.admin'], 'R04_DIRECTIVO'))->postJson('/admin/territorio/barrios/alias', $body)->assertForbidden();
        $this->actingAs($this->writer([$this->geo['florida']]))->postJson('/admin/territorio/barrios/alias', $body)->assertForbidden();

        $this->assertSame(0, DB::table('barrio_aliases')->count());
    }

    // ---- queue ----

    public function test_the_queue_lists_the_groups_texts_without_comuna(): void
    {
        $this->lider('Balcones de Provenza');
        $this->lider('BARRIO BALCONES DE PROVENZA');
        $this->lider('LA PAZ, FLORIDABLANCA');
        $this->lider('Cordoncillo I', 'bga', Campaign::DEFAULT_ID, ['comuna_id' => $this->geo['c08'], 'comuna_source' => 'auto']);
        $this->lider('Sin comuna a mano', 'bga', Campaign::DEFAULT_ID, ['comuna_source' => 'manual']);
        $this->lider('Del subgrupo', 'bga', self::SUB);
        $this->lider('Otro municipio', 'lapaz');
        $this->lider('Inactiva', 'bga', Campaign::DEFAULT_ID, ['is_active' => false]);

        $res = $this->actingAs($this->writer())->getJson('/admin/territorio/barrios-sin-comuna')->assertOk()
            ->assertJsonPath('municipio.nombre', 'Bucaramanga')
            ->assertJsonPath('municipios', [['id' => $this->geo['bga'], 'nombre' => 'Bucaramanga'], ['id' => $this->geo['florida'], 'nombre' => 'Floridablanca']])
            ->assertJsonCount(3, 'comunas')
            ->assertJsonCount(3, 'barrios')
            ->assertJsonPath('total_textos', 2)
            ->json('pendientes');

        $this->assertSame('BALCONES DE PROVENZA', $res[0]['texto']);
        $this->assertSame(2, $res[0]['lideres']);
        $this->assertSame(BarrioMatcher::SIN_MATCH, $res[0]['estado']);
        $this->assertEqualsCanonicalizing(['Balcones de Provenza', 'BARRIO BALCONES DE PROVENZA'], $res[0]['ejemplos']);
        $this->assertSame('LA PAZ FLORIDABLANCA', $res[1]['texto']);
        $this->assertSame(BarrioMatcher::OTRO_MUNICIPIO, $res[1]['estado']);
    }

    public function test_the_queue_follows_permissions_and_territory(): void
    {
        $this->actingAs($this->user(['view.mapa', 'view.votantes', 'write.data']))->getJson('/admin/territorio/barrios-sin-comuna')->assertForbidden();
        $this->actingAs($this->writer())->getJson('/admin/territorio/barrios-sin-comuna?municipio_id='.$this->geo['lapaz'])->assertNotFound();
        $this->actingAs($this->writer([$this->geo['lapaz']]))->getJson('/admin/territorio/barrios-sin-comuna?municipio_id='.$this->geo['bga'])->assertForbidden();
        $this->actingAs($this->writer([$this->geo['lapaz']]))->getJson('/admin/territorio/barrios-sin-comuna')
            ->assertOk()->assertJson(['success' => true, 'municipios' => [], 'pendientes' => []]);
    }

    // ---- geo:asignar-comunas ----

    public function test_the_command_assigns_in_batch_respects_manual_and_dry_run_writes_nothing(): void
    {
        $auto = $this->lider('Cordoncillo 1');
        $manual = $this->lider('Cordoncillo 1', 'bga', Campaign::DEFAULT_ID, ['comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual']);
        $otro = $this->lider('LA PAZ, FLORIDABLANCA');
        $none = $this->lider(null);
        $moved = $this->lider('Cordoncillo 1', 'lapaz', Campaign::DEFAULT_ID, ['comuna_id' => $this->geo['c08'], 'comuna_source' => 'auto']);
        $snapshot = fn () => DB::table('lideres')->orderBy('id')->get(['id', 'comuna_id', 'comuna_source'])->toJson();
        $before = $snapshot();

        $this->artisan('geo:asignar-comunas', ['--municipio' => '68001', '--dry-run' => true])
            ->expectsOutputToContain('Otro municipio (texto normalizado: registros):')
            ->expectsOutputToContain('  - LA PAZ FLORIDABLANCA: 1')
            ->expectsOutputToContain('DRY-RUN: no se guardó nada.')
            ->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->artisan('geo:asignar-comunas', ['--municipio' => '68001'])->expectsOutputToContain('Comunas asignadas.')->assertSuccessful();

        $this->assertSame([$this->geo['c08'], 'auto'], $this->comunaOf('lideres', $auto));
        $this->assertSame([$this->geo['r1'], 'manual'], $this->comunaOf('lideres', $manual));
        $this->assertSame([null, null], $this->comunaOf('lideres', $otro));
        $this->assertSame([null, null], $this->comunaOf('lideres', $none));
        $this->assertSame([null, null], $this->comunaOf('lideres', $moved));
        $resumen = json_decode(DB::table('audit_logs')->where('action', 'geo.asignar_comunas')->value('metadata'), true)['resumen']['lideres'];
        $this->assertSame(['registros' => 4, 'asignadas' => 1, 'manual' => 1, 'otro_municipio' => 1, 'vacio' => 1, 'limpiadas_otro_municipio' => 1],
            array_intersect_key($resumen, array_flip(['registros', 'asignadas', 'manual', 'otro_municipio', 'vacio', 'limpiadas_otro_municipio'])));

        $after = $snapshot();
        $this->artisan('geo:asignar-comunas', ['--municipio' => '68001'])->assertSuccessful();
        $this->assertSame($after, $snapshot());
    }

    public function test_the_command_needs_a_municipio_with_comunas(): void
    {
        $this->artisan('geo:asignar-comunas', ['--municipio' => '68397'])
            ->expectsOutputToContain('La Paz no tiene comunas cargadas')->assertFailed();
        $this->artisan('geo:asignar-comunas', ['--municipio' => '99999'])->expectsOutputToContain('No existe el municipio 99999.')->assertFailed();
        $this->artisan('geo:asignar-comunas', ['--tabla' => ['personas']])->assertFailed();
    }

    // ---- comunas.geojson (the PostGIS body is in GeoComunasPostgresTest) ----

    public function test_the_comunas_endpoint_needs_view_mapa_and_a_dane_code(): void
    {
        $this->get('/api/geo/comunas.geojson?municipio=68001')->assertRedirect('/login');
        $this->actingAs($this->user(['view.votantes']))->getJson('/api/geo/comunas.geojson?municipio=68001')->assertForbidden();
        $this->actingAs($this->user(['view.mapa']))->getJson('/api/geo/comunas.geojson?municipio=bga')->assertStatus(422);
        $this->actingAs($this->user(['view.mapa']))->getJson('/api/geo/comunas.geojson')->assertStatus(422);
    }
}
