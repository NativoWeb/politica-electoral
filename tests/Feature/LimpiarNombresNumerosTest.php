<?php

namespace Tests\Feature;

use App\Console\Commands\LimpiarNombresNumeros;
use App\Models\Campaign;
use App\Support\MigracionFlags;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\BuildsEventosScenario;
use Tests\TestCase;

// data:limpiar-nombres-numeros: the ficha's own cédula and a celular leave the name (the celular goes to Teléfono or to
// the contacts), everything else is only reported by id and shape; dry-run, idempotency and --revertir. SQLite,
// fictitious data; each test in its own process (MigracionFlags caches whether its column exists).
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class LimpiarNombresNumerosTest extends TestCase
{
    use BuildsEventosScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEventos();
        Schema::table('lideres', fn (Blueprint $t) => $t->json('migracion_flags')->nullable());
        (fn () => self::$hasColumn = null)->bindTo(null, MigracionFlags::class)();
        (require database_path('migrations/2026_11_25_000001_create_limpieza_cambios_table.php'))->up();
        Schema::table('persons', function (Blueprint $t) {
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });
    }

    private function ficha(string $nombre, ?string $cedula = null, ?string $telefono = null, array $extra = []): string
    {
        return $this->lider(Campaign::DEFAULT_ID, $nombre, $cedula, ['telefono' => $telefono, ...$extra]);
    }

    private function nombre(string $id): string
    {
        return DB::table('lideres')->where('id', $id)->value('nombre');
    }

    private function correr(array $opciones = []): string
    {
        $this->assertSame(0, Artisan::call('data:limpiar-nombres-numeros', $opciones));

        return Artisan::output();
    }

    private function lote(): string
    {
        return DB::table('limpieza_cambios')->value('lote');
    }

    public function test_the_fichas_own_cedula_leaves_the_name_at_the_start_or_the_end_even_with_dots(): void
    {
        $inicio = $this->ficha('1098765432 JUAN  PEREZ', '1098765432');
        $final = $this->ficha('MARIA GOMEZ - 1.098.111.222', '1098111222');
        $conPuntos = $this->ficha('ANA RUIZ CC 63525141', '63.525.141');

        $salida = $this->correr();

        $this->assertSame('JUAN PEREZ', $this->nombre($inicio));
        $this->assertSame('MARIA GOMEZ', $this->nombre($final));
        $this->assertSame('ANA RUIZ', $this->nombre($conPuntos));
        $this->assertSame('63.525.141', DB::table('lideres')->where('id', $conPuntos)->value('cedula'), 'The cédula itself is not touched.');
        $this->assertSame(3, DB::table('limpieza_cambios')->where('campo', 'nombre')->count());
        $this->assertStringContainsString('Lote:', $salida);

        $audit = DB::table('audit_logs')->where('action', 'data.limpiar_nombres_numeros')->first();
        $this->assertNotNull($audit);
        $this->assertSame(3, json_decode($audit->metadata, true)['resumen']['cedula_quitada']);
        $this->assertStringNotContainsString('PEREZ', $audit->metadata);
        $this->assertStringNotContainsString('1098765432', $audit->metadata);
    }

    public function test_a_celular_goes_to_telefono_or_to_the_contacts_without_duplicates(): void
    {
        $sinTelefono = $this->ficha('PEDRO DIAZ 3001234567');
        $otroTelefono = $this->ficha('LUISA PARRA 310 765 4321', null, '3159998888');
        $yaEnContactos = $this->ficha('CARLOS RIOS CEL 3201112233', null, '3159998888');
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $yaEnContactos, 'tipo' => 'whatsapp', 'valor' => '320 111 2233']);
        $mismoTelefono = $this->ficha('3009990000 SOFIA LARA', null, '300 999 0000');

        $this->correr();

        $this->assertSame('PEDRO DIAZ', $this->nombre($sinTelefono));
        $this->assertSame('3001234567', DB::table('lideres')->where('id', $sinTelefono)->value('telefono'));
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $sinTelefono)->count());

        $this->assertSame('LUISA PARRA', $this->nombre($otroTelefono));
        $this->assertSame('3159998888', DB::table('lideres')->where('id', $otroTelefono)->value('telefono'));
        $contacto = DB::table('lider_contactos')->where('lider_id', $otroTelefono)->first();
        $this->assertSame(['celular', '3107654321', 0, 'activo'], [$contacto->tipo, $contacto->valor, (int) $contacto->principal, $contacto->estado]);

        $this->assertSame('CARLOS RIOS', $this->nombre($yaEnContactos));
        $this->assertSame(1, DB::table('lider_contactos')->where('lider_id', $yaEnContactos)->count(), 'The celular was already a contact.');

        $this->assertSame('SOFIA LARA', $this->nombre($mismoTelefono));
        $this->assertSame('300 999 0000', DB::table('lideres')->where('id', $mismoTelefono)->value('telefono'));
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $mismoTelefono)->count());
    }

    public function test_everything_else_is_left_as_it_is_and_listed_without_personal_data(): void
    {
        $casos = [
            'otro' => $this->ficha('JORGE MORA 7654321', '1098765431'),
            'corto' => $this->ficha('ELENA PRADA2', null),
            'medio' => $this->ficha('RAUL 1098765432 VEGA', '1098765432'),
            'vacio' => $this->ficha('1098765433', '1098765433'),
            'solo' => $this->ficha('15', null),
            'quedan' => $this->ficha('LINA 2 SOSA 3001234567', null),
        ];
        $antes = DB::table('lideres')->orderBy('id')->get();

        $salida = $this->correr();

        $this->assertEquals($antes, DB::table('lideres')->orderBy('id')->get());
        $this->assertSame(0, DB::table('limpieza_cambios')->count());
        $this->assertSame(0, DB::table('audit_logs')->count(), 'No change, no batch: nothing to audit.');
        $this->assertStringContainsString('Sin cambios: no se creó lote.', $salida);
        foreach ($casos as $id) {
            $this->assertStringContainsString($id, $salida);
        }
        foreach (array_values(LimpiarNombresNumeros::MOTIVOS) as $motivo) {
            $this->assertStringContainsString($motivo, $salida);
        }
        $this->assertStringContainsString('A A9', $salida);
        foreach (['JORGE', 'ELENA', 'RAUL', 'SOSA', '7654321', '1098765432', '1098765433', '3001234567'] as $dato) {
            $this->assertStringNotContainsString($dato, $salida);
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $id = $this->ficha('PEDRO DIAZ 3001234567', null, '3159998888');
        $this->ficha('1098765432 JUAN PEREZ', '1098765432');
        $antes = DB::table('lideres')->orderBy('id')->get();

        $salida = $this->correr(['--dry-run' => true]);

        $this->assertEquals($antes, DB::table('lideres')->orderBy('id')->get());
        $this->assertSame(0, DB::table('lider_contactos')->count());
        $this->assertSame(0, DB::table('limpieza_cambios')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertStringContainsString('DRY-RUN', $salida);
        $this->assertSame('PEDRO DIAZ 3001234567', $this->nombre($id));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->ficha('PEDRO DIAZ 3001234567', null, '3159998888');
        $this->ficha('1098765432 JUAN PEREZ', '1098765432');
        $this->ficha('ELENA PRADA2', null);
        $this->correr();
        $estado = [DB::table('lideres')->orderBy('id')->get(), DB::table('lider_contactos')->orderBy('id')->get(), DB::table('limpieza_cambios')->count()];

        $salida = $this->correr();

        $this->assertEquals($estado, [DB::table('lideres')->orderBy('id')->get(), DB::table('lider_contactos')->orderBy('id')->get(), DB::table('limpieza_cambios')->count()]);
        $this->assertStringContainsString('Sin cambios: no se creó lote.', $salida);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_revertir_restores_only_what_still_has_the_written_value(): void
    {
        $telefono = $this->ficha('PEDRO DIAZ 3001234567');
        $contacto = $this->ficha('LUISA PARRA 3107654321', null, '3159998888');
        $editada = $this->ficha('1098765432 JUAN PEREZ', '1098765432');
        $this->correr();
        $lote = $this->lote();
        // Someone fixed this name by hand after the cleaning: the revert leaves it.
        DB::table('lideres')->where('id', $editada)->update(['nombre' => 'JUAN PEREZ GOMEZ']);

        $dry = $this->correr(['--revertir' => $lote, '--dry-run' => true]);
        $this->assertStringContainsString('DRY-RUN', $dry);
        $this->assertSame('PEDRO DIAZ', $this->nombre($telefono));

        $this->correr(['--revertir' => $lote]);

        $this->assertSame('PEDRO DIAZ 3001234567', $this->nombre($telefono));
        $this->assertNull(DB::table('lideres')->where('id', $telefono)->value('telefono'));
        $this->assertSame('LUISA PARRA 3107654321', $this->nombre($contacto));
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $contacto)->count());
        $this->assertSame('JUAN PEREZ GOMEZ', $this->nombre($editada));

        $audit = DB::table('audit_logs')->where('action', 'data.revertir_limpieza_nombres')->first();
        $resumen = json_decode($audit->metadata, true)['resumen'];
        $this->assertSame(1, $resumen['omitidos']);
        $this->assertGreaterThanOrEqual(4, $resumen['restaurados']);

        // Reverting twice changes nothing more.
        $this->correr(['--revertir' => $lote]);
        $this->assertSame('PEDRO DIAZ 3001234567', $this->nombre($telefono));
    }

    public function test_revertir_rejects_an_unknown_batch(): void
    {
        $this->assertSame(1, Artisan::call('data:limpiar-nombres-numeros', ['--revertir' => 'no-es-uuid']));
        $this->assertSame(1, Artisan::call('data:limpiar-nombres-numeros', ['--revertir' => (string) Str::uuid()]));
    }

    public function test_the_linked_electoral_person_is_renamed_and_restored(): void
    {
        $id = $this->ficha('1098765432 JUAN PEREZ', '1098765432');
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => '1098765432 JUAN PEREZ', 'normalized_name' => 'x']);
        $event = (string) Str::uuid();
        DB::table('electoral_events')->insert(['id' => $event, 'name' => 'Locales 2023', 'election_date' => '2023-10-29']);
        $contest = (string) Str::uuid();
        DB::table('contests')->insert(['id' => $contest, 'electoral_event_id' => $event, 'geographic_unit_id' => $this->giron, 'name' => 'Concejo Girón']);
        DB::table('candidacies')->insert(['id' => (string) Str::uuid(), 'person_id' => $person, 'contest_id' => $contest]);

        $this->assertMatchesRegularExpression('/Persona electoral vinculada renombrada\s*\|\s*1\s*\|/', $this->correr(['--dry-run' => true]));
        $this->assertSame('1098765432 JUAN PEREZ', DB::table('persons')->where('id', $person)->value('full_name'));

        $this->correr();

        $this->assertSame('JUAN PEREZ', $this->nombre($id));
        $this->assertSame('JUAN PEREZ', DB::table('persons')->where('id', $person)->value('full_name'));

        $this->correr(['--revertir' => $this->lote()]);

        $this->assertSame('1098765432 JUAN PEREZ', $this->nombre($id));
        $this->assertSame('1098765432 JUAN PEREZ', DB::table('persons')->where('id', $person)->value('full_name'));
    }

    public function test_the_pending_telefono_of_the_ficha_is_completed_and_restored(): void
    {
        $id = $this->ficha('PEDRO DIAZ 3001234567', null, null, ['migracion_flags' => json_encode(['por_completar' => ['telefono']])]);

        $this->correr();
        $this->assertNull(DB::table('lideres')->where('id', $id)->value('migracion_flags'));

        $this->correr(['--revertir' => $this->lote()]);
        $this->assertSame(['por_completar' => ['telefono']], json_decode(DB::table('lideres')->where('id', $id)->value('migracion_flags'), true));
    }

    public function test_clasificar_reads_the_number_at_each_end(): void
    {
        $c = fn (string $n, ?string $cedula = null) => LimpiarNombresNumeros::clasificar($n, $cedula);

        $this->assertSame('JUAN PEREZ', $c('C.C. 1.098.765.432 JUAN PEREZ', '1098765432')['nombre']);
        $this->assertSame('ANA MARCEL', $c('ANA MARCEL 3001234567')['nombre'], 'CEL inside a word is not a label.');
        $this->assertSame('ANA RUIZ', $c('ANA RUIZ (+57 300 123 4567)')['nombre']);
        $this->assertSame([['tipo' => 'cedula', 'valor' => '1098765432'], ['tipo' => 'celular', 'valor' => '3001234567']],
            $c('1098765432 JUAN PEREZ 3001234567', '1098765432')['numeros']);
        $this->assertSame('otro_numero', $c('JUAN PEREZ 1098765432', '63525141')['motivo'], 'Not his cédula and not a celular.');
        $this->assertSame('digitos_cortos', $c('JUAN PEREZ 123')['motivo']);
        $this->assertSame('solo_numeros', $c('15')['motivo']);
        $this->assertSame('quedaria_vacio', $c('3001234567')['motivo']);
    }
}
