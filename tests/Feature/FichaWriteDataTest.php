<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// H1 (auditoría de seguridad): a write role is not enough to edit a ficha, the user also needs write.data, on the
// server and not only in the screen (canWrite). Eventos keeps its own permissions (manage.eventos, register.asistentes).
class FichaWriteDataTest extends TestCase
{
    use BuildsEventosScenario;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        $this->id = $this->lider(Campaign::DEFAULT_ID, 'Ficha H1', '0999');
    }

    public function test_a_write_role_without_write_data_cannot_edit_a_ficha(): void
    {
        $campo = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa'], 'R09_CAMPO');

        $this->actingAs($campo)->putJson("/votantes/{$this->id}", ['barrio' => 'Otro'])->assertForbidden();
        $this->actingAs($campo)->putJson("/mapa-politico/persona/{$this->id}", ['barrio' => 'Otro'])->assertForbidden();
        $this->actingAs($campo)->postJson("/votantes/{$this->id}/contactos", ['tipo' => 'celular', 'valor' => '1'])->assertForbidden();
        $this->actingAs($campo)->postJson('/votantes', ['nombre' => 'Nueva', 'municipio_id' => $this->giron])->assertForbidden();
        $this->actingAs($campo)->postJson('/mapa-politico/crear-lider', ['nombre' => 'Nueva', 'municipio_id' => $this->giron])->assertForbidden();

        $this->assertSame('Barrio original', DB::table('lideres')->where('id', $this->id)->value('barrio'));
        $this->assertSame(0, DB::table('lider_contactos')->count());
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_with_write_data_the_same_user_edits_it(): void
    {
        $campo = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'write.data'], 'R09_CAMPO');

        $this->actingAs($campo)->putJson("/votantes/{$this->id}", ['barrio' => 'Nuevo'])->assertOk();
        $this->assertSame('Nuevo', DB::table('lideres')->where('id', $this->id)->value('barrio'));
    }

    public function test_a_read_only_role_stays_out_even_with_write_data(): void
    {
        $consulta = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'write.data'], 'R10_CONSULTA_EJECUTIVA');

        $this->actingAs($consulta)->putJson("/votantes/{$this->id}", ['barrio' => 'Otro'])->assertForbidden();
    }
}
