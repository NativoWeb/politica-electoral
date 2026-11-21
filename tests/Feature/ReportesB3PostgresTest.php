<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Reportes\Fuentes\AsistenciasFuente;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

// Reportes B-3 on the real schema (timestamps, ilike, unaccent, comunas, the screens they must agree with). Synthetic
// fixtures in their own grupos políticos and municipios, so whatever the local database holds is never counted.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=ReportesB3PostgresTest
class ReportesB3PostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $grupo;

    private string $otro;

    private string $giron;

    private string $buca;

    private string $comuna;

    private string $tipo;

    private User $admin;

    private User $sinDatos;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
        $this->withoutVite();
        PersonasFuente::olvidarEsquema();
        AsistenciasFuente::olvidarEsquema();

        $this->grupo = $this->campaign('Grupo B3');
        $this->otro = $this->campaign('Otro B3');
        $prov = $this->geo('province', 'Provincia B3');
        $this->giron = $this->geo('municipality', 'Girón B3', $prov);
        $this->buca = $this->geo('municipality', 'Bucaramanga B3', $prov);
        $this->comuna = $this->geo('comuna', 'Comuna B3', $this->buca);
        $this->tipo = (string) Str::uuid();
        DB::table('event_types')->insert(['id' => $this->tipo, 'campaign_id' => $this->grupo, 'nombre' => 'Tipo B3', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);

        $perms = ['view.reportes', 'view.eventos', 'view.empresarios', 'view.votantes'];
        $this->admin = $this->user($this->grupo, [...$perms, 'export.datos_personales']);
        $this->sinDatos = $this->user($this->grupo, $perms);
    }

    private function campaign(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent]);

        return $id;
    }

    private function user(string $campaign, array $permissions): User
    {
        $user = User::create(['name' => 'Usuaria '.Str::random(4), 'email' => 'b3-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'), 'permissions' => $permissions]);
        $user->forceFill(['campaign_id' => $campaign])->save();

        return $user;
    }

    private function evento(string $nombre, string $municipio, Carbon $cuando, ?string $campaign = null, bool $borrado = false): string
    {
        $id = (string) Str::uuid();
        DB::table('events')->insert(['id' => $id, 'campaign_id' => $campaign ?? $this->grupo, 'event_type_id' => $this->tipo, 'nombre' => $nombre,
            'starts_at' => $cuando->copy()->utc(), 'sitio_encuentro' => 'Parque', 'municipio_id' => $municipio, 'status' => 'abierto',
            'public_token' => Str::random(43), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $borrado ? now() : null]);

        return $id;
    }

    private function asistente(string $evento, string $nombres, string $cedula, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('event_attendees')->insert(array_merge(['id' => $id, 'event_id' => $evento,
            'campaign_id' => DB::table('events')->where('id', $evento)->value('campaign_id'), 'nombres' => $nombres, 'apellidos' => 'Pérez',
            'cedula' => $cedula, 'celular' => '300'.$cedula, 'barrio' => 'Centro', 'source' => 'qr', 'link_status' => 'pendiente',
            'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()], $extra));

        return $id;
    }

    private function spec(string $fuente, array $filtros = [], array $columnas = [], array $extra = []): array
    {
        return array_merge(['v' => 1, 'fuente' => $fuente, 'filtros' => $filtros, 'columnas' => $columnas, 'orden' => [], 'busqueda' => ''], $extra);
    }

    private function conteo(User $user, string $fuente, array $filtros = [], array $extra = []): int
    {
        return $this->actingAs($user)->postJson("/reportes/{$fuente}/conteo", ['spec' => $this->spec($fuente, $filtros, [], $extra)])->assertOk()->json('total');
    }

    private function filas(User $user, string $fuente, array $filtros, array $columnas, array $extra = []): array
    {
        return $this->actingAs($user)->postJson("/reportes/{$fuente}/lista", ['spec' => $this->spec($fuente, $filtros, $columnas, $extra)])->assertOk()->json('filas');
    }

    private function xlsx(User $user, string $fuente, array $spec): array
    {
        $response = $this->actingAs($user)->postJson("/reportes/{$fuente}/excel", ['spec' => $spec])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'b3').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }

    public function test_asistencias_on_postgres_dates_in_bogota_search_comuna_and_parity_with_the_event(): void
    {
        // 23:30 in Bogotá yesterday is already today in UTC: the filter must count it on its Bogotá day.
        $ayerNoche = Carbon::now('America/Bogota')->subDay()->setTime(23, 30);
        $ev = $this->evento('Gira B3', $this->buca, $ayerNoche);
        $viejo = $this->evento('Gira vieja B3', $this->giron, Carbon::now('America/Bogota')->subDays(60));
        $this->evento('Borrado B3', $this->giron, Carbon::now('America/Bogota'), null, true);
        $otro = $this->evento('Otro grupo B3', $this->giron, Carbon::now('America/Bogota'), $this->otro);
        $this->asistente($ev, 'José', '10001', ['comuna_id' => $this->comuna]);
        $this->asistente($ev, 'María', '10002');
        $this->asistente($viejo, 'Luis', '10003');
        $this->asistente($otro, 'Ajeno', '10004');

        $this->assertSame(3, $this->conteo($this->admin, 'asistencias'));
        $dia = $ayerNoche->toDateString();
        $this->assertSame(2, $this->conteo($this->admin, 'asistencias', ['fecha' => ['desde' => $dia, 'hasta' => $dia]]));
        $this->assertSame(2, $this->conteo($this->admin, 'asistencias', ['fecha' => ['relativo' => 'ultimos_7']]));
        $this->assertSame(1, $this->conteo($this->admin, 'asistencias', [], ['busqueda' => 'JOSÉ pér']));
        $this->assertSame(1, $this->conteo($this->admin, 'asistencias', [], ['busqueda' => '10003']));
        $this->assertSame(0, $this->conteo($this->sinDatos, 'asistencias', [], ['busqueda' => '10003']));

        $filas = $this->filas($this->admin, 'asistencias', ['evento' => [$ev]], ['nombre', 'cedula', 'comuna', 'fecha', 'municipio'], ['orden' => ['campo' => 'nombre', 'dir' => 'asc']]);
        $this->assertSame(['nombre' => 'José Pérez', 'cedula' => '10001', 'comuna' => 'Comuna B3', 'fecha' => $ayerNoche->format('d/m/Y').' 11:30 p. m.',
            'municipio' => 'Bucaramanga B3'], $filas[0]['celdas']);

        $screen = $this->actingAs($this->admin)->get("/eventos/{$ev}")->assertOk()->viewData('page')['props']['asistentes'];
        $this->assertEqualsCanonicalizing(array_column($screen['data'], 'id'), array_column($filas, 'id'));

        $rows = $this->xlsx($this->admin, 'asistencias', $this->spec('asistencias', [], ['nombre', 'cedula', 'celular']));
        $this->assertSame(['Persona', 'Cédula', 'Celular'], $rows[3]);
        $this->assertCount(4 + 3, $rows);
        $this->assertSame(1, AuditLog::where('user_id', $this->admin->id)->where('action', 'EXPORT reportes.asistencias.excel')->count());
    }

    // BUG-19 (QA B-3): every search of Reportes ignores accents and case on PostgreSQL, in both directions.
    public function test_searches_ignore_accents_on_postgres(): void
    {
        $ev = $this->evento('Reunión Peñón B3', $this->giron, Carbon::now('America/Bogota'));
        $this->asistente($ev, 'José Ángel', '20001', ['barrio' => 'El Peñón']);
        $this->asistente($ev, 'Jose Angel', '20002', ['barrio' => 'El Penon']);
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'campaign_id' => $this->grupo, 'geographic_unit_id' => $this->giron,
            'nombre' => 'Óscar Muñoz B3', 'municipio' => 'Girón B3', 'barrio' => 'Peñón B3', 'tipo' => 'votante', 'is_active' => true,
            'fallecido' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizaciones')->insert(['id' => (string) Str::uuid(), 'campaign_id' => $this->grupo, 'tipo' => 'empresa', 'nombre' => 'Café Ñame B3',
            'municipio_id' => $this->giron, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['jose angel', 'JOSÉ ÁNGEL', 'José Angel'] as $q) {
            $this->assertSame(2, $this->conteo($this->admin, 'asistencias', [], ['busqueda' => $q]), $q);
        }
        $this->assertSame(1, $this->conteo($this->admin, 'personas', [], ['busqueda' => 'oscar munoz b3']));
        $this->assertSame(1, $this->conteo($this->admin, 'personas', [], ['busqueda' => 'ÓSCAR MUÑOZ B3']));
        $this->assertSame(1, $this->conteo($this->admin, 'empresarios', [], ['busqueda' => 'cafe name b3']));

        $opciones = fn (string $url) => array_column($this->actingAs($this->admin)->getJson($url)->assertOk()->json('opciones'), 'etiqueta');
        $this->assertEqualsCanonicalizing(['El Peñón', 'El Penon'], $opciones('/reportes/opciones/barrios?fuente=asistencias&q=penon'));
        $this->assertContains('Reunión Peñón B3', array_map(fn ($e) => explode(' · ', $e)[0], $opciones('/reportes/opciones/eventos?fuente=asistencias&q=reunion penon')));
        $this->assertSame(['Peñón B3'], $opciones('/reportes/opciones/barrios?fuente=personas&q=penon b3'));
    }

    public function test_compromisos_on_postgres_agree_with_the_bell_and_search_without_accents(): void
    {
        $ev = $this->evento('Gira compromisos B3', $this->giron, Carbon::now('America/Bogota')->subDays(3));
        $acta = (string) Str::uuid();
        DB::table('event_actas')->insert(['id' => $acta, 'event_id' => $ev, 'campaign_id' => $this->grupo, 'titulo' => 'Acta B3', 'fecha' => now()->toDateString(),
            'estado' => 'confirmada', 'created_at' => now(), 'updated_at' => now()]);
        $hoy = Carbon::now('America/Bogota');
        foreach ([['Gestión de cámaras', $hoy->copy()->subDay(), 'pendiente'], ['Vía rural', $hoy->copy()->addDay(), 'en_curso'],
            ['Cerrado', $hoy->copy()->subDays(5), 'cumplido'], ['Sin fecha', null, 'pendiente']] as $i => [$d, $limite, $estado]) {
            $id = (string) Str::uuid();
            DB::table('event_compromisos')->insert(['id' => $id, 'acta_id' => $acta, 'event_id' => $ev, 'campaign_id' => $this->grupo, 'orden' => $i,
                'descripcion' => $d, 'fecha_limite' => $limite?->toDateString(), 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('event_compromiso_responsables')->insert(['id' => (string) Str::uuid(), 'compromiso_id' => $id, 'orden' => 0, 'nombre' => 'Sandra Pachón',
                'telefono' => '3109990000', 'created_at' => now(), 'updated_at' => now()]);
        }

        $bell = $this->actingAs($this->admin)->getJson('/eventos/compromisos/alertas')->assertOk()->json('data');
        $this->assertSame($bell['vencidos'], $this->conteo($this->admin, 'compromisos', ['vencimiento' => ['vencidos']]));
        $this->assertSame($bell['por_vencer'], $this->conteo($this->admin, 'compromisos', ['vencimiento' => ['por_vencer']]));
        $this->assertSame(1, $bell['vencidos']);
        $this->assertSame(1, $this->conteo($this->admin, 'compromisos', [], ['busqueda' => 'gestion de CAMARAS']));
        $this->assertSame(4, $this->conteo($this->admin, 'compromisos', ['municipio' => [$this->giron]]));

        $filas = $this->filas($this->admin, 'compromisos', [], ['descripcion', 'vencimiento', 'acta', 'telefono_responsable']);
        $this->assertSame(['Gestión de cámaras', 'Vía rural', 'Sin fecha', 'Cerrado'], array_column(array_column($filas, 'celdas'), 'descripcion'));
        $this->assertSame('Vencido', $filas[0]['celdas']['vencimiento']);
        $this->assertSame('Por vencer', $filas[1]['celdas']['vencimiento']);
        $this->assertSame('Acta B3 ('.now()->format('d/m/Y').')', $filas[0]['celdas']['acta']);

        $this->actingAs($this->admin)->postJson('/reportes/compromisos/pdf', ['spec' => $this->spec('compromisos')])->assertOk()->assertHeader('X-Reporte-Total', '4');
    }

    public function test_empresarios_on_postgres_both_views_and_never_a_deleted_one(): void
    {
        $org = (string) Str::uuid();
        $borrada = (string) Str::uuid();
        foreach ([[$org, 'Lácteos B3', null], [$borrada, 'Borrada B3', now()]] as [$id, $nombre, $deleted]) {
            DB::table('organizaciones')->insert(['id' => $id, 'campaign_id' => $this->grupo, 'tipo' => 'empresa', 'nombre' => $nombre, 'nit' => '890903938',
                'nit_dv' => '8', 'representante_nombre' => 'Rosa', 'municipio_id' => $this->giron, 'is_active' => true, 'deleted_at' => $deleted,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $lider = (string) Str::uuid();
        DB::table('lideres')->insert(['id' => $lider, 'campaign_id' => $this->grupo, 'geographic_unit_id' => $this->giron, 'nombre' => 'Miembro B3', 'municipio' => 'Girón B3',
            'cedula' => '55501', 'telefono' => '3205550000', 'tipo' => 'votante', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$org, $borrada] as $o) {
            DB::table('organizacion_miembros')->insert(['id' => (string) Str::uuid(), 'organizacion_id' => $o, 'lider_id' => $lider, 'rol' => 'socio',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(1, $this->conteo($this->admin, 'empresarios'));
        $this->assertSame(1, $this->conteo($this->admin, 'empresarios', ['estado' => 'todas'], ['busqueda' => 'lacteos']));
        $this->assertSame(1, $this->conteo($this->admin, 'empresarios', [], ['busqueda' => '890903']));
        $this->assertSame(1, $this->conteo($this->admin, 'empresarios', ['vista' => 'miembros']));
        $miembro = $this->filas($this->admin, 'empresarios', ['vista' => 'miembros'], ['nombre', 'organizacion', 'cedula', 'celular'])[0];
        $this->assertSame(['nombre' => 'Miembro B3', 'organizacion' => 'Lácteos B3', 'cedula' => '55501', 'celular' => '3205550000'], $miembro['celdas']);
        $this->assertSame($org, $miembro['accion']['id']);
        $this->assertSame(0, $this->conteo($this->user($this->otro, ['view.reportes', 'view.empresarios']), 'empresarios'));

        $rows = $this->xlsx($this->admin, 'empresarios', $this->spec('empresarios', ['vista' => 'miembros'], ['nombre', 'organizacion', 'rol']));
        $this->assertSame(['Nombre', 'Organización', 'Rol en la organización'], $rows[3]);
        $this->assertSame(['Miembro B3', 'Lácteos B3', 'Socio'], $rows[4]);
    }
}
