<?php

namespace Tests\Feature;

use App\Http\Controllers\EventoPublicoController;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: Eventos especiales on the real PostgreSQL schema (append-only data_consents trigger, partial unique
// index, check constraints, real lideres columns, LOWER/LIKE with accents).
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral DB_URL= php artisan test --filter=Postgres
// Every change is rolled back by DatabaseTransactions.
class EventPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $mun;

    private string $campX;

    private User $manager;

    private ConsentText $text;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $prov = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $prov, 'type' => 'province', 'canonical_name' => 'Provincia Eventos PG']);
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Municipio Eventos PG', 'parent_id' => $prov]);

        $this->campX = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $this->campX, 'name' => 'Subcampaña Eventos PG', 'slug' => 'eventos-pg-'.Str::random(6),
            'parent_id' => Campaign::DEFAULT_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The local database may already hold real texts: a newer active one wins for the test.
        $this->text = $this->text(Campaign::DEFAULT_ID);
        $this->manager = $this->user(Campaign::DEFAULT_ID, ['manage.eventos']);
    }

    private function text(string $campaignId): ConsentText
    {
        $body = 'Texto aprobado PG '.Str::random(8);
        $text = (new ConsentText)->forceFill([
            'campaign_id' => $campaignId, 'version' => 'pg-test', 'cuerpo' => $body, 'sha256' => hash('sha256', $body),
            'vigente_desde' => now()->addMinute(), 'is_active' => true, 'approved' => true,
        ]);
        $text->save();

        return $text;
    }

    private function user(string $campaignId, array $permissions): User
    {
        $user = User::create([
            'name' => 'Eventos PG', 'email' => 'eventos-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => $permissions,
        ]);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    private function event(string $campaignId, string $status = 'abierto'): CampaignEvent
    {
        $event = new CampaignEvent([
            'event_type_id' => DB::table('event_types')->whereNull('campaign_id')->where('nombre', 'Gira')->value('id'),
            'nombre' => 'Gira Ñandú PG', 'starts_at' => now()->subHour(), 'sitio_encuentro' => 'Parque',
            'municipio_id' => $this->mun,
        ]);
        $event->forceFill(['campaign_id' => $campaignId, 'status' => $status, 'public_token' => Str::random(43)])->save();

        return $event;
    }

    private function lider(string $campaignId, string $cedula): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => $campaignId, 'geographic_unit_id' => $this->mun, 'nombre' => 'Zzevento Existente',
            'municipio' => 'Municipio Eventos PG', 'cargo' => 'Líder', 'is_active' => true, 'cedula' => $cedula,
            'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ]);

        return $id;
    }

    private function publicSubmit(CampaignEvent $event, ConsentText $text, array $overrides = [])
    {
        return $this->postJson("/e/{$event->public_token}", array_merge([
            'client_uuid' => (string) Str::uuid(), 'consent_text_id' => $text->id, 'autoriza_datos' => true,
            'nombres' => 'Zzevento', 'apellidos' => 'Asistente', 'cedula' => '9'.random_int(100000000, 999999999),
            'celular' => '3001234567', 'barrio' => 'San Martín',
            'form_token' => Crypt::encryptString(json_encode(['e' => $event->id, 't' => now()->timestamp - 10])), 'website' => '',
        ], $overrides));
    }

    private function assertStatementFails(callable $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail('The statement should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('solo insercion', $e->getMessage());
        }
    }

    public function test_data_consents_reject_update_delete_and_truncate(): void
    {
        $event = $this->event(Campaign::DEFAULT_ID);
        $this->publicSubmit($event, $this->text)->assertOk();
        $id = DB::table('data_consents')->where('event_id', $event->id)->value('id');
        $this->assertNotNull($id);

        $this->assertStatementFails(fn () => DB::table('data_consents')->where('id', $id)->update(['cedula' => '1']));
        $this->assertStatementFails(fn () => DB::table('data_consents')->where('id', $id)->delete());
        $this->assertStatementFails(fn () => DB::statement('TRUNCATE data_consents CASCADE'));

        $this->assertSame(1, DB::table('data_consents')->where('id', $id)->count());
    }

    public function test_public_registration_links_dedupes_and_keeps_the_lider_untouched(): void
    {
        $event = $this->event(Campaign::DEFAULT_ID);
        $cedula = '8'.random_int(100000000, 999999999);
        $liderId = $this->lider(Campaign::DEFAULT_ID, $cedula);
        $before = (array) DB::table('lideres')->where('id', $liderId)->first();
        $dotted = number_format((int) $cedula, 0, ',', '.');

        $this->publicSubmit($event, $this->text, ['cedula' => $dotted])->assertOk()
            ->assertExactJson(['success' => true, 'message' => EventoPublicoController::THANKS]);
        $this->publicSubmit($event, $this->text, ['cedula' => $cedula])->assertOk()
            ->assertExactJson(['success' => true, 'message' => EventoPublicoController::THANKS]);

        $attendees = DB::table('event_attendees')->where('event_id', $event->id)->get();
        $this->assertCount(1, $attendees);
        $this->assertSame('vinculado', $attendees[0]->link_status);
        $this->assertSame($liderId, $attendees[0]->lider_id);
        $this->assertSame($before, (array) DB::table('lideres')->where('id', $liderId)->first());

        $this->assertNull(DB::table('audit_logs')->where('entity_id', $attendees[0]->id)->value('user_id'));
        $this->assertStringNotContainsString($cedula, (string) DB::table('audit_logs')->where('entity_id', $attendees[0]->id)->value('metadata'));

        // The partial unique index itself rejects a second row with the same cédula in the event.
        try {
            DB::transaction(fn () => DB::table('event_attendees')->insert([
                'id' => (string) Str::uuid(), 'event_id' => $event->id, 'campaign_id' => Campaign::DEFAULT_ID,
                'nombres' => 'X', 'apellidos' => 'Y', 'cedula' => $cedula, 'source' => 'qr', 'link_status' => 'pendiente',
                'client_uuid' => (string) Str::uuid(),
            ]));
            $this->fail('Duplicate cédula in the event should be rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('event_attendees_event_id_cedula_unique', $e->getMessage());
        }
    }

    public function test_subcampaign_event_operator_and_approval_on_the_real_schema(): void
    {
        $text = $this->text($this->campX);
        $event = $this->event($this->campX, 'borrador');
        $xManager = $this->user($this->campX, ['manage.eventos']);
        $cedula = '7'.random_int(100000000, 999999999);
        $this->lider(Campaign::DEFAULT_ID, $cedula);

        $payload = [
            'client_uuid' => (string) Str::uuid(), 'consent_text_id' => $text->id, 'autoriza_datos' => true,
            'nombres' => 'Zzevento', 'apellidos' => 'Operador', 'cedula' => $cedula, 'celular' => '+57 300 123 4567',
            'barrio' => 'San Martín', 'fijo' => '6076543210', 'fecha_nacimiento' => '1975-01-31',
        ];
        $id = $this->actingAs($xManager)->postJson("/eventos/{$event->id}/asistentes", $payload)->assertCreated()->json('data.id');
        $this->actingAs($xManager)->postJson("/eventos/{$event->id}/asistentes", $payload)->assertOk()->assertJsonPath('data.duplicate', true);

        $this->actingAs($this->manager)->postJson("/eventos/{$event->id}/asistentes/aprobar", ['ids' => [$id]])->assertForbidden();
        $this->actingAs($xManager)->postJson("/eventos/{$event->id}/asistentes/aprobar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data.aprobados', 1);
        $this->actingAs($xManager)->postJson("/eventos/{$event->id}/asistentes/aprobar", ['ids' => [$id]])
            ->assertOk()->assertJsonPath('data.omitidos', 1);

        $lider = DB::table('lideres')->where('id', DB::table('event_attendees')->where('id', $id)->value('lider_id'))->first();
        $this->assertSame($this->campX, $lider->campaign_id);
        $this->assertSame('asistente_evento', $lider->tipo);
        $this->assertNull($lider->cargo);
        $this->assertSame('Provincia Eventos PG', $lider->provincia);
        $this->assertTrue($lider->autoriza_datos);
        $this->assertSame(2, DB::table('lideres')->where('cedula', $cedula)->count());
        $this->assertSame([$this->campX], DB::table('data_consents')->where('event_id', $event->id)->pluck('campaign_id')->all());

        $this->actingAs($this->manager)->get('/eventos?q=ÑANDÚ&barrio=san martin')->assertOk();
        $this->actingAs($this->manager)->getJson("/eventos/{$event->id}?q=zzevento")->assertOk();
    }

    public function test_index_filters_with_accents_and_open_on_postgres(): void
    {
        $event = $this->event(Campaign::DEFAULT_ID, 'borrador');
        $event->barrios()->create(['nombre' => 'San Martín', 'nombre_normalizado' => 'san martin']);

        $total = fn (string $q) => $this->actingAs($this->manager)->get('/eventos?'.$q)->assertOk()->viewData('page')['props']['eventos']['total'];
        $this->assertSame(1, $total('q='.urlencode('ÑANDÚ').'&municipio='.$this->mun));
        $this->assertSame(1, $total('q=nandu&municipio='.$this->mun), 'Without accents or ñ');
        $this->assertSame(1, $total('q='.urlencode('GÍRA ñandu').'&municipio='.$this->mun));
        $this->assertSame(1, $total('barrio='.urlencode('San Martín').'&municipio='.$this->mun));
        $this->assertSame(0, $total('barrio=otro&municipio='.$this->mun));

        $this->actingAs($this->manager)->postJson("/eventos/{$event->id}/abrir")->assertOk()->assertJsonPath('data.public_open', true);

        // Real electoral persons: every result carries its latest candidacy and most have one.
        $figuras = $this->actingAs($this->manager)->getJson('/eventos/buscar/figuras?q=gar')->assertOk()->json('data');
        $this->assertNotEmpty($figuras);
        $this->assertSame(['id', 'nombre', 'ultimo_cargo', 'ultimo_anio', 'ultimo_lugar'], array_keys($figuras[0]));
        $this->assertNotEmpty(array_filter(array_column($figuras, 'ultimo_anio')));

        try {
            DB::transaction(fn () => DB::table('events')->where('id', $event->id)->update(['status' => 'inventado']));
            $this->fail('The status check constraint should reject it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('events_status_check', $e->getMessage());
        }
    }
}
