<?php

namespace Tests\Feature;

use App\Actions\Votantes\AddNexo;
use App\Actions\Votantes\UpdateVotante;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\MapaPoliticoController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// Offline creates are retried (two tabs, lost responses): the device id makes them idempotent.
class OfflineCreateIdempotencyTest extends TestCase
{
    use WithCampaignColumns;

    private string $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('canonical_name');
            $t->uuid('parent_id')->nullable();
        });
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('municipio');
            $t->string('cargo')->nullable();
            $t->string('tipo')->default('directorio');
            $t->string('nivel_confianza')->default('sin_llamar');
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('cedula')->nullable()->unique();
            $t->string('partido')->nullable();
            $t->boolean('destacado')->default(false);
            $t->integer('votos')->default(0);
            foreach (['provincia', 'telefono', 'email', 'profesion', 'direccion', 'barrio', 'zona', 'observacion',
                'tipo_documento', 'fecha_expedicion_doc', 'genero', 'fecha_nacimiento', 'estado_civil',
                'referente_documento', 'referente_nombre', 'referente_apellido', 'departamento_votacion',
                'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion', 'convenio',
                'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv', 'facebook', 'twitter', 'instagram'] as $col) {
                $t->string($col)->nullable();
            }
            foreach (['militante', 'autoriza_datos', 'verificado', 'fallecido', 'empresario', 'reservista',
                'funcionario', 'exfuncionario', 'gran_elector'] as $col) {
                $t->boolean($col)->default(false);
            }
            $t->timestamps();
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->uuid('contest_id')->nullable();
        });
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
        });
        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            foreach (['nombre', 'parentesco', 'cargo', 'gustos', 'observaciones', 'cedula', 'telefono'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('edad')->nullable();
            $t->timestamps();
        });

        $this->addCampaignColumns();
        $this->municipio = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->municipio, 'type' => 'municipality', 'canonical_name' => 'Socorro']);
    }

    private function storeLider(array $payload): int
    {
        $request = Request::create('/mapa-politico/crear-lider', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);

        return app(MapaPoliticoController::class)->storeLider($request)->getStatusCode();
    }

    public function test_the_same_offline_lider_sent_twice_is_stored_once_with_the_device_id(): void
    {
        $clientId = (string) Str::uuid();
        $payload = ['nombre' => 'Pedro Gómez', 'municipio_id' => $this->municipio, 'cedula' => '1098765432', 'client_id' => $clientId];

        $this->assertSame(200, $this->storeLider($payload));
        $this->assertSame(200, $this->storeLider($payload));

        $this->assertSame(1, DB::table('lideres')->where('nombre', 'Pedro Gómez')->count());
        $this->assertSame($clientId, DB::table('lideres')->value('id'));
    }

    public function test_without_client_id_the_online_flow_still_creates_normally(): void
    {
        $this->assertSame(200, $this->storeLider(['nombre' => 'Lucía Díaz', 'municipio_id' => $this->municipio]));
        $this->assertSame(200, $this->storeLider(['nombre' => 'Lucía Díaz', 'municipio_id' => $this->municipio]));

        $this->assertSame(2, DB::table('lideres')->where('nombre', 'Lucía Díaz')->count());
    }

    public function test_the_same_offline_nexo_sent_twice_is_stored_once(): void
    {
        $clientId = (string) Str::uuid();
        $owner = (string) Str::uuid();

        $first = app(AddNexo::class)->handle($owner, ['nombre' => 'Ana', 'parentesco' => 'Hija', 'client_id' => $clientId]);
        $second = app(AddNexo::class)->handle($owner, ['nombre' => 'Ana', 'parentesco' => 'Hija', 'client_id' => $clientId]);

        $this->assertSame($clientId, $first);
        $this->assertSame($clientId, $second);
        $this->assertSame(1, DB::table('nexos_familiares')->count());
    }

    public function test_a_client_id_cannot_reuse_an_electoral_person_id(): void
    {
        $personId = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $personId, 'full_name' => 'Candidato de otro municipio']);

        $this->expectException(ValidationException::class);
        try {
            $this->storeLider(['nombre' => 'Suplantador', 'municipio_id' => $this->municipio, 'client_id' => $personId]);
        } finally {
            $this->assertSame(0, DB::table('lideres')->where('id', $personId)->count());
        }
    }

    public function test_a_new_lider_with_another_liders_cedula_still_gets_the_duplicate_message(): void
    {
        $this->storeLider(['nombre' => 'Original', 'municipio_id' => $this->municipio, 'cedula' => '1111111111']);

        $request = Request::create('/mapa-politico/crear-lider', 'POST',
            ['nombre' => 'Otro', 'municipio_id' => $this->municipio, 'cedula' => '1111111111', 'client_id' => (string) Str::uuid()],
            [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);
        $response = app(MapaPoliticoController::class)->storeLider($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Ya existe un líder con cédula', $response->getData()->message);
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_the_same_cedula_written_with_dots_is_still_a_duplicate(): void
    {
        $this->storeLider(['nombre' => 'Elsa Rojas', 'municipio_id' => $this->municipio, 'cedula' => '63.525.141']);
        $this->assertSame('63525141', DB::table('lideres')->value('cedula'));

        $this->assertSame(422, $this->storeLider(['nombre' => 'ELSA ROJAS', 'municipio_id' => $this->municipio, 'cedula' => '63525141']));
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_a_lider_whose_formatted_cedula_clashes_can_still_be_edited(): void
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            ['id' => (string) Str::uuid(), 'nombre' => 'Otra persona', 'municipio' => 'Socorro', 'geographic_unit_id' => $this->municipio, 'cedula' => '1097333182'],
            ['id' => $id, 'nombre' => 'Asbleidy', 'municipio' => 'Socorro', 'geographic_unit_id' => $this->municipio, 'cedula' => "1.097'333.182"],
        ]);

        // The form resends the unchanged cédula along with the edited field.
        app(UpdateVotante::class)->handle($id, ['cedula' => "1.097'333.182", 'telefono' => '3001234567']);

        $this->assertSame('3001234567', DB::table('lideres')->where('id', $id)->value('telefono'));
        $this->assertSame("1.097'333.182", DB::table('lideres')->where('id', $id)->value('cedula'));
    }

    public function test_the_mapa_politico_panel_can_edit_a_lider_whose_formatted_cedula_clashes(): void
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            ['id' => (string) Str::uuid(), 'nombre' => 'Otra persona', 'municipio' => 'Socorro', 'geographic_unit_id' => $this->municipio, 'cedula' => '1097333182'],
            ['id' => $id, 'nombre' => 'Asbleidy', 'municipio' => 'Socorro', 'geographic_unit_id' => $this->municipio, 'cedula' => "1.097'333.182"],
        ]);

        $request = Request::create("/mapa-politico/persona/{$id}", 'PUT',
            ['cedula' => "1.097'333.182", 'telefono' => '3001234567'], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);
        $response = app(MapaPoliticoController::class)->updatePersona($request, $id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('3001234567', DB::table('lideres')->where('id', $id)->value('telefono'));
        $this->assertSame("1.097'333.182", DB::table('lideres')->where('id', $id)->value('cedula'));
    }

    public function test_a_nexo_id_owned_by_someone_else_is_a_conflict(): void
    {
        $clientId = (string) Str::uuid();
        app(AddNexo::class)->handle((string) Str::uuid(), ['nombre' => 'Ana', 'client_id' => $clientId]);

        try {
            app(AddNexo::class)->handle((string) Str::uuid(), ['nombre' => 'Intruso', 'client_id' => $clientId]);
            $this->fail('Expected a conflict.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->status());
        }
        $this->assertSame('Ana', DB::table('nexos_familiares')->where('id', $clientId)->value('nombre'));
    }
}
