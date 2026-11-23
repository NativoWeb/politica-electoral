<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Role;
use App\Models\User;
use App\Support\MigracionFlags;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: correcting a confirmed acta and acta data left incomplete (docs/eventos-api.md §14) on the real PostgreSQL
// schema: event_actas.version, jsonb migracion_flags and its filters, the accent-blind name match (translate + LIKE ANY)
// and the cédula unique index. Fictitious data, rolled back by DatabaseTransactions.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral DB_URL= php artisan test --filter=Postgres
class EventActaCorreccionPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $mun;

    private User $editor;

    private CampaignEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        $prov = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $prov, 'type' => 'province', 'canonical_name' => 'Provincia Actas PG']);
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Municipio Actas PG', 'parent_id' => $prov]);

        $this->editor = User::create([
            'name' => 'Actas PG', 'email' => 'actas-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => ['manage.eventos', 'view.eventos', 'edit.actas'],
        ]);
        $this->editor->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $this->event = new CampaignEvent([
            'event_type_id' => DB::table('event_types')->whereNull('campaign_id')->where('nombre', 'Gira')->value('id'),
            'nombre' => 'Reunión Actas PG', 'starts_at' => now()->subHour(), 'sitio_encuentro' => 'Parque', 'municipio_id' => $this->mun,
        ]);
        $this->event->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'status' => 'abierto', 'public_token' => Str::random(43)])->save();
    }

    private function lider(string $nombre, ?string $cedula = null): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun, 'nombre' => $nombre,
            'municipio' => 'Municipio Actas PG', 'cargo' => 'Líder', 'is_active' => true, 'cedula' => $cedula,
            'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ]);

        return $id;
    }

    private function confirmed(array $participantes): string
    {
        $id = $this->actingAs($this->editor)->postJson("/eventos/{$this->event->id}/actas", ['titulo' => 'ACTA PG', 'participantes' => $participantes])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->editor)->postJson("/eventos/{$this->event->id}/actas/{$id}/confirmar")->assertOk();

        return $id;
    }

    public function test_name_suggestion_ignores_accents_and_case_and_marks_the_new_ficha(): void
    {
        $mismo = $this->lider('ZZACTA ÑÚÑEZ PÉREZ');

        $suggest = $this->actingAs($this->editor)->postJson("/eventos/{$this->event->id}/actas/sugerencias", [
            'participantes' => [['nombre' => 'perez nunez zzacta'], ['nombre' => 'Zzacta Otra Persona']],
        ])->assertOk();
        $this->assertSame($mismo, $suggest->json('data.participantes.0.id'));
        $this->assertSame('nombre', $suggest->json('data.participantes.0.motivo'));
        $this->assertNull($suggest->json('data.participantes.1'));

        $actaId = $this->confirmed([['nombre' => 'perez nunez zzacta']]);
        $ficha = DB::table('event_acta_participantes')->where('acta_id', $actaId)->value('lider_id');
        $this->assertNotSame($mismo, $ficha, 'Never linked by name alone');
        $flags = json_decode(DB::table('lideres')->where('id', $ficha)->value('migracion_flags'), true);
        $this->assertSame([$mismo], $flags['posible_duplicado']);
        $this->assertSame(['cedula', 'telefono'], $flags['por_completar']);

        // The Votantes filter "Datos por completar" finds it by field.
        foreach (['cedula' => true, 'telefono' => true, 'municipio' => false, 'cualquiera' => true] as $campo => $found) {
            $query = DB::table('lideres')->where('lideres.id', $ficha);
            MigracionFlags::applyFilters($query, ['por_completar' => $campo]);
            $this->assertSame($found, $query->exists(), $campo);
        }
    }

    // BUG-02: jsonb returns desarrollo with its keys in another order; that is not a change.
    public function test_only_the_changed_field_is_reported_and_nothing_changed_writes_nothing(): void
    {
        $id = $this->actingAs($this->editor)->postJson("/eventos/{$this->event->id}/actas", [
            'titulo' => 'ACTA PG JSONB', 'sitio' => 'Parque', 'orden_dia' => ['Uno'],
            'desarrollo' => [['titulo' => 'Uno', 'texto' => 'Texto largo del desarrollo']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->editor)->postJson("/eventos/{$this->event->id}/actas/{$id}/confirmar")->assertOk();
        $url = "/eventos/{$this->event->id}/actas/{$id}";
        $acta = $this->actingAs($this->editor)->get($url)->viewData('page')['props']['acta'];
        $body = [...array_intersect_key($acta, array_flip(['titulo', 'fecha', 'municipio', 'sitio', 'hora_inicio', 'hora_fin', 'introduccion', 'orden_dia', 'desarrollo', 'cierre'])),
            'version' => $acta['version'], 'participantes' => [], 'compromisos' => []];

        $this->actingAs($this->editor)->putJson("{$url}/correccion", $body)->assertOk()->assertJsonPath('resumen.sin_cambios', true)->assertJsonPath('data.version', 0);
        $this->actingAs($this->editor)->putJson("{$url}/correccion", [...$body, 'sitio' => 'Salón comunal'])->assertOk()
            ->assertJsonPath('resumen.cambios', ['sitio'])->assertJsonPath('data.version', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CORREGIDA')->whereRaw("metadata->>'acta_id' = ?", [$id])->count());
    }

    public function test_a_correction_on_postgres_bumps_the_version_fills_the_ficha_and_refuses_a_foreign_cedula(): void
    {
        $this->lider('Zzacta Otra Ficha', '91919191');
        $actaId = $this->confirmed([['nombre' => 'Zzacta Solo Nombre Pg']]);
        $ficha = DB::table('event_acta_participantes')->where('acta_id', $actaId)->value('lider_id');
        $props = $this->actingAs($this->editor)->get("/eventos/{$this->event->id}/actas/{$actaId}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['can']['edit_confirmed']);
        $this->assertSame('confirmada', $props['historial'][0]['accion']);
        $body = ['titulo' => 'ACTA PG', 'version' => $props['acta']['version'],
            'participantes' => [['id' => $props['acta']['participantes'][0]['id'], 'nombre' => 'Zzacta Solo Nombre Pg', 'cedula' => '91919191']]];

        $this->actingAs($this->editor)->putJson("/eventos/{$this->event->id}/actas/{$actaId}/correccion", $body)->assertUnprocessable()
            ->assertJsonValidationErrors('participantes.0.cedula');
        $this->assertNull(DB::table('lideres')->where('id', $ficha)->value('cedula'));

        $body['participantes'][0]['cedula'] = '92929292';
        $this->actingAs($this->editor)->putJson("/eventos/{$this->event->id}/actas/{$actaId}/correccion", $body)->assertOk()->assertJsonPath('data.version', 1);
        $this->actingAs($this->editor)->putJson("/eventos/{$this->event->id}/actas/{$actaId}/correccion", $body)->assertStatus(409);
        $this->assertSame('92929292', DB::table('lideres')->where('id', $ficha)->value('cedula'));
        $this->assertSame(['telefono'], json_decode(DB::table('lideres')->where('id', $ficha)->value('migracion_flags'), true)['por_completar']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENTO_ACTA_CORREGIDA')->whereRaw("metadata->>'acta_id' = ?", [$actaId])->count());

        $historial = $this->actingAs($this->editor)->get("/eventos/{$this->event->id}/actas/{$actaId}")->viewData('page')['props']['historial'];
        $this->assertSame(['corregida', 'confirmada'], array_column($historial, 'accion'));
        $this->assertSame('1 participante corregido', $historial[0]['resumen']);
    }
}
