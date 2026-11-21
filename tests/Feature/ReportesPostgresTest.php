<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Configuracion\Ajuste;
use App\Services\Reportes\Fuentes\PersonasFuente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

// Módulo Reportes on the real schema (jsonb, ilike, the Votantes listing it must agree with). Synthetic fixtures in
// their own grupos políticos and municipios, so whatever the local database holds is never counted.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=ReportesPostgresTest
class ReportesPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $grupo;

    private string $subgrupo;

    private string $otro;

    private string $giron;

    private string $buca;

    private User $admin;

    private User $sinDatos;

    private User $deGiron;

    private User $deSubgrupo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        PersonasFuente::olvidarEsquema();
        $this->grupo = $this->campaign('Grupo Reportes', null);
        $this->subgrupo = $this->campaign('Subgrupo Reportes', $this->grupo);
        $this->otro = $this->campaign('Otro Reportes', null);

        $prov = $this->geo('province', 'Provincia Reportes');
        $this->giron = $this->geo('municipality', 'Girón Reportes', $prov);
        $this->buca = $this->geo('municipality', 'Bucaramanga Reportes', $prov);

        $todo = ['view.reportes', 'view.votantes', 'view.mapa', 'view.eventos', 'write.data', 'export.datos_personales'];
        $this->admin = $this->user($this->grupo, $todo);
        $this->sinDatos = $this->user($this->grupo, ['view.reportes', 'view.votantes', 'write.data']);
        $this->deGiron = $this->user($this->grupo, ['view.reportes', 'view.votantes', 'export.datos_personales'], 'R09_CAMPO', [$this->giron]);
        $this->deSubgrupo = $this->user($this->subgrupo, ['view.reportes', 'view.votantes', 'write.data']);
    }

    private function campaign(string $name, ?string $parent): string
    {
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'parent_id' => $parent,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent]);

        return $id;
    }

    private function user(string $campaign, array $permissions, string $role = 'R02_ADMIN_FUNCIONAL', ?array $territory = null): User
    {
        $user = User::create([
            'name' => 'Usuaria '.Str::random(4), 'email' => 'reportes-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', $role)->value('id'), 'permissions' => $permissions, 'territory_scope' => $territory,
        ]);
        $user->forceFill(['campaign_id' => $campaign])->save();

        return $user;
    }

    private function ficha(string $nombre, array $extra = []): string
    {
        $id = (string) Str::uuid();
        $geo = $extra['geographic_unit_id'] ?? $this->giron;
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => $this->grupo, 'geographic_unit_id' => $geo, 'nombre' => $nombre,
            'municipio' => DB::table('geographic_units')->where('id', $geo)->value('canonical_name'),
            'tipo' => 'votante', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return $id;
    }

    private function spec(array $filtros = [], array $columnas = ['nombre'], array $extra = []): array
    {
        return array_merge(['v' => 1, 'fuente' => 'personas', 'filtros' => $filtros, 'columnas' => $columnas,
            'orden' => ['campo' => 'nombre', 'dir' => 'asc'], 'busqueda' => ''], $extra);
    }

    private function conteo(User $user, array $filtros = [], array $extra = []): int
    {
        return $this->actingAs($user)->postJson('/reportes/personas/conteo', ['spec' => $this->spec($filtros, ['nombre'], $extra)])
            ->assertOk()->assertJson(['success' => true])->json('total');
    }

    private function votantesTotal(User $user, string $query = ''): int
    {
        return $this->actingAs($user)->get('/votantes'.($query !== '' ? '?'.$query : ''))->assertOk()
            ->viewData('page')['props']['counters']['total'];
    }

    // [heading rows..., header, data...] of the downloaded file.
    private function excel(User $user, array $spec, ?\Closure $check = null): array
    {
        $response = $this->actingAs($user)->postJson('/reportes/personas/excel', ['spec' => $spec]);
        $response->assertOk();
        if ($check) {
            $check($response);
        }
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
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

    public function test_the_page_lists_personas_with_sensitive_columns_marked(): void
    {
        $this->ficha('Ana Uno');

        $this->actingAs($this->admin)->get('/reportes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reportes/Index', false)
                ->where('fuentes.0.clave', 'personas')
                ->where('fuentes.0.total', 1)
                ->where('puedeDatosPersonales', true)
                ->where('topes.excel', 50000)
                ->where('topes.pdf', 500)
                ->where('fuentes.0.columnas', fn ($cols) => collect($cols)->firstWhere('clave', 'cedula')['sensible'] === true
                    && collect($cols)->firstWhere('clave', 'municipio')['sensible'] === false
                    && collect($cols)->firstWhere('clave', 'nombre')['grupo'] === 'Identificación')
                ->where('fuentes.0.atajos.0.spec.filtros', ['marcas' => ['gran_elector']])
                ->where('fuentes.0.presets', fn ($p) => collect($p)->firstWhere('por_defecto', true)['clave'] === 'contacto_basico'));

        $this->actingAs($this->sinDatos)->get('/reportes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeDatosPersonales', false)
                ->where('fuentes.0.presets', fn ($p) => collect($p)->firstWhere('por_defecto', true)['clave'] === 'nombre_lugar'
                    && ! in_array('cedula', collect($p)->firstWhere('clave', 'completo')['columnas'], true)));
    }

    public function test_gran_elector_counts_active_living_people_of_the_visible_grupos(): void
    {
        $this->ficha('Ge Uno', ['gran_elector' => true]);
        $this->ficha('Ge Dos', ['gran_elector' => true, 'geographic_unit_id' => $this->buca]);
        $this->ficha('Ge Subgrupo', ['gran_elector' => true, 'campaign_id' => $this->subgrupo]);
        $this->ficha('Ge Inactivo', ['gran_elector' => true, 'is_active' => false]);
        $this->ficha('Ge Fallecido', ['gran_elector' => true, 'fallecido' => true]);
        $this->ficha('Ge Otro grupo', ['gran_elector' => true, 'campaign_id' => $this->otro]);
        $this->ficha('No ge');

        $esperado = DB::table('lideres')->whereIn('campaign_id', [$this->grupo, $this->subgrupo])
            ->where('gran_elector', true)->where('is_active', true)->where('fallecido', false)->count();
        $this->assertSame(3, $esperado);
        $this->assertSame($esperado, $this->conteo($this->admin, ['marcas' => ['gran_elector']]));
        $this->assertSame(4, $this->conteo($this->admin, ['marcas' => ['gran_elector'], 'incluir_fallecidos' => true]));
        $this->assertSame(1, $this->conteo($this->admin, ['marcas' => ['gran_elector'], 'estado' => 'inactivas']));
        // The subgrupo only sees its own.
        $this->assertSame(1, $this->conteo($this->deSubgrupo, ['marcas' => ['gran_elector']]));
    }

    public function test_totals_agree_with_the_votantes_screen_filter_by_filter(): void
    {
        $evento = $this->evento('Evento Reportes');
        $a = $this->ficha('Paridad A', ['gran_elector' => true, 'militante' => true, 'nivel_confianza' => 'confirmado',
            'migracion_flags' => json_encode(['por_completar' => ['barrio']])]);
        $b = $this->ficha('Paridad B', ['verificado' => true, 'nivel_confianza' => 'contactado',
            'migracion_flags' => json_encode(['contradicciones' => ['telefono' => '1']])]);
        $c = $this->ficha('Paridad C', ['gran_elector' => true, 'verificado' => true, 'migracion_flags' => json_encode(['sin_municipio' => true])]);
        $this->ficha('Paridad D', ['militante' => true, 'nivel_confianza' => 'confirmado', 'campaign_id' => $this->subgrupo]);
        $this->ficha('Paridad inactiva', ['gran_elector' => true, 'is_active' => false, 'fallecido' => true]);
        $this->asistio($a, $evento);
        $this->asistio($c, $evento);
        $this->asistio($b, $this->evento('Otro evento'));

        $pares = [
            [['marcas' => ['gran_elector']], 'gran_elector=1'],
            [['marcas' => ['militante']], 'militante=1'],
            [['marcas' => ['verificado']], 'verificado=1'],
            [['nivel_confianza' => ['confirmado']], 'nivel_confianza=confirmado'],
            [['por_completar' => ['barrio']], 'por_completar=barrio'],
            [['por_completar' => ['cualquiera']], 'por_completar=cualquiera'],
            [['por_completar' => ['municipio']], 'por_completar=municipio'],
            [['por_completar' => ['diferencias']], 'diferencias=1'],
            [['evento' => [$evento]], 'evento='.$evento],
            [['eventos_min' => 1], 'asistentes_eventos=1'],
            [[], ''],
        ];
        foreach ($pares as [$filtros, $query]) {
            $votantes = $this->votantesTotal($this->admin, $query);
            $this->assertSame($votantes, $this->conteo($this->admin, $filtros), 'Filtro '.json_encode($filtros));
            $this->assertGreaterThan(0, $votantes, 'Filtro sin datos '.json_encode($filtros));
        }

        // "Tienen personas referidas" is the Votantes counter con_referidos.
        DB::table('lideres')->where('id', $a)->update(['cedula' => '91000001']);
        $this->ficha('Referido de A', ['referente_documento' => '91000001']);
        $conReferidos = $this->actingAs($this->admin)->get('/votantes')->viewData('page')['props']['counters']['con_referidos'];
        $this->assertSame(1, $conReferidos);
        $this->assertSame($conReferidos, $this->conteo($this->admin, ['tiene_referidos' => true]));
        $this->assertSame(3, $this->conteo($this->admin, ['eventos_min' => 1]));
        $this->assertSame(0, $this->conteo($this->admin, ['eventos_min' => 2]));
    }

    public function test_page_two_has_fifty_other_people_in_name_order(): void
    {
        foreach (range(1, 120) as $i) {
            $this->ficha(sprintf('Persona %03d', 121 - $i));
        }
        $spec = $this->spec([], ['nombre', 'municipio']);
        $uno = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', ['spec' => $spec, 'pagina' => 1])->assertOk();
        $dos = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', ['spec' => $spec, 'pagina' => 2])->assertOk();

        $dos->assertJson(['success' => true, 'total' => 120, 'pagina' => 2, 'por_pagina' => 50]);
        $this->assertSame(120, $this->conteo($this->admin));
        $this->assertCount(50, $dos->json('filas'));
        $this->assertSame([], array_intersect(array_column($uno->json('filas'), 'id'), array_column($dos->json('filas'), 'id')));
        $this->assertSame('Persona 051', $dos->json('filas.0.celdas.nombre'));
        $this->assertSame('Persona 100', $dos->json('filas.49.celdas.nombre'));
        $this->assertSame('Girón Reportes', $dos->json('filas.0.celdas.municipio'));
        $this->assertSame(['nombre', 'municipio'], array_keys($dos->json('filas.0.celdas')));

        $desc = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec([], ['nombre'], ['orden' => ['campo' => 'nombre', 'dir' => 'desc']]), 'pagina' => 1,
        ]);
        $this->assertSame('Persona 120', $desc->json('filas.0.celdas.nombre'));
    }

    public function test_a_ficha_edited_from_the_list_shows_its_new_barrio_and_a_subgrupo_ficha_is_read_only(): void
    {
        $propia = $this->ficha('Editable Propia', ['barrio' => 'Viejo', 'gran_elector' => true]);
        $ajena = $this->ficha('Solo Lectura Sub', ['barrio' => 'Sub', 'gran_elector' => true, 'campaign_id' => $this->subgrupo]);
        $this->ficha('Otro Grupo No', ['gran_elector' => true, 'campaign_id' => $this->otro]);
        $spec = $this->spec(['marcas' => ['gran_elector']], ['nombre', 'barrio']);

        $filas = collect($this->actingAs($this->admin)->postJson('/reportes/personas/lista', ['spec' => $spec])->assertOk()->json('filas'))->keyBy('id');
        $this->assertCount(2, $filas);
        $this->assertTrue($filas[$propia]['editable']);
        $this->assertFalse($filas[$ajena]['editable']);

        $this->actingAs($this->admin)->putJson("/votantes/{$propia}", ['barrio' => 'Nuevo Barrio'])->assertOk();
        $this->actingAs($this->admin)->putJson("/votantes/{$ajena}", ['barrio' => 'Intento'])->assertForbidden();
        $this->actingAs($this->admin)->putJson("/mapa-politico/persona/{$propia}", ['barrio' => 'Nuevo Barrio'])->assertOk();

        $filas = collect($this->actingAs($this->admin)->postJson('/reportes/personas/lista', ['spec' => $spec])->json('filas'))->keyBy('id');
        $this->assertSame('Nuevo Barrio', $filas[$propia]['celdas']['barrio']);
        $this->assertSame('Sub', $filas[$ajena]['celdas']['barrio']);

        // The subgrupo does not see the grupo's fichas; a user without write rights gets nothing editable.
        $sub = $this->actingAs($this->deSubgrupo)->postJson('/reportes/personas/lista', ['spec' => $spec])->json('filas');
        $this->assertSame([$ajena], array_column($sub, 'id'));
        $this->assertTrue($sub[0]['editable']);
        $lectura = $this->user($this->grupo, ['view.reportes', 'view.votantes'], 'R10_CONSULTA');
        $this->assertFalse(collect($this->actingAs($lectura)->postJson('/reportes/personas/lista', ['spec' => $spec])->json('filas'))->contains('editable', true));
    }

    public function test_a_territory_user_only_counts_lists_and_downloads_their_municipio(): void
    {
        foreach (range(1, 3) as $i) {
            $this->ficha("Gironés {$i}", ['cedula' => "9200000{$i}"]);
        }
        $this->ficha('Bumangués', ['geographic_unit_id' => $this->buca]);
        $enGiron = DB::table('lideres')->where('campaign_id', $this->grupo)->where('geographic_unit_id', $this->giron)->count();

        $this->assertSame($enGiron, $this->conteo($this->deGiron));
        $this->assertSame($enGiron, $this->conteo($this->deGiron, ['municipio' => [$this->buca]]));
        $this->assertSame($enGiron, $this->conteo($this->deGiron, ['campaign_id' => $this->otro, 'sql' => '1=1', 'tipo' => ['asistente_evento']]));
        $this->assertSame(4, $this->conteo($this->admin, ['campaign_id' => $this->otro, 'sql' => '1=1', 'tipo' => ['asistente_evento', 'votante']]));
        // What one user may filter by is never reused for another in the same process.
        $this->assertSame(1, $this->conteo($this->admin, ['municipio' => [$this->buca]]));

        $lista = $this->actingAs($this->deGiron)->postJson('/reportes/personas/lista', ['spec' => $this->spec(['municipio' => [$this->buca]], ['nombre', 'municipio'])]);
        $this->assertSame(['Girón Reportes'], array_values(array_unique(array_column(array_column($lista->json('filas'), 'celdas'), 'municipio'))));

        $rows = $this->excel($this->deGiron, $this->spec([], ['nombre', 'municipio']));
        $municipios = array_unique(array_column(array_slice($rows, 4), 1));
        $this->assertSame(['Girón Reportes'], array_values($municipios));
        $this->assertCount($enGiron, array_slice($rows, 4));

        // Options of a search box stay inside the territory too.
        $this->ficha('Barrio Fuera', ['geographic_unit_id' => $this->buca, 'barrio' => 'Barrio de Bucaramanga']);
        $this->ficha('Barrio Dentro', ['barrio' => 'Barrio de Girón']);
        $barrios = $this->actingAs($this->deGiron)->getJson('/reportes/opciones/barrios?q=Barrio')->assertOk()->json('opciones');
        $this->assertSame(['Barrio de Girón'], array_column($barrios, 'valor'));
    }

    public function test_the_excel_has_its_heading_exact_columns_text_cells_and_an_audit_row(): void
    {
        $this->ficha('=HYPERLINK("http://x.test","clic")', ['cedula' => '0123', 'telefono' => '3001234567', 'gran_elector' => true]);
        $this->ficha('Zulma Normal', ['cedula' => '0456', 'telefono' => '3007654321', 'gran_elector' => true]);
        $spec = $this->spec(['marcas' => ['gran_elector'], 'municipio' => [$this->giron]], ['nombre', 'cedula', 'telefono']);

        $rows = $this->excel($this->admin, $spec, function ($response) {
            $this->assertStringContainsString('Reporte_Gran_elector_Giron_Reportes_', $response->headers->get('Content-Disposition'));
            $this->assertSame('2', $response->headers->get('X-Reporte-Filas'));
        });

        $this->assertStringContainsString('Gran elector', $rows[0][0]);
        $this->assertSame('Filtros: Gran elector · Girón Reportes', $rows[1][0]);
        $this->assertMatchesRegularExpression('/^Generado por Usuaria \w{4} el \d{2}\/\d{2}\/\d{4} a las \d{2}:\d{2} \(hora de Bogotá\)$/u', $rows[2][0]);
        $this->assertSame(['Nombre', 'Cédula', 'Teléfono'], $rows[3]);
        $this->assertCount(3, $rows[4]);
        $this->assertSame(["'=HYPERLINK(\"http://x.test\",\"clic\")", '0123', '3001234567'], $rows[4]);
        $this->assertSame('0456', $rows[5][1]);
        $this->assertCount(6, $rows);

        $log = AuditLog::where('user_id', $this->admin->id)->where('action', 'EXPORT reportes.personas.excel')->sole();
        $this->assertSame('reporte', $log->entity_type);
        $this->assertSame(2, $log->metadata['filas']);
        $this->assertSame(2, $log->metadata['total']);
        $this->assertFalse($log->metadata['recortado']);
        $this->assertTrue($log->metadata['con_datos_personales']);
        $this->assertSame(['nombre', 'cedula', 'telefono'], $log->metadata['columnas']);
        $this->assertSame(['marcas' => ['gran_elector'], 'municipio' => [$this->giron]], $log->metadata['spec']['filtros']);
        $this->assertSame('reportes.descargar.excel', $log->metadata['ruta']);
        $this->assertSame(1, AuditLog::where('user_id', $this->admin->id)->count(), 'AuditActivity must not log the download again');
    }

    public function test_the_excel_is_cut_at_the_ajuste_and_says_so(): void
    {
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 1646), 500) as $chunk) {
            DB::table('lideres')->insert(array_map(fn ($i) => [
                'id' => (string) Str::uuid(), 'campaign_id' => $this->grupo, 'geographic_unit_id' => $this->giron,
                'nombre' => sprintf('Sintética %04d', $i), 'municipio' => 'Girón Reportes', 'tipo' => 'votante',
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        DB::table('ajustes')->insert(['clave' => 'reportes.excel_max_filas', 'valor' => json_encode(1000), 'tipo' => 'int', 'created_at' => $now, 'updated_at' => $now]);
        Ajuste::olvidar();

        $rows = $this->excel($this->admin, $this->spec([], ['nombre']));

        $this->assertStringContainsString('Tu lista tiene 1.646 personas. Este archivo trae las primeras 1.000', $rows[3][0]);
        $this->assertSame(['Nombre'], $rows[4]);
        $this->assertCount(1000, array_slice($rows, 5));
        $this->assertSame('Sintética 1000', end($rows)[0]);
        $log = AuditLog::where('user_id', $this->admin->id)->where('action', 'EXPORT reportes.personas.excel')->sole();
        $this->assertTrue($log->metadata['recortado']);
        $this->assertSame(1000, $log->metadata['filas']);
        $this->assertSame(1646, $log->metadata['total']);
    }

    public function test_a_searched_cedula_reaches_the_audit_log_as_its_last_four_digits(): void
    {
        $this->ficha('Buscada', ['cedula' => '1098765432']);
        $rows = $this->excel($this->admin, $this->spec([], ['nombre'], ['busqueda' => '1098765432']));
        $this->assertSame('Buscada', $rows[4][0]);

        $log = AuditLog::where('user_id', $this->admin->id)->where('action', 'EXPORT reportes.personas.excel')->sole();
        $this->assertSame('5432', $log->metadata['spec']['busqueda_last4']);
        $this->assertArrayNotHasKey('busqueda', $log->metadata['spec']);
        $this->assertStringNotContainsString('1098765432', json_encode($log->getRawOriginal('metadata')));
    }

    public function test_age_referente_event_and_other_filters(): void
    {
        $hoy = now('America/Bogota');
        $this->ficha('Joven', ['fecha_nacimiento' => $hoy->copy()->subYears(20)->toDateString(), 'genero' => 'Femenino']);
        $this->ficha('Cumple 40 hoy', ['fecha_nacimiento' => $hoy->copy()->subYears(40)->toDateString()]);
        $this->ficha('Cumple 41 mañana', ['fecha_nacimiento' => $hoy->copy()->subYears(41)->addDay()->toDateString()]);
        $this->ficha('Mayor', ['fecha_nacimiento' => $hoy->copy()->subYears(70)->toDateString(), 'zona' => 'rural']);
        $this->ficha('Sin fecha');
        $ref = $this->ficha('Referente Uno', ['cedula' => '93000001', 'tipo' => 'directorio']);
        $this->ficha('Traído 1', ['referente_documento' => '93000001']);
        $this->ficha('Traído 2', ['referente_documento' => '93000001', 'origen' => 'electo']);
        $this->ficha('Traído por otro grupo', ['referente_documento' => '93000001', 'campaign_id' => $this->subgrupo]);

        $this->assertSame(2, $this->conteo($this->admin, ['edad' => ['desde' => 40, 'hasta' => 60]]));
        $this->assertSame(1, $this->conteo($this->admin, ['edad' => ['desde' => 61, 'hasta' => null]]));
        $this->assertSame(1, $this->conteo($this->admin, ['edad' => ['desde' => 18, 'hasta' => 25], 'genero' => ['Femenino']]));
        $this->assertSame(2, $this->conteo($this->admin, ['referente' => [$ref]]));
        // A referente the user cannot see (or that does not exist) is dropped like any value outside the lists.
        $this->assertSame($this->conteo($this->admin), $this->conteo($this->admin, ['referente' => [(string) Str::uuid()]]));
        $this->assertSame(1, $this->conteo($this->admin, ['referente' => [$ref], 'origen' => ['electo']]));
        $this->assertSame(1, $this->conteo($this->admin, ['zona' => ['rural']]));
        $this->assertSame(1, $this->conteo($this->admin, ['tipo' => ['directorio']]));
        $this->assertSame(3, $this->conteo($this->admin, [], ['busqueda' => 'Traído ']));

        $lista = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec(['referente' => [$ref]], ['nombre', 'referente', 'origen']),
        ])->json('filas');
        $edadDe = fn (string $nombre) => collect($this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec([], ['nombre', 'edad'], ['busqueda' => $nombre]),
        ])->json('filas'))->first()['celdas']['edad'];
        $this->assertSame(['Referente Uno'], array_values(array_unique(array_column(array_column($lista, 'celdas'), 'referente'))));
        $origenes = array_column(array_column($lista, 'celdas'), 'origen', 'nombre');
        $this->assertSame(['Traído 1' => 'Registrada aquí', 'Traído 2' => 'Electo'], $origenes);
        $this->assertSame([40, 40], [$edadDe('Cumple 40 hoy'), $edadDe('Cumple 41 mañana')]);

        $orden = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec([], ['nombre', 'referidos'], ['orden' => ['campo' => 'referidos', 'dir' => 'desc']]),
        ])->json('filas');
        $this->assertSame('Referente Uno', $orden[0]['celdas']['nombre']);
        $this->assertSame(2, $orden[0]['celdas']['referidos']);

        $opciones = $this->actingAs($this->admin)->getJson('/reportes/opciones/referentes?q=refer')->assertOk()->json('opciones');
        $this->assertSame([$ref], array_column($opciones, 'valor'));
    }

    public function test_event_filters_and_columns_need_view_eventos(): void
    {
        $evento = $this->evento('Gira Reportes');
        $a = $this->ficha('Asistente Uno', ['tipo' => 'asistente_evento']);
        $this->asistio($a, $evento);
        $this->ficha('Líder normal', ['tipo' => 'directorio']);

        $this->assertSame(1, $this->conteo($this->admin, ['evento' => [$evento]]));
        $this->assertSame(1, $this->conteo($this->admin, ['tipo' => ['asistente_evento']]));
        // Without view.eventos the attendee is a Líder and the event filters do not exist.
        $this->assertSame(2, $this->conteo($this->sinDatos, ['evento' => [$evento], 'eventos_min' => 1, 'tipo' => ['asistente_evento']]));
        $this->assertSame(2, $this->conteo($this->sinDatos, ['tipo' => ['directorio']]));
        $this->actingAs($this->sinDatos)->postJson('/reportes/personas/lista', ['spec' => $this->spec([], ['nombre', 'eventos'])])->assertStatus(422);
        $this->actingAs($this->sinDatos)->getJson('/reportes/opciones/eventos')->assertNotFound();

        $fila = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec(['evento' => [$evento]], ['nombre', 'eventos', 'ultimo_evento', 'tipo']),
        ])->json('filas.0.celdas');
        $this->assertSame('Gira Reportes', $fila['eventos']);
        $this->assertStringStartsWith('Gira Reportes (', $fila['ultimo_evento']);
        $this->assertSame('Asistente a evento', $fila['tipo']);
        $tipoSin = $this->actingAs($this->sinDatos)->postJson('/reportes/personas/lista', ['spec' => $this->spec([], ['nombre', 'tipo'])])->json('filas');
        $this->assertSame(['Líder'], array_values(array_unique(array_column(array_column($tipoSin, 'celdas'), 'tipo'))));
    }

    public function test_every_column_renders_on_postgres(): void
    {
        $id = $this->ficha('Completa', ['cedula' => '94000001', 'telefono' => '3000000001', 'email' => 'c@example.test',
            'gran_elector' => true, 'militante' => true, 'facebook' => 'fb.test', 'fecha_nacimiento' => '1980-05-06',
            'migracion_flags' => json_encode(['por_completar' => ['barrio']]), 'origen' => 'electo', 'nivel_confianza' => 'confirmado']);
        DB::table('lider_contactos')->insert(['id' => (string) Str::uuid(), 'lider_id' => $id, 'tipo' => 'celular', 'valor' => '3110000000',
            'principal' => true, 'estado' => 'activo', 'created_at' => now(), 'updated_at' => now()]);
        $columnas = array_column($this->actingAs($this->admin)->get('/reportes')->viewData('page')['props']['fuentes'][0]['columnas'], 'clave');

        $celdas = $this->actingAs($this->admin)->postJson('/reportes/personas/lista', ['spec' => $this->spec([], $columnas)])
            ->assertOk()->json('filas.0.celdas');
        $this->assertSame($columnas, array_keys($celdas));
        $this->assertSame('Gran elector, Militante', $celdas['marcas']);
        $this->assertSame('Celular: 3110000000', $celdas['contactos']);
        $this->assertSame('06/05/1980', $celdas['fecha_nacimiento']);
        $this->assertSame('Barrio', $celdas['por_completar']);
        $this->assertSame('Confirmado', $celdas['nivel_confianza']);
        $this->assertSame('Grupo Reportes', $celdas['grupo']);
        $this->assertSame('Facebook: fb.test', $celdas['redes']);

        $rows = $this->excel($this->admin, $this->spec([], $columnas));
        $this->assertCount(count($columnas), $rows[3]);
    }

    public function test_comunas_come_in_natural_order_and_sortable_columns_are_marked(): void
    {
        foreach (['Comuna 13', 'Corregimiento 1', 'Comuna 2', 'Comuna 1'] as $nombre) {
            $this->geo(str_starts_with($nombre, 'Comuna') ? 'comuna' : 'corregimiento', $nombre, $this->buca);
        }
        $this->ficha('Barrio B', ['barrio' => 'B']);
        $this->ficha('Barrio A', ['barrio' => 'A']);
        $this->ficha('Sin barrio');

        $props = $this->actingAs($this->admin)->get('/reportes')->viewData('page')['props']['fuentes'][0];
        $comuna = collect($props['filtros'])->pluck('filtros')->flatten(1)->firstWhere('clave', 'comuna');
        $deBuca = array_values(array_filter($comuna['opciones'], fn ($o) => $o['municipio'] === $this->buca));
        $this->assertSame(['Comuna 1', 'Comuna 2', 'Comuna 13', 'Corregimiento 1'], array_column($deBuca, 'etiqueta'));
        $this->assertSame(['nombre', 'municipio', 'barrio', 'tipo', 'referidos'], array_column(array_filter($props['columnas'], fn ($c) => $c['ordenable']), 'clave'));
        foreach (array_filter($props['columnas'], fn ($c) => $c['ordenable']) as $c) {
            $this->assertContains($c['clave'], array_column($props['ordenes'], 'clave'));
        }

        $orden = fn (string $dir) => array_column(array_column($this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec([], ['nombre', 'barrio'], ['orden' => ['campo' => 'barrio', 'dir' => $dir]]),
        ])->assertOk()->json('filas'), 'celdas'), 'nombre');
        $this->assertSame(['Barrio A', 'Barrio B', 'Sin barrio'], $orden('asc'));
        $this->assertSame(['Barrio B', 'Barrio A', 'Sin barrio'], $orden('desc'));
    }

    public function test_the_page_carries_what_the_ficha_of_the_mapa_needs(): void
    {
        $this->ficha('Con cargo', ['cargo' => 'Cargo Reportes Prueba', 'tipo' => 'directorio']);

        $mapa = $this->actingAs($this->admin)->get('/reportes')->viewData('page')['props'];
        $this->assertContains('Cargo Reportes Prueba', $mapa['cargosPorTipo']['lideres']);
        $this->assertSame(['Alcalde Electo', 'Candidato Alcaldía'], $mapa['cargosPorTipo']['alcaldia']);
        $this->assertArrayHasKey('asistentes_evento', $mapa['cargosPorTipo']);
        $this->assertIsArray($mapa['cargoCategorias']);
        $this->assertContains($this->giron, array_column($mapa['municipios'], 'id'));

        // The same lists as the Mapa político's own page.
        $pagina = $this->actingAs($this->admin)->get('/mapa-politico')->viewData('page')['props'];
        $this->assertEquals($pagina['cargosPorTipo'], $mapa['cargosPorTipo']);
        $this->assertEquals($pagina['cargoCategorias'], $mapa['cargoCategorias']);

        // Without view.mapa there is no PersonaPanel: no cargos; the territory user only gets their municipio.
        $sinMapa = $this->actingAs($this->sinDatos)->get('/reportes')->viewData('page')['props'];
        $this->assertEquals([], (array) $sinMapa['cargosPorTipo']);
        $this->assertSame([], $sinMapa['cargoCategorias']);
        $this->assertSame([$this->giron], array_column($this->actingAs($this->deGiron)->get('/reportes')->viewData('page')['props']['municipios'], 'id'));
    }

    public function test_a_referente_outside_the_territory_is_neither_applied_nor_named(): void
    {
        $fuera = $this->ficha('Referente De Bucaramanga', ['cedula' => '95000001', 'geographic_unit_id' => $this->buca]);
        $this->ficha('Traída en Girón', ['referente_documento' => '95000001']);
        $this->ficha('Otra de Girón');

        $this->assertSame(2, $this->conteo($this->deGiron, ['referente' => [$fuera]]));
        $this->assertSame(1, $this->conteo($this->admin, ['referente' => [$fuera]]));

        $rows = $this->excel($this->deGiron, $this->spec(['referente' => [$fuera]], ['nombre']));
        $this->assertStringNotContainsString('Bucaramanga', $rows[0][0].$rows[1][0]);
        $this->assertSame('Filtros: ninguno (todas las personas activas)', $rows[1][0]);
        $log = AuditLog::where('user_id', $this->deGiron->id)->where('action', 'EXPORT reportes.personas.excel')->sole();
        $this->assertSame([], $log->metadata['spec']['filtros']);
    }

    public function test_the_municipio_comes_from_the_geographic_unit_and_empty_cells_stay_empty(): void
    {
        $this->ficha('Escrito sin tilde', ['municipio' => 'Giron', 'provincia' => 'Texto viejo']);
        $this->ficha('Escrito con tilde', ['municipio' => 'Girón Reportes']);
        $this->ficha('Sin unidad', ['geographic_unit_id' => null, 'municipio' => 'Pueblo Escrito', 'provincia' => 'Provincia Escrita', 'is_active' => true]);

        $filas = collect($this->actingAs($this->admin)->postJson('/reportes/personas/lista', [
            'spec' => $this->spec([], ['nombre', 'municipio', 'provincia'], ['orden' => ['campo' => 'municipio', 'dir' => 'asc']]),
        ])->assertOk()->json('filas'))->pluck('celdas');
        $porNombre = $filas->keyBy('nombre');
        $this->assertSame('Girón Reportes', $porNombre['Escrito sin tilde']['municipio']);
        $this->assertSame('Provincia Reportes', $porNombre['Escrito sin tilde']['provincia']);
        $this->assertSame('Pueblo Escrito', $porNombre['Sin unidad']['municipio']);
        $this->assertSame('Provincia Escrita', $porNombre['Sin unidad']['provincia']);
        // Sorted by the unit's name: both spellings together, «Pueblo Escrito» after them.
        $this->assertSame(['Escrito con tilde', 'Escrito sin tilde', 'Sin unidad'], $filas->pluck('nombre')->all());

        $rows = $this->excel($this->admin, $this->spec([], ['nombre', 'municipio', 'barrio', 'votos']));
        $datos = collect(array_slice($rows, 4))->keyBy(0);
        $this->assertSame(['Escrito sin tilde', 'Girón Reportes', '', 0], $datos['Escrito sin tilde']);
    }

    public function test_the_excel_columns_have_readable_widths(): void
    {
        $this->ficha('Ancho');
        $response = $this->actingAs($this->admin)->postJson('/reportes/personas/excel', ['spec' => $this->spec([], ['nombre', 'municipio', 'nivel_confianza'])]);
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('<col min="1" max="1" width="35"', $sheet);
        $this->assertStringContainsString('<col min="2" max="2" width="13"', $sheet);
        $this->assertStringContainsString('<col min="3" max="3" width="22"', $sheet);
    }

    private function evento(string $nombre): string
    {
        $tipo = (string) Str::uuid();
        DB::table('event_types')->insert(['id' => $tipo, 'nombre' => 'Tipo '.Str::random(5), 'is_active' => true]);
        $id = (string) Str::uuid();
        DB::table('events')->insert(['id' => $id, 'campaign_id' => $this->grupo, 'event_type_id' => $tipo, 'nombre' => $nombre,
            'starts_at' => now()->subDay(), 'sitio_encuentro' => 'Parque', 'municipio_id' => $this->giron, 'status' => 'cerrado',
            'public_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function asistio(string $liderId, string $eventoId): void
    {
        DB::table('event_attendees')->insert(['id' => (string) Str::uuid(), 'event_id' => $eventoId, 'campaign_id' => $this->grupo,
            'lider_id' => $liderId, 'nombres' => 'Asistente', 'apellidos' => 'Sintético', 'source' => 'operador',
            'link_status' => 'vinculado', 'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
