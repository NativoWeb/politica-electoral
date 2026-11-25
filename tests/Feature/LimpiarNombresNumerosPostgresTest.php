<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Support\NombreFicha;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in checks of data:limpiar-nombres-numeros on a LOCAL PostgreSQL database (migrated): the read-only dry-run,
// a real run and its revert over synthetic fichas, and the «Nombre con números» clause against a PostgreSQL regex.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=LimpiarNombresNumerosPostgresTest
// Everything runs inside a transaction that is rolled back.
class LimpiarNombresNumerosPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        if (! Schema::hasTable('limpieza_cambios')) {
            $this->markTestSkipped('The local database lacks limpieza_cambios: run php artisan migrate first.');
        }
        $this->municipio = DB::table('geographic_units')->where('type', 'municipality')->value('id');
    }

    private function ficha(string $nombre, ?string $cedula = null, ?string $telefono = null): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => $telefono,
            'municipio' => 'Prueba', 'provincia' => '', 'geographic_unit_id' => $this->municipio, 'cargo' => '', 'tipo' => 'votante',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_dry_run_real_run_and_revert_on_postgresql(): void
    {
        $cedula = '19'.random_int(10000000, 99999999);
        $conCedula = $this->ficha("PGLIMPIA ANA {$cedula}", $cedula);
        $conCelular = $this->ficha('PGLIMPIA BETO 3001234567', null, '3159998888');
        $corto = $this->ficha('PGLIMPIA CIRO2');
        $solo = $this->ficha('9'.random_int(10, 99));

        $this->assertSame(0, Artisan::call('data:limpiar-nombres-numeros', ['--dry-run' => true]));
        $this->assertStringContainsString('DRY-RUN', Artisan::output());
        $this->assertSame("PGLIMPIA ANA {$cedula}", DB::table('lideres')->where('id', $conCedula)->value('nombre'));

        $this->assertSame(0, Artisan::call('data:limpiar-nombres-numeros'));
        $salida = Artisan::output();
        $this->assertStringContainsString($corto, $salida);
        $this->assertStringNotContainsString('PGLIMPIA', $salida);
        $this->assertSame('PGLIMPIA ANA', DB::table('lideres')->where('id', $conCedula)->value('nombre'));
        $this->assertSame('PGLIMPIA BETO', DB::table('lideres')->where('id', $conCelular)->value('nombre'));
        $this->assertSame('3001234567', DB::table('lider_contactos')->where('lider_id', $conCelular)->value('valor'));
        $this->assertSame('PGLIMPIA CIRO', DB::table('lideres')->where('id', $corto)->value('nombre'));
        $this->assertFalse((bool) DB::table('lideres')->where('id', $solo)->value('is_active'));

        $lote = DB::table('limpieza_cambios')->where('lider_id', $conCedula)->value('lote');
        $this->assertSame(0, Artisan::call('data:limpiar-nombres-numeros', ['--revertir' => $lote]));
        $this->assertSame("PGLIMPIA ANA {$cedula}", DB::table('lideres')->where('id', $conCedula)->value('nombre'));
        $this->assertSame('PGLIMPIA BETO 3001234567', DB::table('lideres')->where('id', $conCelular)->value('nombre'));
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $conCelular)->count());
        $this->assertSame('PGLIMPIA CIRO2', DB::table('lideres')->where('id', $corto)->value('nombre'));
        $this->assertTrue((bool) DB::table('lideres')->where('id', $solo)->value('is_active'));
    }

    public function test_the_like_clause_matches_the_postgresql_regex(): void
    {
        $this->ficha('PGLIMPIA SIN DIGITOS');
        $this->ficha('PGLIMPIA 0 CERO');
        $this->ficha('PGLIMPIA NUEVE9');

        $like = NombreFicha::whereConNumeros(DB::table('lideres')->where('nombre', 'like', 'PGLIMPIA%'))->count();
        $regex = DB::table('lideres')->where('nombre', 'like', 'PGLIMPIA%')->whereRaw("nombre ~ '[0-9]'")->count();

        $this->assertSame(2, $like);
        $this->assertSame($regex, $like);
        $this->assertSame(
            DB::table('lideres')->whereRaw("nombre ~ '[0-9]'")->count(),
            NombreFicha::whereConNumeros(DB::table('lideres'))->count(),
            'The same fichas over the whole local table.',
        );
    }
}
