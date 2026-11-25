<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Rules\NombreSinNumeros;
use App\Services\Eventos\ImportRowValidator;
use App\Support\NombreFicha;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// A ficha's name with digits is refused at every entry (Votantes, Mapa Político, event attendees by QR, operator, edit
// and Excel), with the message that says where the cédula or the phone go; an old name with digits sent back unchanged
// on an edit is still accepted. SQLite, fictitious data.
class NombreSinNumerosTest extends TestCase
{
    use BuildsEventosScenario;

    private const MSG = 'El nombre no puede tener números: si es la cédula o el teléfono, escríbelo en su campo.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        $this->addMapaVotantesTables();
        $this->user = $this->eventUser(Campaign::DEFAULT_ID, ['view.votantes', 'view.mapa', 'write.data', 'manage.eventos', 'register.asistentes']);
    }

    public function test_the_message_and_the_rule(): void
    {
        $this->assertSame(self::MSG, NombreFicha::MENSAJE);
        $this->assertSame('A A9', NombreFicha::patron('JUAN  PEREZ2'));
        $passes = fn ($valor, $actual = null) => Validator::make(['n' => $valor], ['n' => [new NombreSinNumeros($actual)]])->passes();

        $this->assertTrue($passes('María José Ñáñez'));
        $this->assertFalse($passes('Juan 3001234567'));
        $this->assertFalse($passes('1098765432'));
        $this->assertTrue($passes(' JUAN  PEREZ2 ', 'juan perez2'), 'The stored name sent back unchanged.');
        $this->assertFalse($passes('JUAN PEREZ3', 'JUAN PEREZ2'));
    }

    public function test_votantes_create_and_edit_refuse_digits_in_the_name(): void
    {
        $this->actingAs($this->user)->postJson('/votantes', ['nombre' => 'Ana Díaz 1098765432', 'municipio_id' => $this->giron])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', self::MSG);
        $this->assertDatabaseMissing('lideres', ['nombre' => 'Ana Díaz 1098765432']);

        $this->actingAs($this->user)->postJson('/votantes', ['nombre' => 'Ana Díaz', 'municipio_id' => $this->giron])->assertCreated();

        $id = $this->lider(Campaign::DEFAULT_ID, 'Beto Ruiz', '555');
        $this->actingAs($this->user)->putJson("/votantes/{$id}", ['nombre' => 'Beto Ruiz 3001234567'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', self::MSG);
        $this->assertSame('Beto Ruiz', DB::table('lideres')->where('id', $id)->value('nombre'));
    }

    public function test_an_old_name_with_digits_does_not_block_saving_other_fields(): void
    {
        $id = $this->lider(Campaign::DEFAULT_ID, 'CARLOS RIOS2', '556');

        $this->actingAs($this->user)->putJson("/votantes/{$id}", ['nombre' => 'CARLOS RIOS2', 'observacion' => 'Llamar el lunes'])->assertOk();
        $this->assertSame('Llamar el lunes', DB::table('lideres')->where('id', $id)->value('observacion'));

        $this->actingAs($this->user)->putJson("/votantes/{$id}", ['nombre' => 'CARLOS RIOS 3'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', self::MSG);
        $this->actingAs($this->user)->putJson("/votantes/{$id}", ['nombre' => 'CARLOS RIOS'])->assertOk();
        $this->assertSame('CARLOS RIOS', DB::table('lideres')->where('id', $id)->value('nombre'));
    }

    public function test_the_mapa_politico_create_and_edit_refuse_digits_in_the_name(): void
    {
        $this->actingAs($this->user)->postJson('/mapa-politico/crear-lider', ['nombre' => '3001234567 Dora Paz', 'municipio_id' => $this->giron])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', self::MSG);
        $this->assertSame(0, DB::table('lideres')->where('nombre', 'like', '%Dora%')->count());

        $id = $this->lider(Campaign::DEFAULT_ID, 'Elsa Mora', '557');
        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$id}", ['nombre' => 'Elsa Mora 7'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', self::MSG);
        $this->assertSame('Elsa Mora', DB::table('lideres')->where('id', $id)->value('nombre'));

        $viejo = $this->lider(Campaign::DEFAULT_ID, 'FABIO LEON 12', '558');
        $this->actingAs($this->user)->putJson("/mapa-politico/persona/{$viejo}", ['nombre' => 'FABIO LEON 12', 'observacion' => 'Nota nueva'])->assertOk();
        $this->assertSame('Nota nueva', DB::table('lideres')->where('id', $viejo)->value('observacion'));
    }

    public function test_attendees_by_qr_operator_and_edit_refuse_digits_in_nombres_and_apellidos(): void
    {
        $event = $this->makeEvent(Campaign::DEFAULT_ID);

        $this->postJson("/e/{$event->public_token}", $this->publicPayload($event, null, ['nombres' => 'Ana 1098765432']))
            ->assertStatus(422)->assertJsonPath('errors.nombres.0', self::MSG);
        $this->actingAs($this->user)->postJson("/eventos/{$event->id}/asistentes", $this->attendeePayload(null, ['apellidos' => 'Pérez 3001234567']))
            ->assertStatus(422)->assertJsonPath('errors.apellidos.0', self::MSG);
        $this->assertSame(0, DB::table('event_attendees')->count());

        $id = (string) Str::uuid();
        DB::table('event_attendees')->insert([
            'id' => $id, 'event_id' => $event->id, 'campaign_id' => $event->campaign_id, 'nombres' => 'Gloria 2', 'apellidos' => 'Pinto',
            'cedula' => '91234567', 'celular' => '3001234567', 'barrio' => 'Centro', 'barrio_normalizado' => 'centro', 'source' => 'operador',
            'link_status' => 'pendiente', 'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $edit = ['nombres' => 'Gloria 2', 'apellidos' => 'Pinto', 'cedula' => '91234567', 'celular' => '3001234567', 'barrio' => 'Norte'];
        $this->actingAs($this->user)->putJson("/eventos/{$event->id}/asistentes/{$id}", $edit)->assertOk();
        $this->actingAs($this->user)->putJson("/eventos/{$event->id}/asistentes/{$id}", ['apellidos' => 'Pinto 9'] + $edit)
            ->assertStatus(422)->assertJsonPath('errors.apellidos.0', self::MSG);
        $this->assertSame('Pinto', DB::table('event_attendees')->where('id', $id)->value('apellidos'));
    }

    public function test_an_excel_row_with_digits_in_the_name_is_an_error_of_that_row_only(): void
    {
        $validator = new ImportRowValidator;
        $fila = fn (string $nombres) => ['nombres' => $nombres, 'apellidos' => 'Rojas', 'cedula' => '91234567', 'celular' => '3001234567',
            'fijo' => null, 'fecha_nacimiento' => null, 'direccion' => null, 'barrio' => 'Centro', 'municipio' => null, 'liderazgo' => null];

        $this->assertSame(self::MSG, $validator->validate($fila('Hugo 91234567'))['errors']['nombres'] ?? null);
        $this->assertSame([], $validator->validate($fila('Hugo'))['errors']);
        $this->assertSame(self::MSG, $validator->validateList($fila('Hugo 3001234567'))['errors']['nombres'] ?? null);
        $this->assertSame([], $validator->validateList($fila('Hugo'))['errors']);
    }
}
