<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Eventos\AttendeeMatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: the lookups that used to read every ficha of a municipio now read only the candidates in PostgreSQL
// (same cédula, same phone, two words of the name): they must still find the same people.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=ScaleLookupsPostgresTest
class ScaleLookupsPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $mun;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => 'Municipio Escala PG']);
    }

    private function lider(string $nombre, ?string $cedula = null, ?string $telefono = null): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun, 'nombre' => $nombre,
            'municipio' => 'Municipio Escala PG', 'cedula' => $cedula, 'telefono' => $telefono, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_possible_duplicates_still_finds_each_kind_among_many_fichas(): void
    {
        $user = User::create([
            'name' => 'Escala PG', 'email' => 'escala-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'territory_scope' => [$this->mun], 'permissions' => ['view.mapa', 'view.votantes', 'write.data'],
        ]);
        $user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $ana = $this->lider('Zzesc Carlos Andrés Peñaranda Gómez', '91234567', '300 123 4567');
        $this->lider('Zzesc C A Penaranda', '91.234.567');
        $this->lider('Zzesc Carlos', null, '+57 3001234567');
        $this->lider('Zzesc Carlos Peñaranda Gómez');
        $this->lider('Zzesc Andres Penaranda', '91234576');
        $this->lider('Zzesc Maria Lopez', '55555555', '3105555555');
        foreach (range(1, 300) as $i) {
            $this->lider("Zzesc Relleno {$i}", (string) (60000000 + $i), '31'.str_pad((string) $i, 8, '0', STR_PAD_LEFT));
        }

        $found = collect($this->actingAs($user)->getJson("/mapa-politico/persona/{$ana}/posibles-duplicados")->assertOk()->json('data'))
            ->mapWithKeys(fn ($d) => [$d['nombre'] => $d['motivo']])->all();

        $this->assertEqualsCanonicalizing([
            'Zzesc C A Penaranda' => 'Misma cédula',
            'Zzesc Carlos' => 'Mismo teléfono',
            'Zzesc Carlos Peñaranda Gómez' => 'Nombre parecido',
            'Zzesc Andres Penaranda' => 'Cédula casi igual',
        ], $found);
    }

    public function test_attendee_suggestion_finds_the_phone_in_the_ficha_or_in_a_contact(): void
    {
        $porFicha = $this->lider('Zzesc Beatriz Rangel', null, '+57 300 765 4321 / 310 000 0000');
        $porContacto = $this->lider('Zzesc Hugo Prada');
        DB::table('lider_contactos')->insert([
            'id' => (string) Str::uuid(), 'lider_id' => $porContacto, 'tipo' => 'celular', 'valor' => '311-222-3344',
            'principal' => true, 'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lider('Zzesc Beatriz Otra', null, '3009999999');

        $result = app(AttendeeMatcher::class)->suggest(Campaign::DEFAULT_ID, [
            'a' => ['phones' => ['3000000000', '3100000000'], 'tokens' => ['beatriz'], 'geo' => [$this->mun], 'cedula' => null],
            'b' => ['phones' => ['3112223344'], 'tokens' => ['hugo'], 'geo' => [$this->mun], 'cedula' => null],
            'c' => ['phones' => ['3201112233'], 'tokens' => ['beatriz'], 'geo' => [$this->mun], 'cedula' => null],
        ]);

        $this->assertSame(['a' => $porFicha, 'b' => $porContacto], $result);
    }
}
