<?php

namespace Tests\Feature;

use App\Actions\Votantes\UpdateVotante;
use App\Http\Controllers\MapaPoliticoController;
use App\Support\DatabaseErrorLog;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Mirrors the NOT NULL columns the lideres migrations create (cargo, votos, nivel_confianza, tipo, booleans).
class UpdateVotanteNullFieldsTest extends TestCase
{
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('municipio');
            $t->string('cargo');
            $t->string('tipo')->default('directorio');
            $t->integer('votos')->default(0);
            $t->string('nivel_confianza')->default('sin_llamar');
            $t->boolean('destacado')->default(false);
            $t->boolean('gran_elector')->default(false);
            foreach (['telefono', 'cedula', 'email', 'observacion', 'direccion', 'barrio', 'zona', 'profesion', 'partido'] as $col) {
                $t->string($col)->nullable();
            }
            $t->uuid('geographic_unit_id')->nullable();
            $t->timestamps();
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
        });

        $this->id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $this->id, 'nombre' => 'Ana Prueba', 'municipio' => 'Socorro', 'cargo' => 'Líder',
            'votos' => 12, 'nivel_confianza' => 'alto', 'destacado' => true, 'gran_elector' => true,
            'telefono' => '3001234567',
        ]);
    }

    public function test_emptied_not_null_fields_save_their_defaults_instead_of_failing(): void
    {
        app(UpdateVotante::class)->handle($this->id, [
            'cargo' => null, 'votos' => null, 'nivel_confianza' => null, 'tipo' => null,
            'destacado' => null, 'gran_elector' => null, 'telefono' => null,
        ]);

        $row = DB::table('lideres')->where('id', $this->id)->first();
        $this->assertSame('', $row->cargo);
        $this->assertSame(0, (int) $row->votos);
        $this->assertSame('sin_llamar', $row->nivel_confianza);
        $this->assertSame('directorio', $row->tipo);
        $this->assertFalse((bool) $row->destacado);
        $this->assertFalse((bool) $row->gran_elector);
        $this->assertNull($row->telefono);
    }

    public function test_name_and_municipio_cannot_be_cleared(): void
    {
        app(UpdateVotante::class)->handle($this->id, ['nombre' => null, 'municipio' => null, 'cargo' => 'Concejal']);

        $row = DB::table('lideres')->where('id', $this->id)->first();
        $this->assertSame('Ana Prueba', $row->nombre);
        $this->assertSame('Socorro', $row->municipio);
        $this->assertSame('Concejal', $row->cargo);
    }

    public function test_database_errors_are_logged_without_row_values(): void
    {
        Log::spy();

        try {
            DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'nombre' => 'Dato Personal Secreto', 'municipio' => 'X', 'cargo' => null]);
            $this->fail('Expected a NOT NULL violation.');
        } catch (QueryException $e) {
            report($e);
        }

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            $logged = $message.json_encode($context);

            return str_starts_with($message, 'Database error: ')
                && ! str_contains($logged, 'Dato Personal Secreto')
                && str_contains($context['sql'] ?? '', '?');
        })->once();
    }

    public function test_mapa_politico_profile_saves_with_empty_cargo(): void
    {
        $request = Request::create('/mapa-politico/persona/'.$this->id, 'PUT', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->merge(['cargo' => null, 'votos' => null, 'destacado' => null, 'telefono' => '3009998877']);
        $this->app->instance('request', $request);

        $response = app(MapaPoliticoController::class)->updatePersona($request, $this->id);

        $this->assertSame(200, $response->getStatusCode());
        $row = DB::table('lideres')->where('id', $this->id)->first();
        $this->assertSame('', $row->cargo);
        $this->assertSame(0, (int) $row->votos);
        $this->assertSame('3009998877', $row->telefono);
    }

    /** @dataProvider postgresMessages */
    public function test_log_line_masks_values_but_keeps_object_names(string $driverMessage, string $expected): void
    {
        $this->assertSame($expected, DatabaseErrorLog::redact($driverMessage));
    }

    public static function postgresMessages(): array
    {
        return [
            'not null' => [
                "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"cargo\" of relation \"lideres\" violates not-null constraint\nDETAIL:  Failing row contains (Ana Prueba, 3001234567).",
                'SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "cargo" of relation "lideres" violates not-null constraint',
            ],
            'unique' => [
                "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"lideres_cedula_unique\"\nDETAIL:  Key (cedula)=(1098765432) already exists.",
                'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "lideres_cedula_unique"',
            ],
            'invalid uuid' => [
                'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "Juan Perez 3001234567"',
                'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "?"',
            ],
            'invalid date' => [
                'SQLSTATE[22007]: Invalid datetime format: 7 ERROR:  invalid input syntax for type date: "1985-13-45"',
                'SQLSTATE[22007]: Invalid datetime format: 7 ERROR:  invalid input syntax for type date: "?"',
            ],
            'out of range' => [
                'SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  value "99999999999" is out of range for type integer',
                'SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  value "?" is out of range for type integer',
            ],
        ];
    }
}
