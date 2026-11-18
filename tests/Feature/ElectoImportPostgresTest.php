<?php

namespace Tests\Feature;

use App\Models\FuncionarioContrato;
use App\Models\FuncionarioEntidad;
use App\Models\Role;
use App\Models\User;
use App\Services\Electo\EventosImporter;
use App\Services\Electo\PersonasImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

// Opt-in checks of electo:importar and geo:cargar-divipola on a LOCAL PostgreSQL database with every migration:
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php -d memory_limit=2G vendor/bin/phpunit --filter=ElectoImportPostgresTest
// Everything runs inside a transaction that is rolled back, against a campaign created by the test. The CSV files are
// written by the test with FICTITIOUS people (never the real Electo export).
class ElectoImportPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const VOTANTES = ['id', 'fkidpadre', 'tipodocumento', 'documento', 'docexp', 'nombres', 'apellidos', 'genero', 'fechanacimiento',
        'direccion', 'fkbarrio', 'fkmunicipio', 'facebook', 'twitter', 'instagram', 'observacion', 'fkprofesion', 'fkconfianza', 'autorizainfo',
        'origindata', 'editado', 'hvtipo', 'hvfechaentregada', 'militante', 'fkconvenio', 'estado', 'verificado', 'directorio', 'lider', 'fallecido',
        'fkdeppuestovot', 'fkmunpuestovot', 'dirpuestovot', 'puestovot', 'mesavot', 'fkestadocivil', 'fkescolaridad', 'funcionario', 'empresario',
        'reservista', 'exfuncionario', 'clavegl', 'granelector', 'invitacion_enviada', 'createdat', 'hvdetalle', 'fkupdated', 'fkcreated',
        'candidatoge', 'updatedat', 'verificado_ia'];

    private const CLAVE = 'CLAVEFICTICIA999';

    private string $dir;

    private string $campaign;

    private array $geo = [];

    private string $existing;

    private string $homonym;

    private string $existingEvent;

    private string $lastReport;

    private string $otherText;

    private string $sameText;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->travelTo('2026-11-18 12:00:00');
        $this->geo = DB::table('geographic_units')->where('type', 'municipality')
            ->whereIn('official_code', ['68001', '68081', '68276', '68307'])->pluck('id', 'official_code')->all();
        $this->assertCount(4, $this->geo, 'La BD local debe tener los municipios DIVIPOLA de Santander.');

        $this->campaign = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $this->campaign, 'name' => 'Grupo prueba Electo', 'slug' => 'electo-test-'.Str::random(6),
            'created_at' => now(), 'updated_at' => now()]);
        $this->existing = $this->ficha(['nombre' => 'JUAN EXISTENTE SEIS', 'cedula' => '07123456', 'fecha_nacimiento' => '1971-02-02',
            'telefono' => '3209999999', 'email' => 'existing@example.com', 'observacion' => 'NUESTRA', 'tipo' => 'directorio']);
        $this->homonym = $this->ficha(['nombre' => 'Pedro Prueba Siete', 'cedula' => null, 'tipo' => 'directorio']);
        $this->otherText = $this->ficha(['nombre' => 'TEXTO OTRO MUNICIPIO', 'cedula' => '1100000011', 'municipio' => 'Bogota', 'geographic_unit_id' => null]);
        $this->sameText = $this->ficha(['nombre' => 'TEXTO MISMO MUNICIPIO', 'cedula' => '1100000012', 'municipio' => 'BUCARAMANGA', 'geographic_unit_id' => null]);
        $this->existingEvent = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $this->existingEvent, 'campaign_id' => $this->campaign, 'event_type_id' => DB::table('event_types')->value('id'),
            'nombre' => 'Audiencia ya cargada', 'starts_at' => '2026-09-18 10:00:00', 'sitio_encuentro' => 'Plaza', 'municipio_id' => $this->geo['68081'],
            'status' => 'cerrado', 'public_token' => Str::random(43), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'electo_test_'.Str::random(8);
        mkdir($this->dir);
        $this->writeFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function ficha(array $data): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert($data + [
            'id' => $id, 'campaign_id' => $this->campaign, 'municipio' => 'Bucaramanga', 'geographic_unit_id' => $this->geo['68001'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function votante(array $data): array
    {
        return $data + [
            'tipodocumento' => '13', 'genero' => '2', 'fechanacimiento' => '1985-01-01', 'fkmunicipio' => '68001', 'fkconfianza' => '1',
            'autorizainfo' => 'true', 'origindata' => '1', 'hvtipo' => '0', 'militante' => 'false', 'estado' => '1', 'verificado' => '1',
            'directorio' => 'false', 'lider' => 'false', 'fallecido' => 'false', 'funcionario' => 'false', 'empresario' => 'false',
            'reservista' => 'false', 'exfuncionario' => 'false', 'granelector' => 'false', 'candidatoge' => 'false',
            'createdat' => '2025-06-01 10:00:00.000', 'fkcreated' => '1',
        ] + array_fill_keys(self::VOTANTES, '');
    }

    private function writeFixtures(): void
    {
        $this->csv('votantes', self::VOTANTES, [
            $this->votante(['id' => '1001', 'fkidpadre' => '1002', 'documento' => ' 00091.234.567 ', 'nombres' => 'ana maría', 'apellidos' => 'prueba uno',
                'genero' => '1', 'fechanacimiento' => '1980-05-05', 'fkprofesion' => '2', 'fkconfianza' => '3', 'fkestadocivil' => '2',
                'fkescolaridad' => '8', 'observacion' => "línea uno\r\nlínea dos", 'clavegl' => self::CLAVE, 'docexp' => '1999-01-01',
                'fkbarrio' => '123', 'fkconvenio' => '1', 'hvtipo' => '0', 'origindata' => '2']),
            $this->votante(['id' => '1002', 'documento' => '1098765432', 'nombres' => 'carlos', 'apellidos' => 'referente dos',
                'fechanacimiento' => '1900-01-01', 'fkmunicipio' => '68276', 'autorizainfo' => 'false', 'lider' => 'true']),
            $this->votante(['id' => '1003', 'documento' => '1100000001', 'nombres' => 'niña', 'apellidos' => 'menor tres', 'fechanacimiento' => '2015-01-01',
                'fkmunicipio' => '68307']),
            $this->votante(['id' => '1004', 'documento' => '1100000002', 'nombres' => 'luis', 'apellidos' => 'fallecido cuatro', 'fallecido' => 'true',
                'observacion' => str_repeat('x', 5000)]),
            $this->votante(['id' => '1005', 'documento' => '1100000003', 'nombres' => 'sin', 'apellidos' => 'municipio cinco', 'fkmunicipio' => '00000']),
            $this->votante(['id' => '1006', 'documento' => '7.123.456', 'nombres' => 'juan', 'apellidos' => 'existente seis', 'fechanacimiento' => '1970-01-01',
                'fkmunicipio' => '68081', 'direccion' => 'CL 1 # 2 - 3', 'observacion' => 'DE ELECTO', 'militante' => 'true']),
            $this->votante(['id' => '1007', 'tipodocumento' => '0', 'documento' => '00012345', 'nombres' => 'PEDRO', 'apellidos' => 'PRUEBA SIETE']),
            $this->votante(['id' => '1008', 'documento' => '91234567', 'nombres' => 'ANA MARIA', 'apellidos' => 'PRUEBA UNO', 'direccion' => 'KR 8 # 8 - 8',
                'genero' => '1', 'fechanacimiento' => '1980-05-05']),
            $this->votante(['id' => '1009', 'documento' => '1100000009', 'nombres' => 'otro', 'apellidos' => 'pais nueve', 'fkmunicipio' => '99999']),
            $this->votante(['id' => '1010', 'documento' => '1100000010', 'nombres' => ' ', 'apellidos' => '']),
            $this->votante(['id' => '1011', 'documento' => '1100000011', 'nombres' => 'texto', 'apellidos' => 'otro municipio']),
            $this->votante(['id' => '1012', 'documento' => '1100000012', 'nombres' => 'texto', 'apellidos' => 'mismo municipio']),
        ]);
        $this->csv('votantecontactos', ['id', 'fkvotante', 'tipocontacto', 'contacto', 'principal', 'whatsapp', 'estado', 'fkcreated', 'fkupdated', 'createdat', 'updatedat'], [
            ['1', '1001', '1', '+57 300 111 2222', 'true', 'true', '1', '', '', '2025-06-01 10:00:00.000', ''],
            ['2', '1001', '1', '3001112222', 'true', 'false', '1', '', '', '', ''],
            ['3', '1001', '3', 'ANA@EXAMPLE.COM', 'true', 'false', '1', '', '', '', ''],
            ['4', '1001', '3', 'no-es-email', 'false', 'false', '1', '', '', '', ''],
            ['5', '1006', '1', '3150000000', 'true', 'true', '1', '', '', '', ''],
            ['6', '1006', '2', '607 634 5678', 'false', 'false', '1', '', '', '', ''],
            ['7', '1999', '1', '3000000000', 'true', 'false', '1', '', '', '', ''],
            ['8', '1006', '3', 'Existing@Example.com', 'true', 'false', '1', '', '', '', ''],
            ['9', '1002', '0', '', 'false', 'false', '1', '', '', '', ''],
            ['10', '1002', '1', '310 222 3333', 'false', 'true', '1', '', '', '', ''],
        ]);
        $this->csv('lideres', ['id', 'fkvotante', 'fkdepto', 'fkmunicipio', 'fkbarrio', 'fechainicial', 'fechafinal', 'detalle', 'estado', 'fkcreado', 'creadoat', 'activo', 'updatedat'], [
            ['1', '1002', '68', '68276', '', '2025-01-01', '', 'COMERCIANTE', '1', '', '', 'true', ''],
            ['2', '1006', '68', '68001', '', '2025-02-01', '2025-12-31', 'LIDER BARRIAL', '0', '', '', 'false', ''],
        ]);
        $this->csv('mapapolitico', ['id', 'fkvotante', 'fkcargopolitico', 'fechainicial', 'fechafinal', 'detalle', 'estado', 'fkdepto', 'fkmunicipio', 'votos', 'tipo', 'fkpartido', 'aliado', 'fkbarrio', 'createdat', 'updatedat', 'fkupdated', 'fkcreated'], [
            ['1', '1002', '7', '2024-01-01', '', 'Concejal 2023', '1', '68', '68276', '120', '1', '1', 'true', '', '2025-01-01 00:00:00.000', '', '', ''],
            ['2', '1001', '14', '', '', '', '0', '68', '68000', '0', '0', '', 'false', '', '', '', '', ''],
            ['3', '1999', '7', '', '', '', '1', '68', '68001', '5', '1', '1', 'false', '', '', '', '', ''],
        ]);
        $this->csv('eventos', ['id', 'fechahorainicial', 'fechahorafinal', 'fkorganiza', 'fkdepto', 'fkmunicipio', 'fkbarrio', 'observacion', 'fkedita', 'editadoat', 'detalle', 'createdat', 'updatedat', 'fkcreated', 'fkupdated', 'fkeventotipo', 'direccion', 'esvirtual', 'linkvirtual', 'googlecalid', 'syncat'], [
            ['501', '2026-09-18 09:00:00.000', '2026-09-18 12:00:00.000', '1002', '68', '68081', '', '', '', '', 'AUDIENCIA PUBLICA', '', '', '', '', '1', 'Plaza', 'false', '', '', ''],
            ['502', '2025-03-10 15:30:00.000 -0500', '2025-03-10 18:00:00.000 -0500', '1002', '68', '68001', '77', "Reunión\ncon líderes", '', '', '', '', '', '', '', '1', '', 'false', '', '', ''],
        ]);
        $this->csv('eventoasistentes', ['id', 'fkvotante', 'estado', 'observacion', 'fkevento', 'createdat', 'updatedat', 'fkcreated', 'fkupdated', 'asiste', 'creado_en_evento', 'fkreferente', 'referente_asignado'], [
            ['9001', '1001', '1', '', '501', '', '', '', '', '1', '', '', ''],
            ['9002', '1001', '1', 'CPS', '502', '', '', '', '', '1', '', '', ''],
            ['9003', '1008', '1', '', '502', '', '', '', '', '1', '', '', ''],
            ['9004', '1006', '1', 'LIDER', '502', '', '', '', '', '1', '', '', ''],
            ['9005', '1001', '1', '', '999', '', '', '', '', '1', '', '', ''],
            ['9006', '1007', '1', '', '502', '', '', '', '', '1', '', '', ''],
            ['9007', '1007', '1', '', '502', '', '', '', '', '1', '', '', ''],
            ['9008', '', '1', '', '502', '', '', '', '', '1', '', '', ''],
        ]);
        $this->csv('funcionarios_entidades', ['id', 'nombre', 'direccion', 'telefono', 'email', 'fkmunicipio', 'estado', 'createdat', 'updatedat', 'fkcreated', 'fkupdated'], [
            ['5', 'ALCALDIA DE PRUEBA', 'CL 35', '6337000', '', '68001', '1', '0001-12-31 19:00:00.000 -0500', '', '', ''],
            ['3', 'FISCALIA DE PRUEBA', '', '', '', '', '1', '', '', '', ''],
        ]);
        $this->csv('funcionarios', ['id', 'fkvotante', 'fkvotanterecomienda', 'fkentidad', 'fechainicio', 'fechafinal', 'fechaterminacion', 'salario', 'otrosi', 'otrosifechafinal', 'contrato', 'observacion', 'estado', 'createdat', 'updatedat', 'fkcreated', 'fkupdated'], [
            ['1', '1001', '', '5', '2026-01-15', '2026-12-31', '', '4500000', '0', '', '1234', 'CPS', '1', '', '', '', ''],
            ['2', '1999', '', '5', '2026-01-15', '', '', '', '0', '', '', '', '1', '', '', '', ''],
        ]);
        $this->csv('profesiones', ['id', 'nombre', 'fkcreated', 'createdat', 'fkupdated', 'updatedat', 'estado'], [['1', 'NO APLICA', '', '', '', '', '1'], ['2', 'ABOGADO', '', '', '', '', '1']]);
        $this->csv('escolaridad', ['id', 'nombre', 'editado', 'fkedita'], [['2', 'SIN DEFINIR', '', ''], ['8', 'PROFESIONAL', '', '']]);
        $this->csv('estadocivil', ['id', 'nombre', 'fkedita', 'estado', 'createdat', 'updatedat'], [['2', 'Casado/a', '', '1', '', ''], ['0', 'No Aplica', '', '1', '', '']]);
        $this->csv('partidos_politicos', ['id', 'nombre'], [['1', 'Centro Democrático'], ['4', 'FIRMAS, LA U, CD']]);
    }

    private function csv(string $table, array $header, array $rows): void
    {
        $handle = fopen($this->dir.DIRECTORY_SEPARATOR."{$table}_202611171100.csv", 'wb');
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_is_list($row) ? $row : array_map(fn ($col) => $row[$col], $header), ',', '"', '');
        }
        fclose($handle);
    }

    private function import(array $options = []): PendingCommand
    {
        $this->lastReport = $this->dir.DIRECTORY_SEPARATOR.'reporte_'.Str::random(8).'.json';

        return $this->artisan('electo:importar', ['carpeta' => $this->dir, '--campana' => $this->campaign, '--lote' => 100,
            '--reporte' => $this->lastReport] + $options);
    }

    private function lider(int $electoId): ?object
    {
        return DB::table('lideres')->where('campaign_id', $this->campaign)->where('origen', 'electo')->where('origen_id', $electoId)->first();
    }

    private function flags(?object $row): array
    {
        return json_decode((string) $row?->migracion_flags, true) ?: [];
    }

    private function snapshot(): array
    {
        return [
            DB::table('lideres')->where('campaign_id', $this->campaign)->count(),
            DB::table('lideres')->where('id', $this->existing)->value('updated_at'),
            DB::table('lider_contactos')->count(), DB::table('lider_info_politica')->count(), DB::table('events')->count(),
            DB::table('event_attendees')->count(), DB::table('data_consents')->count(), DB::table('electo_import_rows')->count(),
            DB::table('funcionario_contratos')->count(), DB::table('consent_texts')->count(), DB::table('event_types')->count(),
        ];
    }

    private function report(): array
    {
        return json_decode(file_get_contents($this->lastReport), true);
    }

    public function test_dry_run_reports_everything_and_leaves_nothing(): void
    {
        $before = $this->snapshot();
        $this->import(['--dry-run' => true])->expectsOutputToContain('DRY-RUN (nada guardado)')->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $report = $this->report();
        $this->assertSame('dry-run (todo revertido)', $report['modo']);
        $this->assertSame(7, $report['conteos']['votante']['creado']);
        $this->assertSame(3, $report['conteos']['votante']['fusionado:existente']);
        $this->assertSame(1, $report['conteos']['votante']['fusionado:cedula_repetida_en_electo']);
        $this->assertSame(1, $report['conteos']['votante']['omitido:sin_nombre']);
        $this->assertSame(['votantes' => ['99999' => 1]], array_intersect_key($report['municipios_no_reconocidos'], ['votantes' => 1]));
        $this->assertStringNotContainsString('PRUEBA', json_encode($report), 'El reporte no lleva nombres.');
        $this->assertStringNotContainsString('91234567', json_encode($report), 'El reporte no lleva cédulas.');
    }

    public function test_it_creates_normalized_fichas_without_the_electo_password(): void
    {
        $this->import()->assertSuccessful();

        $ana = $this->lider(1001);
        $this->assertSame('ANA MARÍA PRUEBA UNO', $ana->nombre);
        $this->assertSame('91234567', $ana->cedula);
        $this->assertSame('CC', $ana->tipo_documento);
        $this->assertSame('Femenino', $ana->genero);
        $this->assertSame('ABOGADO', $ana->profesion);
        $this->assertSame('Casado/a', $ana->estado_civil);
        $this->assertSame('Universitario', $ana->escolaridad);
        $this->assertSame('sin_llamar', $ana->nivel_confianza, 'Sin catálogo de confianza el campo queda vacío.');
        $this->assertNull($ana->barrio);
        $this->assertNull($ana->convenio);
        $this->assertNull($ana->tipo_hoja_vida);
        $this->assertSame("línea uno\nlínea dos", $ana->observacion);
        $this->assertSame('votante', $ana->tipo);
        $this->assertTrue((bool) $ana->is_active);
        $this->assertSame($this->geo['68001'], $ana->geographic_unit_id);
        $flags = $this->flags($ana);
        $this->assertEquals(['nivel_confianza' => '3', 'barrio' => '123', 'convenio' => '1', 'genero' => '1', 'origen_dato' => '2', 'creado_por' => '1'],
            array_intersect_key($flags['electo_codigos'], array_flip(['nivel_confianza', 'barrio', 'convenio', 'genero', 'origen_dato', 'creado_por'])));
        $this->assertArrayNotHasKey('tipo_hoja_vida', $flags['electo_codigos'], 'Código 0 = sin dato.');
        $this->assertEqualsCanonicalizing(['barrio', 'nivel_confianza', 'convenio'], $flags['por_completar']);
        $this->assertTrue($flags['genero_inferido']);
        $this->assertSame(1, DB::table('lideres')->where('campaign_id', $this->campaign)
            ->whereRaw('migracion_flags @> ?::jsonb', ['{"por_completar": ["barrio"]}'])->count(), 'El equipo filtra lo pendiente por campo.');
        $this->assertSame('KR 8 # 8 - 8', $ana->direccion, 'La cédula repetida en Electo llena lo vacío de la misma ficha.');
        $this->assertSame(0, DB::table('lideres')->where('campaign_id', $this->campaign)->whereRaw('row_to_json(lideres.*)::text LIKE ?', ['%'.self::CLAVE.'%'])->count());

        $carlos = $this->lider(1002);
        $this->assertSame('lider', $carlos->tipo);
        $this->assertSame('COMERCIANTE', $carlos->cargo);
        $this->assertNull($carlos->fecha_nacimiento);
        $this->assertSame(['fecha_nacimiento'], $this->flags($carlos)['fecha_invalida']);
        $this->assertSame('1098765432', $ana->referente_documento);
        $this->assertSame('carlos', $ana->referente_nombre);

        $pedro = $this->lider(1007);
        $this->assertNull($pedro->cedula);
        $this->assertTrue($this->flags($pedro)['documento_sin_tipo']);
        $this->assertSame([$this->homonym], $this->flags($pedro)['posible_duplicado']);
        $this->assertSame('Pedro Prueba Siete', DB::table('lideres')->where('id', $this->homonym)->value('nombre'));
        $this->assertSame(0, DB::table('electo_import_rows')->where('entidad', 'votante')->where('origen_id', 1010)->where('accion', '<>', 'omitido')->count());
    }

    public function test_minors_deceased_and_without_municipio_are_inactive_and_flagged(): void
    {
        $this->import()->assertSuccessful();

        $menor = $this->lider(1003);
        $this->assertFalse((bool) $menor->is_active);
        $this->assertTrue($this->flags($menor)['menor']);
        $this->assertFalse((bool) $menor->autoriza_datos, 'Ley 1581 art. 7: no se toma la autorización de Electo para un menor.');
        $this->assertTrue($this->flags($menor)['autorizacion_menor_omitida']);
        $this->assertSame(0, DB::table('data_consents')->where('lider_id', $menor->id)->count());
        $this->assertSame(1, $this->report()['marcas_de_revision']['creado.autorizacion_menor_omitida']);
        $fallecido = $this->lider(1004);
        $this->assertFalse((bool) $fallecido->is_active);
        $this->assertTrue($this->flags($fallecido)['fallecido']);
        $this->assertTrue($this->flags($fallecido)['observacion_recortada']);
        $this->assertSame(4000, mb_strlen($fallecido->observacion));
        foreach ([1005, 1009] as $id) {
            $row = $this->lider($id);
            $this->assertFalse((bool) $row->is_active);
            $this->assertTrue($this->flags($row)['sin_municipio']);
            $this->assertNull($row->geographic_unit_id);
            $this->assertSame(PersonasImporter::SIN_MUNICIPIO, $row->municipio);
        }
    }

    public function test_an_existing_ficha_only_gets_empty_fields_and_keeps_the_contradictions(): void
    {
        $before = DB::table('lideres')->where('id', $this->existing)->first();
        $this->import()->assertSuccessful();
        $after = DB::table('lideres')->where('id', $this->existing)->first();

        $this->assertNull($this->lider(1006), 'No se crea una ficha nueva para quien ya existe por cédula.');
        $this->assertSame($before->nombre, $after->nombre);
        $this->assertSame('07123456', $after->cedula);
        $this->assertSame('1971-02-02', $after->fecha_nacimiento);
        $this->assertSame('NUESTRA', $after->observacion);
        $this->assertSame($this->geo['68001'], $after->geographic_unit_id);
        $this->assertSame('3209999999', $after->telefono);
        $this->assertSame('directorio', $after->tipo);
        $this->assertSame('CL 1 # 2 - 3', $after->direccion);
        $this->assertSame('Masculino', $after->genero);
        $this->assertSame('LIDER BARRIAL', $after->cargo);
        $this->assertTrue((bool) $after->militante);
        $this->assertTrue((bool) $after->autoriza_datos);
        $flags = $this->flags($after);
        $this->assertSame(1006, $flags['electo_id']);
        $this->assertTrue($flags['electo_lider']);
        $this->assertTrue($flags['genero_inferido'], 'El género vacío se llenó con la inferencia.');
        $this->assertTrue($flags['autoriza_datos_desde_electo']);
        $this->assertSame(['nivel_confianza'], $flags['por_completar']);
        $this->assertSame('sin_llamar', $after->nivel_confianza);
        $this->assertSame('1970-01-01', $flags['contradicciones']['fecha_nacimiento']);
        $this->assertSame('Barrancabermeja', $flags['contradicciones']['municipio']);
        $this->assertSame('3150000000', $flags['contradicciones']['telefono']);
        $this->assertArrayNotHasKey('email', $flags['contradicciones'], 'Electo trae el mismo correo: no es contradicción.');
        $this->assertNull($flags['electo_antes']['direccion'], 'Se guarda el valor previo de lo que se llenó.');
        $this->assertNull($flags['electo_antes']['cargo']);
        $this->assertFalse($flags['electo_antes']['autoriza_datos']);
        $this->assertArrayNotHasKey('observacion', $flags['electo_antes']);
        $this->assertSame(2, DB::table('lider_contactos')->where('lider_id', $this->existing)->where('origen', 'electo')->count());

        $report = $this->report();
        $this->assertSame(1, $report['contradicciones_por_campo']['fecha_nacimiento']);
        $this->assertSame(1, $report['contradicciones_por_campo']['telefono']);
        $this->assertSame(11, $report['por_completar']['lideres.nivel_confianza']);
        $this->assertSame(3, $report['conteos']['votante']['fusionado.autoriza_datos_desde_electo']);
        $this->assertSame(1, $report['por_completar']['lideres.barrio']);
    }

    public function test_contacts_keep_whatsapp_one_principal_and_fill_the_ficha(): void
    {
        $this->import()->assertSuccessful();

        $ana = $this->lider(1001);
        $contacts = DB::table('lider_contactos')->where('lider_id', $ana->id)->orderBy('origen_id')->get();
        $this->assertSame(['3001112222', 'ana@example.com'], $contacts->pluck('valor')->all());
        $this->assertTrue((bool) $contacts[0]->whatsapp);
        $this->assertSame(1, $contacts->where('tipo', 'celular')->where('principal', true)->count());
        $this->assertSame('3001112222', $ana->telefono);
        $this->assertSame('ana@example.com', $ana->email);
        $this->assertSame('3102223333', $this->lider(1002)->telefono);
        $report = $this->report();
        $this->assertSame(1, $report['conteos']['contacto']['omitido:email_invalido']);
        $this->assertSame(1, $report['conteos']['contacto']['omitido:repetido']);
        $this->assertSame(1, $report['conteos']['contacto']['omitido:persona_no_importada']);
        $this->assertSame(1, $report['conteos']['contacto']['omitido:vacio']);
    }

    public function test_authorization_from_electo_is_recorded_once_as_weak_evidence(): void
    {
        $this->import()->assertSuccessful();
        $this->import()->assertSuccessful();

        $text = DB::table('consent_texts')->where('campaign_id', $this->campaign)->where('version', PersonasImporter::CONSENT_VERSION)->first();
        $this->assertSame(PersonasImporter::CONSENT_TEXT, $text->cuerpo);
        $this->assertFalse((bool) $text->is_active, 'Nunca es el texto vigente del grupo.');
        $this->assertFalse((bool) $text->approved);
        $consents = DB::table('data_consents')->where('consent_text_id', $text->id)->get();
        $this->assertSame(['migracion_electo'], $consents->pluck('channel')->unique()->values()->all());
        $this->assertCount(1, $consents->where('lider_id', $this->lider(1001)->id));
        $this->assertCount(1, $consents->where('lider_id', $this->existing));
        $this->assertCount(0, $consents->where('lider_id', $this->lider(1002)->id), 'Sin autorización en Electo no hay registro.');
        $this->assertFalse((bool) $this->lider(1002)->autoriza_datos);
    }

    public function test_running_twice_creates_nothing_new(): void
    {
        $this->import()->assertSuccessful();
        $first = $this->snapshot();
        $flags = DB::table('lideres')->where('id', $this->existing)->value('migracion_flags');

        $this->import()->assertSuccessful();

        $second = $this->snapshot();
        unset($first[1], $second[1]);
        $this->assertSame($first, $second);
        $this->assertJsonStringEqualsJsonString($flags, DB::table('lideres')->where('id', $this->existing)->value('migracion_flags'));
        $report = $this->report();
        $this->assertSame(11, $report['conteos']['votante']['ya_importado']);
        $this->assertArrayNotHasKey('creado', $report['conteos']['votante']);
        $this->assertArrayNotHasKey('creado', $report['conteos']['evento_asistente'] ?? []);
    }

    public function test_events_are_closed_and_the_existing_one_is_not_duplicated(): void
    {
        $this->import()->assertSuccessful();

        $this->assertSame(1, DB::table('events')->where('campaign_id', $this->campaign)->whereDate('starts_at', '2026-09-18')->count());
        $this->assertSame(0, DB::table('event_attendees')->where('event_id', $this->existingEvent)->count());
        $event = DB::table('events')->where('origen', 'electo')->where('origen_id', 502)->first();
        $this->assertSame('cerrado', $event->status);
        $this->assertSame('2025-03-10 15:30:00', $event->starts_at);
        $this->assertSame('Evento Electo 2025-03-10 Bucaramanga', $event->nombre);
        $this->assertSame('Sin dato', $event->sitio_encuentro);
        $this->assertEquals(['electo_codigos' => ['barrio' => '77', 'tipo_evento' => '1'], 'por_completar' => ['barrio']], json_decode($event->migracion_flags, true));
        $this->assertSame(EventosImporter::EVENT_TYPE, DB::table('event_types')->where('id', $event->event_type_id)->value('nombre'));
        $this->assertSame($this->lider(1002)->id, DB::table('event_organizers')->where('event_id', $event->id)->value('lider_id'));

        $attendees = DB::table('event_attendees')->where('event_id', $event->id)->orderBy('origen_id')->get();
        $this->assertSame([9002, 9004, 9006], $attendees->pluck('origen_id')->map(fn ($v) => (int) $v)->all());
        $this->assertNull($attendees[2]->cedula, 'Persona sin cédula: se deduplica por ficha.');
        $this->assertSame(['electo'], $attendees->pluck('source')->unique()->values()->all());
        $this->assertSame(['vinculado'], $attendees->pluck('link_status')->unique()->values()->all());
        $this->assertSame('ANA MARÍA', $attendees[0]->nombres);
        $this->assertSame('PRUEBA UNO', $attendees[0]->apellidos);
        $this->assertSame($this->existing, $attendees[1]->lider_id);
        $this->assertSame('CPS', $attendees[0]->nota);
        $counts = $this->report()['conteos']['evento_asistente'];
        $this->assertSame(1, $counts['omitido:evento_existente']);
        $this->assertSame(2, $counts['omitido:asistencia_duplicada']);
        $this->assertSame(1, $counts['omitido:sin_votante']);
        $this->assertSame(1, $counts['omitido:evento_no_importado']);
    }

    public function test_political_map_rows_and_leader_assignments(): void
    {
        $this->import()->assertSuccessful();

        $info = DB::table('lider_info_politica')->where('origen', 'electo')->orderBy('origen_id')->get();
        $this->assertCount(2, $info);
        $this->assertSame('CENTRO DEMOCRÁTICO', $info[0]->partido);
        $this->assertSame('Sí', $info[0]->aliado);
        $this->assertSame(120, $info[0]->votos);
        $this->assertSame('', $info[0]->estado, 'El estado de Electo no se interpreta.');
        $this->assertSame('Floridablanca', $info[0]->municipio);
        $this->assertSame('2024-01-01', $info[0]->periodo_desde);
        $this->assertNull($info[0]->cargo_politico);
        $infoFlags = json_decode($info[0]->migracion_flags, true);
        $this->assertEquals(['cargo_politico' => '7', 'estado' => '1', 'tipo' => '1', 'partido' => '1'], $infoFlags['electo_codigos']);
        $this->assertSame(['cargo_politico', 'estado'], $infoFlags['por_completar']);
        $this->assertSame('', $info[1]->estado);
        $this->assertSame('0', json_decode($info[1]->migracion_flags, true)['electo_codigos']['estado'], 'Estado 0 importado tal cual.');
        $this->assertSame(2, $this->report()['por_completar']['lider_info_politica.estado']);
        $this->assertNull($info[1]->municipio);
        $this->assertSame(['68000' => 1], $this->report()['municipios_no_reconocidos']['mapapolitico']);

        $carlos = $this->lider(1002);
        $influencia = DB::table('lider_municipios_influencia')->where('lider_id', $carlos->id)->first();
        $this->assertSame($this->geo['68276'], $influencia->geographic_unit_id);
        $this->assertStringContainsString('COMERCIANTE', $influencia->nota);
        $this->assertSame('electo', $influencia->origen);
    }

    public function test_contracts_never_store_the_salary_and_only_the_superadmin_sees_them(): void
    {
        $this->import()->assertSuccessful();

        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('funcionario_contratos', 'salario'));
        $this->assertSame(0, DB::table('funcionario_contratos')->whereRaw('row_to_json(funcionario_contratos.*)::text LIKE ?', ['%4500000%'])->count());
        $this->assertStringNotContainsString('4500000', json_encode($this->report()));
        $contract = DB::table('funcionario_contratos')->where('origen', 'electo')->where('origen_id', 1)->first();
        $this->assertSame($this->lider(1001)->id, $contract->lider_id);
        $this->assertSame('1234', $contract->numero_contrato);
        $this->assertSame('ALCALDIA DE PRUEBA', DB::table('funcionario_entidades')->where('id', $contract->entidad_id)->value('nombre'));
        $this->assertSame(1, $this->report()['conteos']['funcionario_contrato']['omitido:persona_no_importada']);

        // CurrentCampaign reads the request's user, as in a real web or agent request.
        $campo = $this->user('R09_CAMPO');
        request()->setUserResolver(fn () => $campo);
        $this->assertSame(0, FuncionarioContrato::where('origen', 'electo')->count());
        $this->assertSame(0, FuncionarioEntidad::where('origen', 'electo')->count());
        $superadmin = $this->user('R01_SUPERADMIN');
        request()->setUserResolver(fn () => $superadmin);
        $this->assertSame(1, FuncionarioContrato::where('origen', 'electo')->count());
        $this->assertSame(2, FuncionarioEntidad::where('origen', 'electo')->count());
    }

    public function test_preflight_stops_when_a_file_is_missing(): void
    {
        unlink($this->dir.DIRECTORY_SEPARATOR.'votantecontactos_202611171100.csv');
        $before = $this->snapshot();

        $this->import(['--solo' => 'contactos'])->expectsOutputToContain('Falta el archivo votantecontactos_*.csv')->assertFailed();
        $this->import(['--solo' => 'personas,nada'])->expectsOutputToContain('Partes desconocidas: nada')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_ficha_without_unit_keeps_its_typed_municipio_unless_it_matches(): void
    {
        $this->import()->assertSuccessful();

        $other = DB::table('lideres')->where('id', $this->otherText)->first();
        $this->assertNull($other->geographic_unit_id);
        $this->assertSame('Bogota', $other->municipio);
        $this->assertSame('Bucaramanga', $this->flags($other)['contradicciones']['municipio']);

        $same = DB::table('lideres')->where('id', $this->sameText)->first();
        $this->assertSame($this->geo['68001'], $same->geographic_unit_id);
        $this->assertSame('Bucaramanga', $same->municipio);
        $this->assertEquals(['geographic_unit_id' => null, 'municipio' => 'BUCARAMANGA'],
            array_intersect_key($this->flags($same)['electo_antes'], ['geographic_unit_id' => 1, 'municipio' => 1]));
    }

    public function test_divipola_loads_the_country_without_touching_santander(): void
    {
        $santander = DB::table('geographic_units')->where('official_code', 'like', '68%')->orderBy('id')->get(['id', 'canonical_name', 'official_code', 'parent_id'])->toJson();
        $country = DB::table('geographic_units')->where('type', 'country')->value('id');
        $cundinamarca = (string) Str::uuid();
        $bogota = (string) Str::uuid();
        DB::table('geographic_units')->insert([
            ['id' => $cundinamarca, 'parent_id' => $country, 'type' => 'department', 'canonical_name' => 'Zz Cundinamarca Test', 'level' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $bogota, 'parent_id' => $cundinamarca, 'type' => 'municipality', 'canonical_name' => 'Bogota', 'level' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('geographic_units')->where('official_code', '11001')->where('id', '<>', $bogota)->update(['official_code' => null]);
        $file = $this->dir.DIRECTORY_SEPARATOR.'divipola.csv';
        file_put_contents($file, implode("\n", [
            '"cod_dpto","dpto","cod_mpio","nom_mpio","tipo_municipio","longitud","latitud"',
            '"68","SANTANDER","68001","BUCARAMANGA","Municipio","",""',
            '"68","SANTANDER","68020","ALBANIA","Municipio","",""',
            '"11","BOGOTÁ, D.C.","11001","BOGOTÁ, D.C.","Municipio","",""',
            '"97","ZZ DEPARTAMENTO TEST","97901","ALBANIA","Municipio","",""',
            '"97","ZZ DEPARTAMENTO TEST","97902","SAN ZQX DEL TEST","Isla","",""',
            '"95","ZZ NORTE TEST","95245","EL CARMEN","Municipio","",""',
            '"96","ZZ CUNDINAMARCA TEST","96001","ZQX UNO","Municipio","",""',
        ]));
        // The file needs at least 10 rows: pad with unique fictitious municipios.
        for ($i = 10; $i < 18; $i++) {
            file_put_contents($file, "\n\"96\",\"ZZ CUNDINAMARCA TEST\",\"960{$i}\",\"ZQX RELLENO {$i}\",\"Municipio\",\"\",\"\"", FILE_APPEND);
        }
        DB::table('geographic_units')->whereIn('official_code', ['97', '96', '95'])->update(['official_code' => null]);

        $this->artisan('geo:cargar-divipola', ['archivo' => $file, '--dry-run' => true])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertNull(DB::table('geographic_units')->where('id', $bogota)->value('official_code'));

        $this->artisan('geo:cargar-divipola', ['archivo' => $file])->assertSuccessful();
        $this->assertSame($santander, DB::table('geographic_units')->where('official_code', 'like', '68%')->orderBy('id')->get(['id', 'canonical_name', 'official_code', 'parent_id'])->toJson());
        $this->assertSame('11001', DB::table('geographic_units')->where('id', $bogota)->value('official_code'));
        $this->assertSame($cundinamarca, DB::table('geographic_units')->where('id', $bogota)->value('parent_id'), 'No se mueve lo creado a mano.');
        $this->assertSame('96', DB::table('geographic_units')->where('id', $cundinamarca)->value('official_code'));
        $this->assertSame('Albania (Zz Departamento Test)', DB::table('geographic_units')->where('official_code', '97901')->value('canonical_name'));
        $this->assertSame('El Carmen (Zz Norte Test)', DB::table('geographic_units')->where('official_code', '95245')->value('canonical_name'),
            'Único en el archivo, pero se llama como un municipio de Santander (alias de El Carmen de Chucurí).');
        $this->assertSame('San Zqx del Test', DB::table('geographic_units')->where('official_code', '97902')->value('canonical_name'));
        $dept = DB::table('geographic_units')->where('type', 'department')->where('official_code', '97')->first();
        $this->assertSame($dept->id, DB::table('geographic_units')->where('official_code', '97902')->value('parent_id'));

        $count = DB::table('geographic_units')->count();
        $this->artisan('geo:cargar-divipola', ['archivo' => $file])->assertSuccessful();
        $this->assertSame($count, DB::table('geographic_units')->count(), 'Correrlo otra vez no crea nada.');
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Electo PG', 'email' => 'electo-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', $role)->value('id'), 'permissions' => [],
        ]);
        $user->forceFill(['campaign_id' => $this->campaign])->save();

        return $user;
    }
}
