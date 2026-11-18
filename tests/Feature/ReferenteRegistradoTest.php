<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// The referente of a ficha has to be a lider of the own campaign, unless it did not change (old fichas, Electo). SQLite.
class ReferenteRegistradoTest extends TestCase
{
    use BuildsEventosScenario;

    private const NO_REGISTRADA = 'Esta persona no está registrada en el sistema.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->user = $this->eventUser(Campaign::DEFAULT_ID, ['view.mapa', 'view.votantes']);
    }

    public function test_crear_lider_rejects_a_referente_nobody_has(): void
    {
        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Zzref Nuevo', 'municipio_id' => $this->giron,
            'referente_documento' => '5555555', 'referente_nombre' => 'Inventado', 'referente_apellido' => 'A Mano',
        ])->assertStatus(422)->assertJsonPath('errors.referente_documento.0', self::NO_REGISTRADA);

        $this->assertDatabaseMissing('lideres', ['nombre' => 'Zzref Nuevo']);
    }

    public function test_crear_lider_rejects_a_referente_of_another_campaign(): void
    {
        $this->lider($this->campY, 'Ajeno', '7777777');

        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Zzref Nuevo', 'municipio_id' => $this->giron, 'referente_documento' => '7777777',
        ])->assertStatus(422)->assertJsonPath('errors.referente_documento.0', self::NO_REGISTRADA);
    }

    public function test_crear_lider_rejects_an_inactive_referente_and_accepts_one_of_another_municipio(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Inactivo', '8888888', ['is_active' => false]);
        $this->lider(Campaign::DEFAULT_ID, 'En Velez', '8888889', ['geographic_unit_id' => $this->velez, 'municipio' => 'Vélez']);

        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Zzref Inactivo', 'municipio_id' => $this->giron, 'referente_documento' => '8888888',
        ])->assertStatus(422)->assertJsonPath('errors.referente_documento.0', self::NO_REGISTRADA);

        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Zzref Velez', 'municipio_id' => $this->giron, 'referente_documento' => '8888889',
        ])->assertOk();
    }

    public function test_crear_lider_accepts_a_registered_referente_typed_with_dots(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Maria Referente', '1098765432');

        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Zzref Nuevo', 'municipio_id' => $this->giron,
            'referente_documento' => '1.098.765.432', 'referente_nombre' => 'Maria', 'referente_apellido' => 'Referente',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('lideres', ['nombre' => 'Zzref Nuevo', 'referente_documento' => '1098765432']);
    }

    public function test_crear_lider_duplicate_cedula_reports_the_field(): void
    {
        $this->lider(Campaign::DEFAULT_ID, 'Ya Existe', '1234');

        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Zzref Dup', 'municipio_id' => $this->giron, 'cedula' => '1234',
        ])->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['message', 'errors' => ['cedula']]);
    }

    public function test_update_persona_rejects_a_new_unregistered_referente_and_keeps_an_unchanged_old_one(): void
    {
        // Migrated from Electo: its referente is not registered in the system.
        $id = $this->lider(Campaign::DEFAULT_ID, 'Ficha Vieja', '2222', [
            'referente_documento' => '9990001', 'referente_nombre' => 'Escrito', 'referente_apellido' => 'A Mano',
        ]);

        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$id}", [
            'telefono' => '3111111111', 'referente_documento' => '9990001', 'referente_nombre' => 'Escrito', 'referente_apellido' => 'A Mano',
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('lideres', ['id' => $id, 'telefono' => '3111111111', 'referente_documento' => '9990001']);

        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$id}", ['referente_documento' => '9990002'])
            ->assertStatus(422)->assertJsonPath('errors.referente_documento.0', self::NO_REGISTRADA);
        $this->assertDatabaseHas('lideres', ['id' => $id, 'referente_documento' => '9990001']);

        $this->lider(Campaign::DEFAULT_ID, 'Referente Real', '3333');
        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$id}", [
            'referente_documento' => '3333', 'referente_nombre' => 'Referente', 'referente_apellido' => 'Real',
        ])->assertOk();
        $this->assertDatabaseHas('lideres', ['id' => $id, 'referente_documento' => '3333', 'referente_nombre' => 'Referente']);

        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$id}", [
            'referente_documento' => null, 'referente_nombre' => null, 'referente_apellido' => null,
        ])->assertOk();
        $this->assertDatabaseHas('lideres', ['id' => $id, 'referente_documento' => null, 'referente_nombre' => null]);
    }

    public function test_votantes_store_and_update_validate_the_referente(): void
    {
        $this->actingAs($this->user)->postJson('/votantes', [
            'nombre' => 'Zzvot Nuevo', 'municipio_id' => $this->giron, 'referente_documento' => '5555555',
        ])->assertStatus(422)->assertJsonPath('errors.referente_documento.0', self::NO_REGISTRADA);

        $this->lider(Campaign::DEFAULT_ID, 'Referente Votante', '4444');
        $this->actingAs($this->user)->postJson('/votantes', [
            'nombre' => 'Zzvot Nuevo', 'municipio_id' => $this->giron, 'referente_documento' => '4444',
        ])->assertCreated();

        $viejo = $this->lider(Campaign::DEFAULT_ID, 'Votante Viejo', '6666', ['referente_documento' => '9990003', 'referente_nombre' => 'Sin Registro']);
        $this->actingAs($this->user)->putJson("/votantes/{$viejo}", [
            'barrio' => 'Nuevo barrio', 'referente_documento' => '9990003', 'referente_nombre' => 'Sin Registro',
        ])->assertOk();
        $this->actingAs($this->user)->putJson("/votantes/{$viejo}", ['referente_documento' => '9990004'])
            ->assertStatus(422)->assertJsonPath('errors.referente_documento.0', self::NO_REGISTRADA);

        $this->assertSame('9990003', DB::table('lideres')->where('id', $viejo)->value('referente_documento'));
        $this->assertSame('Nuevo barrio', DB::table('lideres')->where('id', $viejo)->value('barrio'));
    }
}
