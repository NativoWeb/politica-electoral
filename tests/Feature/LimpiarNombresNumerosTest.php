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

    public function test_the_second_pass_strips_digits_and_urls_and_inactivates_names_with_nothing_left(): void
    {
        $casos = [
            'otro' => $this->ficha('JORGE MORA 7654321', '1098765431'),
            'corto' => $this->ficha('ANA MARIA1', null),
            'medio' => $this->ficha('RAUL 1098765432 VEGA', '1098765432'),
            'vacio' => $this->ficha('1098765433', '1098765433'),
            'solo' => $this->ficha('15', null),
            'quedan' => $this->ficha('LINA 2 SOSA 3001234567', null),
            'url' => $this->ficha('LUZ DARY PINTO http://wa.link.co.x:8080', null),
            'letra' => $this->ficha('P3REZ', null),
        ];

        $salida = $this->correr();

        $this->assertSame('JORGE MORA', $this->nombre($casos['otro']));
        $this->assertSame('1098765431', DB::table('lideres')->where('id', $casos['otro'])->value('cedula'), 'The other number goes to no field.');
        $this->assertSame('JORGE MORA 7654321', DB::table('limpieza_cambios')->where('lider_id', $casos['otro'])->where('campo', 'nombre')->value('antes'),
            'The removed number is kept in the log.');
        $this->assertSame('ANA MARIA', $this->nombre($casos['corto']));
        $this->assertSame('RAUL VEGA', $this->nombre($casos['medio']));
        $this->assertSame('1098765432', DB::table('lideres')->where('id', $casos['medio'])->value('cedula'));
        $this->assertSame('LINA SOSA', $this->nombre($casos['quedan']));
        $this->assertSame('3001234567', DB::table('lideres')->where('id', $casos['quedan'])->value('telefono'), 'A celular in the second pass is kept too.');
        $this->assertSame('LUZ DARY PINTO', $this->nombre($casos['url']));
        // Accepted on purpose: a digit inside a word is dropped and the word stays («PREZ»); the old name is in the log.
        $this->assertSame('PREZ', $this->nombre($casos['letra']));

        foreach (['vacio', 'solo'] as $caso) {
            $fila = DB::table('lideres')->where('id', $casos[$caso])->first();
            $this->assertFalse((bool) $fila->is_active, "{$caso}: inactivated, not deleted");
        }
        $this->assertSame('15', $this->nombre($casos['solo']));
        $this->assertSame(2, DB::table('limpieza_cambios')->where('campo', 'is_active')->count());

        $this->assertStringContainsString(LimpiarNombresNumeros::INACTIVADA, $salida);
        $this->assertMatchesRegularExpression('/nombre depurado \(quedan letras\)\s*\|\s*6\s*\|/', $salida);
        $this->assertMatchesRegularExpression('/'.LimpiarNombresNumeros::INACTIVADA.'\s*\|\s*2\s*\|/', $salida);
        foreach ($casos as $id) {
            $this->assertStringContainsString($id, $salida);
        }
        foreach (['JORGE', 'MARIA', 'RAUL', 'SOSA', 'LUZ', 'PREZ', '7654321', '1098765432', '1098765433', '3001234567', 'wa.link'] as $dato) {
            $this->assertStringNotContainsString($dato, $salida);
        }
        $this->assertStringNotContainsString('No se tocó', $salida);

        $audit = json_decode(DB::table('audit_logs')->where('action', 'data.limpiar_nombres_numeros')->value('metadata'), true)['resumen'];
        $this->assertSame([6, 2], [$audit['nombre_depurado'], $audit['inactivada']]);
        $this->assertSame([1, 1, 1], [$audit['numero_largo_quitado'], $audit['cedula_quitada'], $audit['celular_a_telefono']]);
    }

    public function test_the_second_pass_keeps_a_celular_in_the_middle_and_drops_the_labels(): void
    {
        $contacto = $this->ficha('LUZ CEL 3107654321 MARINA 2', null, '3159998888');
        $repetido = $this->ficha('ANA TEL: 315 999 8888 RUIZ 4', null, '3159998888');
        $cedula = $this->ficha('MARIA C.C 63.525.141 GOMEZ 7', '63525141');
        $rotulo = $this->ficha('JUAN PEREZ CC 12', null);
        $parentesis = $this->ficha('LUZ-MARINA (CEL 3', null);

        $this->correr();

        $this->assertSame('LUZ MARINA', $this->nombre($contacto));
        $this->assertSame('3159998888', DB::table('lideres')->where('id', $contacto)->value('telefono'));
        $this->assertSame(['3107654321'], DB::table('lider_contactos')->where('lider_id', $contacto)->pluck('valor')->all());
        $this->assertSame('ANA RUIZ', $this->nombre($repetido));
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $repetido)->count(), 'Already its telefono.');
        $this->assertSame('MARIA GOMEZ', $this->nombre($cedula));
        $this->assertSame('JUAN PEREZ', $this->nombre($rotulo));
        $this->assertSame('LUZ-MARINA', $this->nombre($parentesis));

        $this->correr(['--revertir' => $this->lote()]);
        $this->assertSame('LUZ CEL 3107654321 MARINA 2', $this->nombre($contacto));
        $this->assertSame(0, DB::table('lider_contactos')->where('lider_id', $contacto)->count());
    }

    public function test_revertir_restores_the_second_pass_name_and_is_active(): void
    {
        $depurada = $this->ficha('ANA MARIA1', null);
        $inactiva = $this->ficha('15', null);
        $yaInactiva = $this->ficha('16', null, null, ['is_active' => false]);

        $this->correr();
        $this->assertSame(1, DB::table('limpieza_cambios')->where('campo', 'is_active')->count(), 'An already inactive ficha is not logged.');
        $this->correr(['--revertir' => $this->lote()]);

        $this->assertSame('ANA MARIA1', $this->nombre($depurada));
        $this->assertTrue((bool) DB::table('lideres')->where('id', $inactiva)->value('is_active'));
        $this->assertFalse((bool) DB::table('lideres')->where('id', $yaInactiva)->value('is_active'));
    }

    public function test_dry_run_reports_the_second_pass_without_writing(): void
    {
        $this->ficha('ANA MARIA1', null);
        $this->ficha('15', null);
        $antes = DB::table('lideres')->orderBy('id')->get();

        $salida = $this->correr(['--dry-run' => true]);

        $this->assertEquals($antes, DB::table('lideres')->orderBy('id')->get());
        $this->assertSame(0, DB::table('limpieza_cambios')->count());
        $this->assertMatchesRegularExpression('/nombre depurado \(quedan letras\)\s*\|\s*1\s*\|/', $salida);
        $this->assertMatchesRegularExpression('/'.LimpiarNombresNumeros::INACTIVADA.'\s*\|\s*1\s*\|/', $salida);
    }

    public function test_depurar(): void
    {
        $this->assertSame('ANA MARIA', LimpiarNombresNumeros::depurar('ANA MARIA1'));
        $this->assertSame('PREZ', LimpiarNombresNumeros::depurar('P3REZ'));
        $this->assertSame('CECILIA (LA MONA)', LimpiarNombresNumeros::depurar('CECILIA (LA MONA) 2'));
        $this->assertSame('LUZ-MARINA', LimpiarNombresNumeros::depurar('LUZ-MARINA 5 (CEL)'));
        $this->assertSame('MA.ELENA ROJAS', LimpiarNombresNumeros::depurar('MA.ELENA ROJAS 12'), 'A dotted name is not a domain.');
        $this->assertSame('ANA', LimpiarNombresNumeros::depurar('ANA www.ana.com ana@correo.co'));
        $this->assertSame('ANA PEREZ', LimpiarNombresNumeros::depurar('ANA # 12 : PEREZ / 3'));
        $this->assertNull(LimpiarNombresNumeros::depurar('15 - 3'));
        $this->assertNull(LimpiarNombresNumeros::depurar('A 15'), 'One letter is not a name.');
        foreach (['JUAN PEREZ CC' => 'JUAN PEREZ', 'ANA TEL: RUIZ' => 'ANA RUIZ', 'MARIA C.C' => 'MARIA', 'LUZ CEL' => 'LUZ',
            'LUZ-MARINA (CEL' => 'LUZ-MARINA', 'ROSA CÉDULA: 12 PAEZ' => 'ROSA PAEZ', 'MARCEL TELLO (2)' => 'MARCEL TELLO'] as $in => $out) {
            $this->assertSame($out, LimpiarNombresNumeros::depurar($in), $in);
        }
        $this->assertNull(LimpiarNombresNumeros::depurar('CEL 300'), 'Only a label: nothing left.');
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
