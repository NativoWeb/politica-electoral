<?php

namespace Tests\Feature;

use App\Actions\Eventos\ApproveAttendees;
use App\Exports\GenericExport;
use App\Http\Requests\Eventos\AttendeeInput;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

// Opt-in: phase 4 of Eventos especiales on the real PostgreSQL schema and data (docs/eventos-api.md §12).
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral DB_URL= php artisan test --filter=Postgres
// Every change is rolled back by DatabaseTransactions.
class EventIntegrationPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private string $floridablanca;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $this->floridablanca = (string) DB::table('geographic_units')->where('type', 'municipality')->where('canonical_name', 'Floridablanca')->value('id');
        if ($this->floridablanca === '') {
            $this->markTestSkipped('The local database has no Floridablanca.');
        }
        $this->user = User::create([
            'name' => 'Integración PG', 'email' => 'integracion-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'permissions' => ['view.mapa', 'view.votantes', 'view.eventos'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
    }

    private function lider(string $nombre, string $cedula, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->floridablanca,
            'nombre' => $nombre, 'municipio' => 'Floridablanca', 'cedula' => $cedula, 'is_active' => true,
            'tipo' => ApproveAttendees::TIPO, 'barrio' => 'Cañaveral', 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    private function event(string $nombre, string $barrio): CampaignEvent
    {
        $event = new CampaignEvent([
            'event_type_id' => DB::table('event_types')->whereNull('campaign_id')->where('nombre', 'Gira')->value('id'),
            'nombre' => $nombre, 'starts_at' => now()->subHour(), 'sitio_encuentro' => 'Parque', 'municipio_id' => $this->floridablanca,
        ]);
        $event->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'status' => 'abierto', 'public_token' => Str::random(43)])->save();
        DB::table('event_barrios')->insert([
            'id' => (string) Str::uuid(), 'event_id' => $event->id, 'nombre' => $barrio, 'nombre_normalizado' => AttendeeInput::barrioKey($barrio),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $event;
    }

    private function attend(CampaignEvent $event, ?string $liderId, string $cedula, string $barrio): void
    {
        DB::table('event_attendees')->insert([
            'id' => (string) Str::uuid(), 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'lider_id' => $liderId,
            'nombres' => 'Zzint', 'apellidos' => 'PG '.$cedula, 'cedula' => $cedula, 'celular' => '3001234567',
            'barrio' => $barrio, 'barrio_normalizado' => AttendeeInput::barrioKey($barrio), 'autoriza_datos' => true,
            'source' => 'qr', 'link_status' => $liderId ? 'aprobado' : 'pendiente', 'client_uuid' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mapa(string $query = ''): array
    {
        $props = $this->actingAs($this->user)->get("/mapa-politico?municipio={$this->floridablanca}{$query}")->assertOk()->viewData('page')['props'];
        ksort($props['sectionCounts']);

        return $props;
    }

    public function test_floridablanca_default_counts_do_not_change_with_attendee_fichas(): void
    {
        $before = $this->mapa();

        foreach (['Zzint Asistente Uno', 'Zzint Asistente Dos', 'Zzint Asistente Tres'] as $i => $nombre) {
            $this->lider($nombre, '7700000'.$i);
        }
        $after = $this->mapa();

        $this->assertSame($before['sectionCounts'], $after['sectionCounts']);
        $this->assertSame(array_column($before['data'], 'nombre'), array_column($after['data'], 'nombre'));

        // Every tipo checked: the same sections plus the attendees.
        $all = $this->mapa('&tipo=alcaldia,concejo,lideres,nuevos_lideres,senado,camara,asamblea,asistentes_evento');
        $this->assertSame(3, $all['sectionCounts']['Asistentes a eventos']);
        unset($all['sectionCounts']['Asistentes a eventos']);
        $this->assertSame($before['sectionCounts'], $all['sectionCounts']);

        Excel::fake();
        $this->actingAs($this->user)->get("/exportar/excel?source=mapa-politico&tipo=asistentes_evento&municipio={$this->floridablanca}")->assertOk();
        Excel::assertDownloaded('Exportacion.xlsx', function (GenericExport $export) {
            $rows = $export->array();
            $this->assertCount(3, $rows);
            $this->assertSame(['Asistentes a eventos'], array_values(array_unique(array_column($rows, 2))));

            return true;
        });
    }

    public function test_votantes_filter_count_and_territory_view_on_postgresql(): void
    {
        $uno = $this->lider('Zzint Votante Uno', '7710000001', ['tipo' => 'directorio', 'barrio' => 'CAÑAVERAL']);
        $dos = $this->lider('Zzint Votante Dos', '7710000002', ['tipo' => 'directorio', 'barrio' => 'Lagos']);
        $gira = $this->event('Zzint Gira Cañaveral', 'Cañaveral');
        $novena = $this->event('Zzint Novena Lagos', 'Lagos');
        $this->attend($gira, $uno, '7710000001', 'cañaveral');
        $this->attend($novena, $uno, '7710000001', 'Lagos');
        $this->attend($novena, $dos, '7710000002', 'Lagos');
        $this->attend($gira, null, '7710000003', '  CANAVERAL ');

        $props = $this->actingAs($this->user)->get("/votantes?evento={$gira->id}&search=Zzint")->assertOk()->viewData('page')['props'];
        $this->assertSame([$uno], array_column($props['votantes']['data'], 'id'));
        $this->assertSame(2, (int) $props['votantes']['data'][0]['eventos_count']);

        $props = $this->actingAs($this->user)->get('/votantes?asistentes_eventos=1&search=Zzint')->assertOk()->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([$uno, $dos], array_column($props['votantes']['data'], 'id'));

        $persona = $this->actingAs($this->user)->getJson("/eventos/persona/{$uno}")->assertOk()->json('data');
        $this->assertSame(2, $persona['total']);

        $territorio = $this->actingAs($this->user)->get("/eventos/territorio?municipio={$this->floridablanca}&barrio=canaveral")
            ->assertOk()->viewData('page')['props'];
        $r = $territorio['resultado'];
        $this->assertSame(['Zzint Gira Cañaveral'], array_column($r['eventos'], 'nombre'));
        $this->assertSame(2, $r['eventos'][0]['asistentes_barrio']);
        $this->assertEqualsCanonicalizing(['7710000001', '7710000003'], array_column($r['asistentes']['data'], 'cedula'));
        $this->assertContains('Zzint Votante Uno', array_column($r['lideres']['data'], 'nombre'));
        $this->assertNotContains('Zzint Votante Dos', array_column($r['lideres']['data'], 'nombre'));
        $this->assertSame($r['kpis']['asistentes'], 2);
        $this->assertCount(1, collect($territorio['barrios'])->where('key', 'canaveral'));
    }

    public function test_without_view_eventos_offline_and_saving_treat_attendees_as_lideres(): void
    {
        $asis = $this->lider('Zzint Asistente Offline', '7720000001');
        $mapaOnly = User::create([
            'name' => 'Mapa sin eventos PG', 'email' => 'mapa-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => ['view.mapa', 'write.data'],
        ]);
        $mapaOnly->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $names = fn (User $u) => array_column($this->actingAs($u)->getJson("/api/offline/download/{$this->floridablanca}")->assertOk()->json('lideres'), 'nombre');
        $this->assertContains('Zzint Asistente Offline', $names($mapaOnly), 'An ordinary lider for this user');
        $this->assertNotContains('Zzint Asistente Offline', $names($this->user), 'Off by default for who sees events');

        // Saving the ficha as it was shown ("Líderes") keeps it an attendee for the users who can see events.
        $this->actingAs($mapaOnly)->putJson("/mapa-politico/persona/{$asis}", ['tipos' => ['Líderes'], 'telefono' => '3009998877'])->assertOk();
        $this->assertSame([ApproveAttendees::TIPO, '3009998877'], array_values((array) DB::table('lideres')->where('id', $asis)->first(['tipo', 'telefono'])));

        $this->actingAs($mapaOnly)->putJson("/mapa-politico/persona/{$asis}", ['tipos' => ['Nuevos Líderes']])->assertOk();
        $this->assertSame('nuevo_lider', DB::table('lideres')->where('id', $asis)->value('tipo'));
    }

    public function test_without_view_eventos_offline_and_search_never_send_the_attendee_tipo(): void
    {
        $this->lider('Zzint Asistente Busqueda', '7730000001');
        $plain = User::create([
            'name' => 'Sin eventos PG', 'email' => 'plain-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => ['view.mapa', 'view.votantes'],
        ]);
        $plain->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $offline = $this->actingAs($plain)->getJson("/api/offline/download/{$this->floridablanca}")->assertOk()->json();
        $this->assertContains('Zzint Asistente Busqueda', array_column($offline['lideres'], 'nombre'));
        $this->assertStringNotContainsString('asistente_evento', json_encode($offline));

        $search = $this->actingAs($plain)->getJson('/mapa-politico/search-lideres?q=Zzint%20Asistente')->assertOk()->json();
        $this->assertContains('Zzint Asistente Busqueda', array_column($search['data'], 'nombre'));
        $this->assertStringNotContainsString('asistente_evento', json_encode($search));
    }

    public function test_qa_votantes_search_with_underscore_counters_and_mapa_tipo_without_permission(): void
    {
        $this->lider('Zzint Con_Guion', '7740000001', ['tipo' => 'directorio', 'partido' => 'Partido Zzint']);
        $this->lider('Zzint ConXGuion', '7740000002', ['tipo' => 'directorio', 'partido' => 'Otro Zzint']);

        // BUG-03: "_" is a character, not a wildcard.
        $props = $this->actingAs($this->user)->get('/votantes?search=n_G')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Zzint Con_Guion'], array_column($props['votantes']['data'], 'nombre'));
        // BUG-05: counters follow search and partido.
        $this->assertSame(1, $props['counters']['total']);
        $props = $this->actingAs($this->user)->get('/votantes?search=Zzint&partido=Otro%20Zzint')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Zzint ConXGuion'], array_column($props['votantes']['data'], 'nombre'));
        $this->assertSame(1, $props['counters']['total']);

        // BUG-06: without view.eventos, ?tipo=asistentes_evento is the default Mapa, not an empty list.
        $plain = User::create([
            'name' => 'Mapa QA PG', 'email' => 'mapa-qa-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => ['view.mapa'],
        ]);
        $plain->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();
        $get = fn (string $q) => $this->actingAs($plain)->get("/mapa-politico?municipio={$this->floridablanca}{$q}")->assertOk()->viewData('page')['props'];
        $default = $get('');
        $asked = $get('&tipo=asistentes_evento');
        $this->assertNotEmpty($asked['data']);
        $this->assertSame($default['sectionCounts'], $asked['sectionCounts']);
        $this->assertSame('todos', $asked['filters']['tipo']);
    }
}
