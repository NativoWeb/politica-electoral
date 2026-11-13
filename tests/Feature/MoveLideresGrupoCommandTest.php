<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// data:move-lideres-grupo moves fichas from a group to another one of the same tree with their own nexos, leaves what
// belongs to the origin group, aborts on a cédula already in the destination and audits only ids and counts.
class MoveLideresGrupoCommandTest extends TestCase
{
    private string $alejandro;

    private string $otroArbol;

    private string $cimitarra;

    private string $landazuri;

    private string $fuera;

    private User $userAlejandro;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->uuid('parent_id')->nullable();
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
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->rememberToken();
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
            $t->uuid('campaign_id');
            $t->boolean('is_active')->default(true);
            $t->string('cedula')->nullable();
            $t->string('referente_documento')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->uuid('comuna_id')->nullable();
            $t->string('comuna_source')->nullable();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX lideres_campaign_id_cedula_unique ON lideres (campaign_id, cedula) WHERE cedula IS NOT NULL AND cedula <> ''");
        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->string('nombre');
            $t->string('cedula')->nullable();
            $t->uuid('campaign_id');
            $t->timestamps();
        });
        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
        });
        Schema::create('event_attendees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('lider_id')->nullable();
        });
        Schema::create('data_consents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('lider_id')->nullable();
        });

        $this->alejandro = (string) Str::uuid();
        $this->otroArbol = (string) Str::uuid();
        DB::table('campaigns')->insert([
            ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => null],
            ['id' => $this->otroArbol, 'name' => 'Otro partido', 'slug' => 'otro-partido', 'parent_id' => null],
        ]);
        DB::table('campaigns')->insert(['id' => $this->alejandro, 'name' => 'Alejandro Cardenas', 'slug' => 'alejandro-cardenas', 'parent_id' => Campaign::DEFAULT_ID]);

        [$this->cimitarra, $this->landazuri, $this->fuera] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->userAlejandro = $this->user('ale', $this->alejandro, [$this->cimitarra, $this->landazuri]);
    }

    private function user(string $key, string $campaignId, ?array $territory = null): User
    {
        $user = User::create(['name' => "Usuario {$key}", 'email' => "{$key}@test.local", 'password' => 'secret123']);
        $user->forceFill(['campaign_id' => $campaignId, 'territory_scope' => $territory])->save();

        return $user;
    }

    private function lider(array $attributes = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id,
            'nombre' => 'Ficha '.Str::random(6),
            'campaign_id' => Campaign::DEFAULT_ID,
            'is_active' => true,
            'geographic_unit_id' => $this->cimitarra,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);

        return $id;
    }

    private function campaignOf(string $liderId): string
    {
        return DB::table('lideres')->where('id', $liderId)->value('campaign_id');
    }

    private function move(array $ids, array $options = [])
    {
        return $this->artisan('data:move-lideres-grupo', [
            'grupo_destino' => 'alejandro-cardenas',
            '--ids' => implode(',', $ids),
            '--reason' => 'Creadas por Alejandro antes de su subgrupo',
            ...$options,
        ]);
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $id = $this->lider(['cedula' => '1001']);
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'person_id' => $id, 'nombre' => 'Hermano', 'campaign_id' => Campaign::DEFAULT_ID]);

        $this->move([$id], ['--dry-run' => true, '--reason' => null])
            ->expectsOutputToContain('Destino: Alejandro Cardenas. Origen: Centro Democrático.')
            ->expectsOutputToContain('--dry-run: no se cambió nada.')
            ->assertSuccessful();

        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($id));
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('nexos_familiares')->value('campaign_id'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_it_moves_the_ficha_with_its_own_nexos_and_leaves_what_belongs_to_the_origin(): void
    {
        $id = $this->lider(['cedula' => '1001']);
        $queda = $this->lider(['cedula' => '2002']);
        $nexo = (string) Str::uuid();
        DB::table('nexos_familiares')->insert([
            ['id' => $nexo, 'person_id' => $id, 'nombre' => 'Hermano', 'cedula' => null, 'campaign_id' => Campaign::DEFAULT_ID],
            ['id' => (string) Str::uuid(), 'person_id' => $queda, 'nombre' => 'Prima', 'cedula' => null, 'campaign_id' => Campaign::DEFAULT_ID],
        ]);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $id]);
        DB::table('event_attendees')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'lider_id' => $id]);
        DB::table('data_consents')->insert(['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'lider_id' => $id]);
        Cache::put('compromisos-alertas:'.$this->userAlejandro->id, ['stale' => true], 300);

        $this->move([$id])->expectsOutputToContain('Listo: 1 ficha(s) movidas.')->assertSuccessful();

        $this->assertSame($this->alejandro, $this->campaignOf($id));
        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($queda));
        $this->assertSame($this->alejandro, DB::table('nexos_familiares')->where('id', $nexo)->value('campaign_id'));
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('nexos_familiares')->where('person_id', $queda)->value('campaign_id'));
        $this->assertSame($id, DB::table('lider_contactos')->value('lider_id'));
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('event_attendees')->value('campaign_id'), 'Attendance of an origin event stays');
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('data_consents')->value('campaign_id'), 'Consents are append-only');
        $this->assertFalse(Cache::has('compromisos-alertas:'.$this->userAlejandro->id));
    }

    public function test_the_audit_keeps_ids_and_counts_without_personal_data(): void
    {
        $id = $this->lider(['nombre' => 'Pedro Pérez Gómez', 'cedula' => '1098765432']);
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'person_id' => $id, 'nombre' => 'Hermano', 'campaign_id' => Campaign::DEFAULT_ID]);

        $this->move([$id])->assertSuccessful();

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('LIDERES_GRUPO_MOVIDOS', $log->action);
        $this->assertSame($this->alejandro, $log->entity_id);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame([$id], $metadata['lider_ids']);
        $this->assertSame(1, $metadata['movidas']);
        $this->assertSame(1, $metadata['nexos_movidos']);
        $this->assertSame('Creadas por Alejandro antes de su subgrupo', $metadata['reason']);
        $this->assertSame([Campaign::DEFAULT_ID], json_decode($log->before_state, true)['campaign_ids']);
        $raw = $log->metadata.$log->before_state.$log->after_state;
        $this->assertStringNotContainsString('Pedro', $raw);
        $this->assertStringNotContainsString('1098765432', $raw);
    }

    public function test_it_does_not_move_outside_the_tree_inactive_or_outside_the_destination_territory(): void
    {
        $otro = $this->lider(['campaign_id' => $this->otroArbol]);
        $inactiva = $this->lider(['is_active' => false]);
        $fuera = $this->lider(['geographic_unit_id' => $this->fuera]);
        $ok = $this->lider();

        $this->move([$otro, $inactiva, $fuera, $ok])
            ->expectsOutputToContain("Omitida {$otro}: otro árbol de grupos.")
            ->expectsOutputToContain("Omitida {$inactiva}: inactiva.")
            ->expectsOutputToContain("Omitida {$fuera}: fuera del territorio del grupo destino.")
            ->assertSuccessful();

        $this->assertSame($this->otroArbol, $this->campaignOf($otro));
        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($inactiva));
        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($fuera));
        $this->assertSame($this->alejandro, $this->campaignOf($ok));
    }

    public function test_a_cedula_already_in_the_destination_aborts_everything(): void
    {
        $this->lider(['campaign_id' => $this->alejandro, 'cedula' => '1001']);
        $choca = $this->lider(['cedula' => '1001']);
        $limpia = $this->lider(['cedula' => '3003']);

        $this->move([$choca, $limpia])
            ->expectsOutputToContain('Abortado: 1 cédula(s) ya existen en el grupo destino')
            ->assertFailed();

        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($choca));
        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($limpia));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_by_creator_it_crosses_the_audit_log_by_name_and_time_and_reports_what_does_not_cross(): void
    {
        $at = now()->subDays(3)->startOfSecond();
        $creada = $this->lider(['nombre' => 'Ana Ruiz', 'created_at' => $at->copy()->subSecond()]);
        $this->lider(['nombre' => 'Ana Ruiz', 'created_at' => $at->copy()->subMinutes(10)]);
        $log = fn (string $nombre, $when) => DB::table('audit_logs')->insert([
            'id' => $auditId = (string) Str::uuid(), 'user_id' => $this->userAlejandro->id, 'action' => 'POST mapa-politico.lider.store',
            'entity_type' => 'lider', 'metadata' => json_encode(['nombre' => $nombre]), 'created_at' => $when,
        ]) ? $auditId : null;
        $log('Ana Ruiz', $at);
        $perdida = $log('Nadie Existe', $at);

        $this->artisan('data:move-lideres-grupo', [
            'grupo_destino' => $this->alejandro, '--creados-por' => $this->userAlejandro->id, '--reason' => 'test',
        ])
            ->expectsOutputToContain("Sin cruzar (audit_logs {$perdida}): ninguna ficha con ese nombre y hora.")
            ->assertSuccessful();

        $this->assertSame($this->alejandro, $this->campaignOf($creada));
        $this->assertSame(1, DB::table('lideres')->where('campaign_id', $this->alejandro)->count(), 'The homonym created earlier stays');
    }

    public function test_it_does_not_move_from_a_group_that_is_not_above_the_destination(): void
    {
        $hermano = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $hermano, 'name' => 'Hermano', 'slug' => 'hermano', 'parent_id' => Campaign::DEFAULT_ID]);
        $id = $this->lider(['campaign_id' => $hermano]);

        $this->move([$id])
            ->expectsOutputToContain("Omitida {$id}: el origen no es un grupo superior del destino.")
            ->expectsOutputToContain('No hay fichas para mover.')
            ->assertSuccessful();

        $this->assertSame($hermano, $this->campaignOf($id));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_only_the_nexos_of_the_fichas_origin_group_move(): void
    {
        $id = $this->lider();
        $propio = (string) Str::uuid();
        $ajeno = (string) Str::uuid();
        DB::table('nexos_familiares')->insert([
            ['id' => $propio, 'person_id' => $id, 'nombre' => 'Hermano', 'cedula' => null, 'campaign_id' => Campaign::DEFAULT_ID],
            ['id' => $ajeno, 'person_id' => $id, 'nombre' => 'Primo', 'cedula' => null, 'campaign_id' => $this->otroArbol],
        ]);

        $this->move([$id])->assertSuccessful();

        $this->assertSame($this->alejandro, DB::table('nexos_familiares')->where('id', $propio)->value('campaign_id'));
        $this->assertSame($this->otroArbol, DB::table('nexos_familiares')->where('id', $ajeno)->value('campaign_id'));
        $this->assertSame(1, json_decode(DB::table('audit_logs')->value('metadata'), true)['nexos_movidos']);
    }

    public function test_by_creator_the_offline_client_id_wins_over_name_and_time_and_a_foreign_user_is_warned(): void
    {
        $userCd = $this->user('cd', Campaign::DEFAULT_ID);
        $id = $this->lider(['nombre' => 'Nombre Cambiado', 'created_at' => now()->subDays(2)]);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userCd->id, 'action' => 'POST mapa-politico.lider.store',
            'entity_type' => 'lider', 'metadata' => json_encode(['nombre' => 'Otro Nombre', 'client_id' => $id]), 'created_at' => now(),
        ]);

        $this->artisan('data:move-lideres-grupo', [
            'grupo_destino' => 'alejandro-cardenas', '--creados-por' => $userCd->id, '--reason' => 'test',
        ])
            ->expectsOutputToContain('El usuario de --creados-por no pertenece al grupo destino')
            ->assertSuccessful();

        $this->assertSame($this->alejandro, $this->campaignOf($id));
    }

    public function test_it_warns_about_referente_links_that_break(): void
    {
        $referente = $this->lider(['cedula' => '5005']);
        $id = $this->lider(['cedula' => '6006', 'referente_documento' => '5005']);
        $this->lider(['cedula' => '7007', 'referente_documento' => '6006']);

        $this->move([$id], ['--dry-run' => true])
            ->expectsOutputToContain('Referente que se queda en el origen')
            ->assertSuccessful();

        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($referente));
    }

    public function test_it_requires_a_reason_and_one_selector(): void
    {
        $id = $this->lider();

        $this->move([$id], ['--reason' => ''])->expectsOutputToContain('--reason es obligatorio')->assertFailed();
        $this->artisan('data:move-lideres-grupo', ['grupo_destino' => 'alejandro-cardenas', '--dry-run' => true])
            ->expectsOutputToContain('Indique --ids o --creados-por')->assertFailed();
        $this->artisan('data:move-lideres-grupo', ['grupo_destino' => 'no-existe', '--ids' => $id, '--dry-run' => true])
            ->expectsOutputToContain('Grupo político destino no encontrado')->assertFailed();

        $this->assertSame(Campaign::DEFAULT_ID, $this->campaignOf($id));
    }
}
