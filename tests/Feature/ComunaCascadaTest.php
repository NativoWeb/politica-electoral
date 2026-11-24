<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lider;
use App\Models\Role;
use App\Models\User;
use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\PuestoNormalizer;
use App\Services\Geo\Trigram;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// The comuna cascade on SQLite with synthetic fichas (docs/geografia.md): manual > barrio > "COMUNA N" written >
// polling station; geo:asignar-comunas (dry-run, batch log, idempotency), geo:revertir-comunas, the rollback guard of
// 2026_11_24_000001, the nightly run and data:electo-barrios. The polling station match is done in PHP, so it is the
// same here as in PostgreSQL (ComunaCascadaPostgresTest covers the PostGIS load and pg_trgm parity).
class ComunaCascadaTest extends TestCase
{
    use WithCampaignColumns;

    private array $geo = [];

    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->addCampaignColumns();
        (require database_path('migrations/2026_11_14_000001_create_barrios_and_comunas.php'))->up();
        (require database_path('migrations/2026_11_14_000002_add_comuna_to_fichas.php'))->up();
        (require database_path('migrations/2026_11_24_000001_add_comuna_cascade.php'))->up();
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
            $t->string('direccion')->nullable();
            $t->string('municipio_votacion')->nullable();
            $t->string('puesto_votacion')->nullable();
            $t->string('cargo')->nullable();
            $t->string('tipo')->nullable();
            $t->string('cedula')->nullable();
            $t->boolean('is_active')->default(true);
            $t->json('migracion_flags')->nullable();
            $t->timestamps();
        });
        Schema::create('events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('municipio_id')->nullable();
            $t->timestamps();
        });
        Schema::create('event_attendees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('campaign_id');
            $t->string('direccion')->nullable();
            $t->string('barrio')->nullable();
            $t->string('link_status')->default('pendiente');
            $t->timestamps();
        });
        Schema::create('organizaciones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('municipio_id')->nullable();
            $t->string('nombre')->default('');
            $t->string('direccion')->nullable();
            $t->string('barrio')->nullable();
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

    private function seedGeo(): void
    {
        foreach (['R09_CAMPO', 'R04_DIRECTIVO'] as $code) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => 1])->id;
        }
        $this->unit('dept', 'department', 'Santander', null, '68');
        $this->unit('bga', 'municipality', 'Bucaramanga', 'dept', '68001');
        $this->unit('florida', 'municipality', 'Floridablanca', 'dept', '68276');
        foreach (['03', '05', '06', '07', '12', '13'] as $n) {
            $this->unit("c{$n}", 'comuna', "Comuna {$n}", 'bga', "68001-C{$n}");
        }
        $this->unit('r1', 'corregimiento', 'Corregimiento 1', 'bga', '68001-R1');

        foreach (['LA JOYA' => 'c05', 'COMUNEROS' => 'c03'] as $name => $comuna) {
            DB::table('barrios')->insert(['id' => (string) Str::uuid(), 'municipio_id' => $this->geo['bga'], 'comuna_unit_id' => $this->geo[$comuna],
                'nombre' => $name, 'nombre_normalizado' => $name, 'fuente' => 'amb', 'created_at' => now(), 'updated_at' => now()]);
        }
        $puestos = [
            ['COL SAN PEDRO CLAVER', 'c12', ['SAN PEDRO CLAVER']],
            ['IE CAMPO HERMOSO', 'c05', []],
            ['COL AURELIO MARTINEZ MUTIS SEDE A', 'c07', []],
            ['COL AURELIO MARTINEZ MUTIS SEDE B', 'c06', []],
            ['ESC NORMAL SUPERIOR SEDE A', 'c13', []],
            ['ESC NORMAL SUPERIOR SEDE C', 'c13', []],
        ];
        foreach ($puestos as [$name, $comuna, $alias]) {
            DB::table('puestos_votacion')->insert([
                'id' => (string) Str::uuid(), 'municipio_id' => $this->geo['bga'], 'nombre' => $name,
                'nombre_normalizado' => PuestoNormalizer::normalize($name), 'comuna_unit_id' => $this->geo[$comuna], 'comuna_origen' => 'dataset',
                'fuente' => 'test', 'anio' => 2023, 'alias' => $alias ? json_encode($alias) : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // A ficha written by the query builder (as bulk loads do): no model hook, the command assigns it.
    private function row(array $values = [], string $municipio = 'bga'): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert($values + [
            'id' => $id, 'nombre' => 'Ficha '.Str::random(4), 'geographic_unit_id' => $this->geo[$municipio],
            'campaign_id' => Campaign::DEFAULT_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function lider(array $values, string $municipio = 'bga'): Lider
    {
        $lider = (new Lider)->forceFill($values + ['nombre' => 'Ficha', 'municipio' => 'X', 'geographic_unit_id' => $this->geo[$municipio],
            'campaign_id' => Campaign::DEFAULT_ID]);
        $lider->save();

        return $lider;
    }

    private function comunaOf(string $id): array
    {
        $row = DB::table('lideres')->where('id', $id)->first(['comuna_id', 'comuna_source']);

        return [$row->comuna_id, $row->comuna_source];
    }

    private function votaBga(string $puesto): array
    {
        return ['puesto_votacion' => $puesto, 'municipio_votacion' => 'BUCARAMANGA'];
    }

    private function snapshot(): string
    {
        return DB::table('lideres')->orderBy('id')->get(['id', 'barrio', 'comuna_id', 'comuna_source', 'migracion_flags'])->toJson();
    }

    private function writer(): User
    {
        $user = User::create([
            'name' => 'Cascada', 'email' => 'cascada-'.Str::random(6).'@test.local', 'password' => 'secret123',
            'role_id' => $this->roles['R09_CAMPO'], 'permissions' => ['view.mapa', 'view.votantes', 'write.data'],
        ]);
        $user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        return $user->fresh();
    }

    // ---- the cascade when a ficha is saved ----

    public function test_the_strongest_signal_wins_and_the_next_takes_over_when_it_goes(): void
    {
        $both = $this->lider(['barrio' => 'La Joya'] + $this->votaBga('Colegio San Pedro Claver'));
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($both->id), 'barrio C05 beats puesto C12');

        $lider = $this->lider($this->votaBga('Colegio San Pedro Claver'));
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($lider->id));

        $lider->update(['barrio' => 'Comuneros']);
        $this->assertSame([$this->geo['c03'], 'auto'], $this->comunaOf($lider->id));

        $lider->update(['barrio' => null]);
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($lider->id), 'without barrio it falls back to the puesto, not to empty');

        $lider->update(['direccion' => 'Calle 5 # 3-2 comuna 6']);
        $this->assertSame([$this->geo['c06'], 'texto'], $this->comunaOf($lider->id), 'a written comuna beats the puesto');

        $lider->update(['barrio' => 'La Joya']);
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($lider->id), 'the barrio beats a written comuna');

        $lider->update(['barrio' => 'Barrio que no existe', 'direccion' => null]);
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($lider->id), 'an unknown barrio does not block the puesto');

        $lider->update(['puesto_votacion' => 'IE Campo Hermoso']);
        $this->assertSame([$this->geo['c05'], 'puesto'], $this->comunaOf($lider->id), 'changing the puesto recalculates');
    }

    public function test_a_manual_comuna_is_never_touched_with_or_without_comuna(): void
    {
        $withComuna = $this->row(['barrio' => 'La Joya', 'comuna_id' => $this->geo['c13'], 'comuna_source' => 'manual'] + $this->votaBga('IE Campo Hermoso'));
        $withoutComuna = $this->row(['comuna_id' => null, 'comuna_source' => 'manual'] + $this->votaBga('Colegio San Pedro Claver'));

        Lider::find($withComuna)->update(['barrio' => 'Comuneros', 'puesto_votacion' => 'Colegio San Pedro Claver', 'direccion' => 'comuna 6']);
        Lider::find($withoutComuna)->update(['barrio' => 'La Joya']);
        app(ComunaAssigner::class)->refreshRow('lideres', $withComuna);
        $this->artisan('geo:asignar-comunas')->assertSuccessful();

        $this->assertSame([$this->geo['c13'], 'manual'], $this->comunaOf($withComuna));
        $this->assertSame([null, 'manual'], $this->comunaOf($withoutComuna));
        $this->assertSame(0, DB::table('comuna_cambios')->count());
    }

    public function test_the_polling_station_counts_only_when_the_ficha_votes_and_lives_in_the_municipio(): void
    {
        $florida = $this->lider(['puesto_votacion' => 'Colegio San Pedro Claver', 'municipio_votacion' => 'FLORIDABLANCA']);
        $unknown = $this->lider(['puesto_votacion' => 'Colegio San Pedro Claver', 'municipio_votacion' => null]);
        $livesFlorida = $this->lider($this->votaBga('Colegio San Pedro Claver'), 'florida');
        $byCode = $this->lider(['puesto_votacion' => 'San Pedro Claver', 'municipio_votacion' => '68001']);
        $lower = $this->lider(['puesto_votacion' => 'col. san pedro claver', 'municipio_votacion' => 'Bucaramanga']);

        $this->assertSame([null, null], $this->comunaOf($florida->id), 'votes in Floridablanca');
        $this->assertSame([null, null], $this->comunaOf($unknown->id), 'municipio de votación unknown');
        $this->assertSame([null, null], $this->comunaOf($livesFlorida->id), 'lives in Floridablanca, votes in Bucaramanga');
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($byCode->id), 'DANE code and alias');
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($lower->id));
    }

    public function test_ambiguous_empty_and_zero_polling_stations_assign_nothing(): void
    {
        $ambiguous = $this->lider($this->votaBga('Colegio Aurelio Martinez Mutis'));
        $zero = $this->lider($this->votaBga('0'));
        $spaces = $this->lider($this->votaBga('   '));
        $sameComuna = $this->lider($this->votaBga('Escuela Normal Superior'));

        $this->assertSame([null, null], $this->comunaOf($ambiguous->id), 'sede A is comuna 7 and sede B comuna 6');
        $this->assertSame([null, null], $this->comunaOf($zero->id));
        $this->assertSame([null, null], $this->comunaOf($spaces->id));
        $this->assertSame([$this->geo['c13'], 'puesto'], $this->comunaOf($sameComuna->id), 'two sedes of the same comuna are not ambiguous');
    }

    public function test_a_written_comuna_needs_one_existing_number_and_no_other_municipio(): void
    {
        $rows = [
            [['barrio' => 'COMUNA 25'], [null, null]],
            [['barrio' => 'Comuna 5 Floridablanca'], [null, null]],
            [['barrio' => 'Comuna No. 5'], ['c05', 'texto']],
            [['direccion' => 'Cra 3 # 4-5, comuna 05'], ['c05', 'texto']],
            [['barrio' => 'Comuna 3', 'direccion' => 'Comuna 5'], [null, null]],
            [['barrio' => 'Salón comunal'], [null, null]],
        ];
        foreach ($rows as [$values, [$comuna, $source]]) {
            $lider = $this->lider($values);
            $this->assertSame([$comuna ? $this->geo[$comuna] : null, $source], $this->comunaOf($lider->id), json_encode($values));
        }
    }

    public function test_the_official_name_with_its_institution_prefix_meets_the_alias_without_it(): void
    {
        $puesto = fn (string $name, string $comuna, array $alias = []) => DB::table('puestos_votacion')->insert([
            'id' => (string) Str::uuid(), 'municipio_id' => $this->geo['bga'], 'nombre' => $name,
            'nombre_normalizado' => PuestoNormalizer::normalize($name), 'comuna_unit_id' => $this->geo[$comuna], 'comuna_origen' => 'dataset',
            'fuente' => 'test', 'anio' => 2023, 'alias' => $alias ? json_encode($alias) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $puesto('IE INEM CUSTODIO GARCIA ROV SEDE A', 'c12', ['INEM CUSTODIO GARCIA ROVIRA SEDE A', 'INEM SEDE A']);
        $puesto('IE INEM SEDE D', 'c13');
        // The other sedes, as in the real list: by similarity alone the official name is ambiguous among them.
        $puesto('IE INEM CUSTODIO GARCIA ROV SEDE H', 'c07');
        $puesto('IE INEM CUSTODIO GARCIA ROVIRA SEDE G', 'c06');
        $puesto('IE LA LIBERTAD', 'c05');
        $puesto('COL LA LIBERTAD', 'c03');

        $official = $this->lider($this->votaBga('I.E. INEM CUSTODIO GARCIA ROVIRA SEDE A'));
        $colegio = $this->lider($this->votaBga('Colegio INEM Custodio Garcia Rov Sede A'));
        $bare = $this->lider($this->votaBga('INEM'));
        $twoComunas = $this->lider($this->votaBga('La Libertad'));

        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($official->id));
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($colegio->id));
        $this->assertSame([null, null], $this->comunaOf($bare->id), '«INEM» alone is still not assigned');
        $this->assertSame([null, null], $this->comunaOf($twoComunas->id), 'the same name in two comunas is ambiguous');
    }

    public function test_the_report_lists_only_texts_no_signal_settled_and_the_audit_keeps_every_counter(): void
    {
        $this->row(['barrio' => 'Comuna 6']);
        $this->row(['barrio' => 'Barrio inventado'] + $this->votaBga('Colegio San Pedro Claver'));
        $this->row(['barrio' => 'Barrio perdido']);

        $this->artisan('geo:asignar-comunas', ['--tabla' => ['lideres'], '--dry-run' => true])
            ->expectsOutputToContain('  - PERDIDO: 1')
            ->doesntExpectOutputToContain('  - COMUNA 6: 1')
            ->doesntExpectOutputToContain('  - INVENTADO: 1')
            ->assertSuccessful();

        $this->artisan('geo:asignar-comunas', ['--tabla' => ['lideres']])->assertSuccessful();
        $resumen = json_decode(DB::table('audit_logs')->where('action', 'geo.asignar_comunas')->value('metadata'), true)['resumen']['lideres'];
        $this->assertSame(['texto' => 1, 'por_direccion' => 0, 'puesto' => 1, 'sin_senal' => 1],
            array_intersect_key($resumen, array_flip(['texto', 'por_direccion', 'puesto', 'sin_senal'])));
        $this->assertArrayNotHasKey('direccion', $resumen);
    }

    public function test_the_mapas_query_builder_writes_use_the_cascade(): void
    {
        $id = $this->row($this->votaBga('Colegio San Pedro Claver'));

        $this->assertSame(['comuna_id' => $this->geo['c12'], 'comuna_source' => 'puesto'], app(ComunaAssigner::class)->refreshRow('lideres', $id));
        $this->assertNull(app(ComunaAssigner::class)->refreshRow('lideres', $id), 'nothing left to change');
    }

    public function test_choosing_by_hand_over_a_polling_station_and_back_to_automatic(): void
    {
        $id = $this->row($this->votaBga('Colegio San Pedro Claver'));
        $user = $this->writer();

        foreach ([1, 2] as $click) {
            $this->actingAs($user)->patchJson("/votantes/{$id}/comuna", ['comuna_id' => $this->geo['c13']])
                ->assertOk()->assertJson(['comuna_id' => $this->geo['c13'], 'comuna_source' => 'manual']);
        }
        $this->assertSame(1, DB::table('lideres')->where('id', $id)->count());
        $this->assertSame([$this->geo['c13'], 'manual'], $this->comunaOf($id));

        $this->actingAs($user)->patchJson("/votantes/{$id}/comuna", ['automatica' => true])
            ->assertOk()->assertJson(['comuna_id' => $this->geo['c12'], 'comuna_source' => 'puesto']);
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($id));
    }

    // ---- geo:asignar-comunas ----

    private function mixedRows(): array
    {
        return [
            'barrio' => $this->row(['barrio' => 'La Joya'] + $this->votaBga('Colegio San Pedro Claver')),
            'texto' => $this->row(['barrio' => 'comuna 6']),
            'puesto' => $this->row($this->votaBga('Colegio San Pedro Claver')),
            'ambiguo' => $this->row($this->votaBga('Colegio Aurelio Martinez Mutis')),
            'florida' => $this->row(['puesto_votacion' => 'Colegio San Pedro Claver', 'municipio_votacion' => 'FLORIDABLANCA']),
            'manual' => $this->row(['comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual', 'barrio' => 'La Joya']),
            'nada' => $this->row([]),
        ];
    }

    public function test_dry_run_reports_by_origin_and_writes_nothing(): void
    {
        $this->mixedRows();
        $before = $this->snapshot();

        $this->artisan('geo:asignar-comunas', ['--dry-run' => true])
            ->expectsOutputToContain('Por puesto')
            ->expectsOutputToContain('Puesto de votación sin usar (fichas): ambiguo 1, sin coincidencia 0, vota en otro municipio o sin municipio de votación 1.')
            ->expectsOutputToContain('  - sin comuna → puesto: 1')
            ->expectsOutputToContain('Puesto ambiguo (texto normalizado: registros):')
            ->expectsOutputToContain('  - COL AURELIO MARTINEZ MUTIS: 1')
            ->expectsOutputToContain('DRY-RUN: no se guardó nada.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('comuna_cambios')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_real_run_logs_only_the_changes_in_one_batch_and_a_second_run_creates_none(): void
    {
        $ids = $this->mixedRows();

        $this->artisan('geo:asignar-comunas')->expectsOutputToContain('Comunas asignadas. Lote: ')->assertSuccessful();

        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($ids['barrio']));
        $this->assertSame([$this->geo['c06'], 'texto'], $this->comunaOf($ids['texto']));
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($ids['puesto']));
        foreach (['ambiguo', 'florida', 'nada'] as $key) {
            $this->assertSame([null, null], $this->comunaOf($ids[$key]), $key);
        }
        $this->assertSame([$this->geo['r1'], 'manual'], $this->comunaOf($ids['manual']));

        $cambios = DB::table('comuna_cambios')->get();
        $this->assertCount(3, $cambios, 'only the three that changed');
        $this->assertCount(1, $cambios->pluck('lote')->unique());
        $this->assertEqualsCanonicalizing([$ids['barrio'], $ids['texto'], $ids['puesto']], $cambios->pluck('registro_id')->all());
        $puesto = $cambios->firstWhere('registro_id', $ids['puesto']);
        $this->assertSame([null, null, $this->geo['c12'], 'puesto'], [$puesto->antes_comuna_id, $puesto->antes_source, $puesto->despues_comuna_id, $puesto->despues_source]);
        $audit = json_decode(DB::table('audit_logs')->where('action', 'geo.asignar_comunas')->value('metadata'), true);
        $this->assertSame($cambios[0]->lote, $audit['lote']);

        $after = $this->snapshot();
        $this->artisan('geo:asignar-comunas')->expectsOutputToContain('Sin cambios: no se creó lote.')->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
        $this->assertSame(3, DB::table('comuna_cambios')->count());
    }

    public function test_a_ficha_changed_between_reading_and_writing_is_not_overwritten_nor_logged(): void
    {
        $raced = $this->row($this->votaBga('Colegio San Pedro Claver'));
        $other = $this->row(['barrio' => 'La Joya']);

        // Right after the command reads the page, someone chooses the comuna of $raced by hand.
        $done = false;
        DB::listen(function ($query) use (&$done, $raced) {
            if (! $done && str_contains($query->sql, 'from "lideres"') && str_contains($query->sql, 'limit 500')) {
                $done = true;
                DB::table('lideres')->where('id', $raced)->update(['comuna_id' => $this->geo['c13'], 'comuna_source' => 'manual']);
            }
        });

        $this->artisan('geo:asignar-comunas', ['--tabla' => ['lideres']])
            ->expectsOutputToContain('1 registros cambiaron mientras se leían: no se tocaron')
            ->assertSuccessful();

        $this->assertTrue($done, 'the change happened between read and write');
        $this->assertSame([$this->geo['c13'], 'manual'], $this->comunaOf($raced));
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($other));
        $this->assertSame([$other], DB::table('comuna_cambios')->pluck('registro_id')->all(), 'only the row really changed is logged');
    }

    public function test_the_nightly_run_keeps_the_comunas_of_polling_station_and_text(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'geo:asignar-comunas'));
        $this->assertNotNull($event, 'the nightly run is scheduled');
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        $puesto = $this->lider(['barrio' => 'Barrio desconocido'] + $this->votaBga('Colegio San Pedro Claver'));
        $texto = $this->lider(['direccion' => 'Comuna 6']);
        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($puesto->id));

        $this->artisan('geo:asignar-comunas --municipio=68001')->expectsOutputToContain('Sin cambios: no se creó lote.')->assertSuccessful();

        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($puesto->id));
        $this->assertSame([$this->geo['c06'], 'texto'], $this->comunaOf($texto->id));
    }

    // ---- geo:revertir-comunas ----

    public function test_revert_restores_a_batch_and_skips_records_changed_afterwards(): void
    {
        $fromPuesto = $this->row(['barrio' => 'La Joya', 'comuna_id' => $this->geo['c12'], 'comuna_source' => 'puesto'] + $this->votaBga('Colegio San Pedro Claver'));
        $plain = $this->row($this->votaBga('Colegio San Pedro Claver'));
        $byHand = $this->row(['barrio' => 'comuna 6']);
        $untouched = $this->row(['comuna_id' => $this->geo['r1'], 'comuna_source' => 'manual']);

        $this->artisan('geo:asignar-comunas')->assertSuccessful();
        $lote = DB::table('comuna_cambios')->value('lote');
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($fromPuesto));
        // Someone fixes it by hand after the run: the revert leaves it alone.
        DB::table('lideres')->where('id', $byHand)->update(['comuna_id' => $this->geo['c13'], 'comuna_source' => 'manual']);

        $this->artisan('geo:revertir-comunas', ['--lote' => $lote, '--dry-run' => true])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($fromPuesto), 'dry-run writes nothing');

        $this->artisan('geo:revertir-comunas', ['--lote' => $lote])
            ->expectsTable(['Restaurados', 'Omitidos (cambiaron después)', 'Ya estaban como antes', 'Ya no existen'], [[2, 1, 0, 0]])
            ->assertSuccessful();

        $this->assertSame([$this->geo['c12'], 'puesto'], $this->comunaOf($fromPuesto), 'back to what it had');
        $this->assertSame([null, null], $this->comunaOf($plain));
        $this->assertSame([$this->geo['c13'], 'manual'], $this->comunaOf($byHand));
        $this->assertSame([$this->geo['r1'], 'manual'], $this->comunaOf($untouched));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'geo.revertir_comunas')->count());

        $this->artisan('geo:revertir-comunas', ['--lote' => $lote])
            ->expectsTable(['Restaurados', 'Omitidos (cambiaron después)', 'Ya estaban como antes', 'Ya no existen'], [[0, 1, 2, 0]])
            ->assertSuccessful();
    }

    public function test_revert_of_an_unknown_batch_fails(): void
    {
        $this->artisan('geo:revertir-comunas', ['--lote' => (string) Str::uuid()])->expectsOutputToContain('No existe el lote')->assertFailed();
        $this->artisan('geo:revertir-comunas', ['--lote' => 'x'])->assertFailed();
        $this->artisan('geo:revertir-comunas')->assertFailed();
    }

    // ---- migration ----

    public function test_the_rollback_aborts_while_records_have_the_new_origins(): void
    {
        $migration = require database_path('migrations/2026_11_24_000001_add_comuna_cascade.php');
        $id = $this->row(['comuna_id' => $this->geo['c12'], 'comuna_source' => 'puesto']);

        try {
            $migration->down();
            $this->fail('The rollback should have aborted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('lideres tiene 1 registros con comuna por texto, dirección o puesto', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('comuna_cambios') && Schema::hasTable('puestos_votacion'), 'nothing was dropped');

        DB::table('lideres')->where('id', $id)->update(['comuna_id' => $this->geo['c12'], 'comuna_source' => 'auto']);
        $migration->down();
        $this->assertFalse(Schema::hasTable('comuna_cambios'));
        $this->assertFalse(Schema::hasTable('puestos_votacion'));
        $this->assertSame([$this->geo['c12'], 'auto'], $this->comunaOf($id), 'auto and manual comunas stay');
    }

    // ---- data:electo-barrios ----

    private function catalogo(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'barrios').'.csv';
        $lines = ['codigo;nombre;fkmunicipio'];
        foreach ($rows as $r) {
            $lines[] = implode(';', $r);
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function electo(string $code, ?string $barrio = null, string $municipio = 'bga', array $flags = []): string
    {
        return $this->row(['barrio' => $barrio, 'migracion_flags' => json_encode($flags + [
            'electo_codigos' => ['barrio' => $code, 'genero' => '1'], 'por_completar' => ['barrio', 'convenio'],
        ])], $municipio);
    }

    public function test_electo_barrios_fills_only_empty_barrios_of_the_same_municipio_and_then_assigns_comunas(): void
    {
        $csv = $this->catalogo([['501', 'LA JOYA', '68001'], ['502', 'COMUNEROS', '68001'], ['1404', 'SIN DEFINIR', '68001'],
            ['600', 'CAÑAVERAL', '68276'], ['503', 'NO REGISTRA', '68001']]);
        $fill = $this->electo('501');
        $hasBarrio = $this->electo('502', 'Mi barrio');
        $sinBarrio = $this->electo('1404');
        $otro = $this->electo('600');
        $missing = $this->electo('999');
        $placeholder = $this->electo('503');
        $before = $this->snapshot();

        $this->artisan('data:electo-barrios', ['csv' => $csv, '--dry-run' => true])
            ->expectsTable(['Fichas con código', 'Llenadas', 'Ya tenían barrio', 'Código sin catálogo', 'Código de otro municipio',
                '«Sin barrio» (excluido)', 'Nombre vacío', 'Con comuna por ese barrio'], [[6, 1, 1, 1, 1, 1, 1, 1]])
            ->assertSuccessful();
        $this->assertSame($before, $this->snapshot(), 'dry-run writes nothing');

        $this->artisan('data:electo-barrios', ['csv' => $csv])->expectsOutputToContain('Comunas asignadas. Lote: ')->assertSuccessful();

        $row = DB::table('lideres')->where('id', $fill)->first();
        $this->assertSame('LA JOYA', $row->barrio);
        $flags = json_decode($row->migracion_flags, true);
        $this->assertSame(['codigo' => '501', 'valor' => 'LA JOYA'], $flags['barrio_desde_electo']);
        $this->assertSame(['convenio'], $flags['por_completar']);
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($fill));
        $this->assertSame('Mi barrio', DB::table('lideres')->where('id', $hasBarrio)->value('barrio'));
        foreach ([$sinBarrio, $otro, $missing, $placeholder] as $id) {
            $this->assertNull(DB::table('lideres')->where('id', $id)->value('barrio'));
        }

        // Revert: only where the barrio is still the one written; the comuna follows.
        $edited = $this->electo('502');
        $this->artisan('data:electo-barrios', ['csv' => $csv])->assertSuccessful();
        DB::table('lideres')->where('id', $edited)->update(['barrio' => 'COMUNEROS ALTOS']);

        $this->artisan('data:electo-barrios', ['--revertir' => true])
            ->expectsTable(['Revertidas', 'Omitidas (el barrio cambió después)'], [[1, 1]])
            ->assertSuccessful();
        $row = DB::table('lideres')->where('id', $fill)->first();
        $this->assertNull($row->barrio);
        $flags = json_decode($row->migracion_flags, true);
        $this->assertArrayNotHasKey('barrio_desde_electo', $flags);
        $this->assertEqualsCanonicalizing(['convenio', 'barrio'], $flags['por_completar']);
        $this->assertSame([null, null], $this->comunaOf($fill));
        $this->assertSame('COMUNEROS ALTOS', DB::table('lideres')->where('id', $edited)->value('barrio'));
    }

    public function test_the_no_barrio_code_is_used_only_when_the_catalog_names_a_real_barrio(): void
    {
        $id = $this->electo('1404');

        $this->artisan('data:electo-barrios', ['csv' => $this->catalogo([['1404', 'La Joya', '68001']])])->assertSuccessful();

        $this->assertSame('La Joya', DB::table('lideres')->where('id', $id)->value('barrio'));
        $this->assertSame([$this->geo['c05'], 'auto'], $this->comunaOf($id));
    }

    public function test_electo_barrios_rejects_a_bad_catalog(): void
    {
        $this->artisan('data:electo-barrios')->assertFailed();
        $this->artisan('data:electo-barrios', ['csv' => $this->catalogo([['501', 'A', '68001'], ['501', 'B', '68001']])])
            ->expectsOutputToContain('el código 501 está repetido')->assertFailed();
        $path = tempnam(sys_get_temp_dir(), 'barrios').'.csv';
        file_put_contents($path, "codigo,nombre\n1,LA JOYA\n");
        $this->artisan('data:electo-barrios', ['csv' => $path])->expectsOutputToContain('le falta la columna municipio')->assertFailed();
    }

    // ---- matching helpers ----

    public function test_polling_station_names_are_normalized_and_compared_like_pg_trgm(): void
    {
        $this->assertSame('IE LAS AMERICAS', PuestoNormalizer::normalize('I.E. Las Américas'));
        $this->assertSame('IE LA LIBERTAD', PuestoNormalizer::normalize('Institución Educativa La Libertad'));
        $this->assertSame('COL SANTANDER SEDE A', PuestoNormalizer::normalize('Puesto Colegio Santander Sede A'));
        $this->assertSame('', PuestoNormalizer::normalize('0'));
        $this->assertSame(1.0, Trigram::similarity('COL SANTANDER', 'col santander'));
        $this->assertSame(0.0, Trigram::similarity('', 'COL'));
        // pg_trgm: similarity('word', 'two words') = 4 / 11.
        $this->assertEqualsWithDelta(4 / 11, Trigram::similarity('word', 'two words'), 1e-9);
    }
}
