<?php

namespace Tests\Feature;

use App\Actions\Votantes\FindPossibleDuplicates;
use App\Actions\Votantes\MergeLideres;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\MapaPoliticoController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// The same person entered twice: the panel merges the duplicate into the record being edited.
class MergeLideresTest extends TestCase
{
    private string $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('municipio')->nullable();
            $t->string('provincia')->nullable();
            $t->string('cargo')->default('');
            $t->string('nivel_confianza')->default('sin_llamar');
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('cedula')->nullable()->unique();
            foreach (['telefono', 'email', 'observacion', 'barrio', 'partido'] as $col) {
                $t->string($col)->nullable();
            }
            $t->integer('votos')->default(0);
            $t->boolean('militante')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        foreach (['lider_contactos', 'lider_info_politica', 'lider_seguimientos'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('lider_id');
            });
        }
        Schema::create('lider_municipios_influencia', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->uuid('geographic_unit_id');
            $t->unique(['lider_id', 'geographic_unit_id']);
        });
        foreach (['nexos_familiares', 'whatsapp_send_recipients'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('person_id');
            });
        }
        Schema::create('favorites', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('favoritable_id');
        });
        Schema::create('recent_views', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewable_id');
        });
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
        });
        Schema::create('contests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('geographic_unit_id');
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->uuid('contest_id');
        });
        Schema::create('taggables', function (Blueprint $t) {
            $t->uuid('tag_id');
            $t->uuid('taggable_id');
        });

        $this->municipio = (string) Str::uuid();
    }

    private function lider(array $attrs): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert($attrs + ['id' => $id, 'municipio' => 'Concepcion', 'geographic_unit_id' => $this->municipio]);

        return $id;
    }

    public function test_the_two_records_become_one_with_the_edited_values_first(): void
    {
        $keep = $this->lider(['nombre' => 'Deisy Paola Villamizar Bohada', 'cargo' => 'Concejal', 'telefono' => '3143635963',
            'observacion' => 'Es concejal del partido CD.', 'partido' => 'Centro Democrático']);
        $drop = $this->lider(['nombre' => 'Paola Villamizar', 'cargo' => 'Directorio Municipal, Concejal', 'telefono' => '3143635993',
            'cedula' => '1098151229', 'observacion' => 'Llamada: si vota', 'email' => 'paola@example.com', 'votos' => 175, 'militante' => true]);
        $influencia = (string) Str::uuid();
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $drop]);
        DB::table('nexos_familiares')->insert(['id' => (string) Str::uuid(), 'person_id' => $drop]);
        DB::table('lider_municipios_influencia')->insert([
            ['id' => (string) Str::uuid(), 'lider_id' => $keep, 'geographic_unit_id' => $influencia],
            ['id' => (string) Str::uuid(), 'lider_id' => $drop, 'geographic_unit_id' => $influencia],
        ]);

        $merged = app(MergeLideres::class)->handle($keep, $drop);

        $this->assertSame($drop, $merged->id, 'the more complete record survives');
        $this->assertSame('Deisy Paola Villamizar Bohada', $merged->nombre);
        $this->assertSame('1098151229', $merged->cedula);
        $this->assertSame('Concejal, Directorio Municipal', $merged->cargo);
        $this->assertSame('3143635963', $merged->telefono);
        $this->assertSame("Es concejal del partido CD.\nLlamada: si vota\nOtro teléfono registrado: 3143635993.\nTambién registrado como: Paola Villamizar.", $merged->observacion);
        $this->assertSame('paola@example.com', $merged->email);
        $this->assertSame('Centro Democrático', $merged->partido);
        $this->assertSame(175, (int) $merged->votos);
        $this->assertTrue((bool) $merged->militante);

        $this->assertNull(DB::table('lideres')->where('id', $keep)->first());
        $this->assertSame(1, DB::table('lider_contactos')->where('lider_id', $drop)->count());
        $this->assertSame(1, DB::table('nexos_familiares')->where('person_id', $drop)->count());
        $this->assertSame(1, DB::table('lider_municipios_influencia')->count());
    }

    public function test_merging_from_the_sparse_record_keeps_the_complete_one_and_the_fullest_name(): void
    {
        $sparse = $this->lider(['nombre' => 'Cesar Silva Diaz', 'cargo' => 'Concejal', 'telefono' => '3204228817']);
        $complete = $this->lider(['nombre' => 'Cesar Ricardo Silva Diaz', 'cargo' => 'Directorio Municipal', 'telefono' => '3204228817',
            'cedula' => '91452739', 'email' => 'cesar@example.com']);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $complete]);

        $merged = app(MergeLideres::class)->handle($sparse, $complete);

        $this->assertSame($complete, $merged->id);
        $this->assertSame('Cesar Ricardo Silva Diaz', $merged->nombre);
        $this->assertSame('Concejal, Directorio Municipal', $merged->cargo);
        $this->assertSame('91452739', $merged->cedula);
        $this->assertSame('cesar@example.com', $merged->email);
        $this->assertSame('También registrado como: Cesar Silva Diaz.', $merged->observacion);
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_the_survivor_stays_active_in_the_edited_records_municipio(): void
    {
        $edited = $this->lider(['nombre' => 'Ana Rojas', 'telefono' => '3001234567']);
        $otroMunicipio = (string) Str::uuid();
        $other = $this->lider(['nombre' => 'Ana Rojas', 'municipio' => 'Otro', 'geographic_unit_id' => $otroMunicipio,
            'cedula' => '1234567', 'email' => 'ana@example.com', 'barrio' => 'Centro', 'is_active' => false]);

        $merged = app(MergeLideres::class)->handle($edited, $other);

        $this->assertSame($other, $merged->id, 'the more complete record survives');
        $this->assertSame($this->municipio, $merged->geographic_unit_id);
        $this->assertSame('Concepcion', $merged->municipio);
        $this->assertTrue((bool) $merged->is_active);
    }

    public function test_a_name_linked_to_a_candidate_is_kept(): void
    {
        $edited = $this->lider(['nombre' => 'Cesar Silva Diaz', 'telefono' => '3204228817']);
        $other = $this->lider(['nombre' => 'Cesar Ricardo Silva Diaz', 'telefono' => '3204228817']);
        $person = (string) Str::uuid();
        $contest = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'CESAR SILVA DIAZ']);
        DB::table('contests')->insert(['id' => $contest, 'geographic_unit_id' => $this->municipio]);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $person, 'contest_id' => $contest]);

        $merged = app(MergeLideres::class)->handle($edited, $other);

        $this->assertSame('Cesar Silva Diaz', $merged->nombre);
        $this->assertSame('También registrado como: Cesar Ricardo Silva Diaz.', $merged->observacion);
    }

    public function test_possible_duplicates_are_found_by_phone_or_contained_name_but_not_with_other_cedulas(): void
    {
        $cesar = $this->lider(['nombre' => 'Cesar Silva Diaz', 'telefono' => '320 422 8817']);
        $this->lider(['nombre' => 'Cesar Ricardo Silva Diaz', 'telefono' => '3204228817', 'cedula' => '91452739']);
        $this->lider(['nombre' => 'C. Silva', 'telefono' => '3204228817']);
        $this->lider(['nombre' => 'Maria Diaz', 'telefono' => '3204228817']);
        $this->lider(['nombre' => 'Pedro Gomez', 'telefono' => '3001112233']);
        $this->lider(['nombre' => 'Cesar Diaz Lopez', 'telefono' => '3005556677']);
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'nombre' => 'Cesar Silva Diaz', 'municipio' => 'Otro', 'geographic_unit_id' => (string) Str::uuid()]);

        $found = collect(app(FindPossibleDuplicates::class)->handle($cesar))->pluck('motivo', 'nombre')->all();

        $this->assertSame(['Cesar Ricardo Silva Diaz' => 'Mismo teléfono', 'C. Silva' => 'Mismo teléfono'], $found);

        $withCedula = $this->lider(['nombre' => 'Cesar Silva Diaz Rojas', 'cedula' => '1234567']);
        $names = collect(app(FindPossibleDuplicates::class)->handle($withCedula))->pluck('nombre')->all();
        $this->assertNotContains('Cesar Ricardo Silva Diaz', $names, 'a different cédula is never offered');
        $this->assertContains('Cesar Silva Diaz', $names);
    }

    public function test_records_with_different_cedulas_are_not_merged(): void
    {
        $keep = $this->lider(['nombre' => 'Juan Pedraza', 'cedula' => '1072365898']);
        $drop = $this->lider(['nombre' => 'Juan Ramon Pedraza', 'cedula' => '1072365893']);

        $this->expectException(BusinessRuleException::class);
        try {
            app(MergeLideres::class)->handle($keep, $drop);
        } finally {
            $this->assertSame(2, DB::table('lideres')->count());
        }
    }

    public function test_a_mistyped_cedula_is_replaced_by_the_duplicates_when_the_form_says_so(): void
    {
        $keep = $this->lider(['nombre' => 'Juan Ramon Pedraza Witinga', 'cedula' => '1072365898', 'email' => 'juan@a.com']);
        $drop = $this->lider(['nombre' => 'Juan Pedraza', 'cedula' => '1072365893', 'email' => 'juan@b.com']);

        $merged = app(MergeLideres::class)->handle($keep, $drop, '1072365893');

        $this->assertSame('1072365893', $merged->cedula);
        $this->assertSame('juan@a.com', $merged->email);
        $this->assertSame("Otro correo registrado: juan@b.com.\nTambién registrado como: Juan Pedraza.\nCédula anterior: 1072365898.", $merged->observacion);
        $this->assertSame(1, DB::table('lideres')->count());
    }

    public function test_saving_a_duplicate_cedula_offers_the_other_record_to_merge(): void
    {
        $keep = $this->lider(['nombre' => 'Deisy Paola Villamizar Bohada']);
        $drop = $this->lider(['nombre' => 'Paola Villamizar', 'cedula' => '1098151229', 'cargo' => 'Directorio Municipal']);

        $request = Request::create("/mapa-politico/persona/{$keep}", 'PUT', ['cedula' => '1.098.151.229'], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->app->instance('request', $request);
        $response = app(MapaPoliticoController::class)->updatePersona($request, $keep);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame($drop, $response->getData()->duplicado->id);
        $this->assertSame('Paola Villamizar', $response->getData()->duplicado->nombre);
    }
}
