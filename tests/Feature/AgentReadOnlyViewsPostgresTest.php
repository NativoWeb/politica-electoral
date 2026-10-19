<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in checks of schema agent_ro on a LOCAL database (the views only exist on PostgreSQL):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AgentReadOnlyViewsPostgresTest
// A throw-away role gets the grants of deploy/sql/agent_ro_role.sql (its plain SQL statements are replayed);
// role, grants and data are rolled back. Checks run WITHOUT forcing read-only: only the role's privileges count.
class AgentReadOnlyViewsPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private string $role;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->role = 'agent_ro_test_'.Str::lower(Str::random(8));
        DB::statement("CREATE ROLE {$this->role} NOLOGIN");
        foreach ($this->roleScriptStatements() as $sql) {
            DB::statement($sql);
        }
    }

    // Plain statements of the deploy script; psql meta-commands and \if blocks (password handling) are skipped.
    private function roleScriptStatements(): array
    {
        $statements = [];
        $depth = 0;
        foreach (file(base_path('deploy/sql/agent_ro_role.sql'), FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '\if')) {
                $depth++;
            } elseif (str_starts_with($line, '\endif')) {
                $depth--;
            } elseif ($depth === 0 && $line !== '' && ! str_starts_with($line, '--') && ! str_starts_with($line, chr(92))
                && ! str_contains($line, '\gset')) {
                $line = str_replace(':"DBNAME"', '"'.DB::connection()->getDatabaseName().'"', $line);
                $statements[] = preg_replace('/\b(ROLE|TO|FROM)\s+agent_ro\b/', '$1 '.$this->role, rtrim($line, ';'));
            }
        }
        $this->assertNotEmpty($statements);

        return $statements;
    }

    private function asRole(callable $fn): mixed
    {
        DB::statement('SAVEPOINT agent_ro_check');
        DB::statement("SET LOCAL ROLE {$this->role}");
        try {
            return $fn();
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT agent_ro_check');
            DB::statement('RESET ROLE');
        }
    }

    private function assertDenied(string $sql, string $expected): void
    {
        try {
            $this->asRole(fn () => DB::select($sql));
            $this->fail("Expected an error for: {$sql}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    private function lider(array $attrs): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert([
            'id' => $id, 'nombre' => 'Lider Agent '.Str::random(4), 'municipio' => 'Girón', 'cargo' => 'COORDINADOR MUNICIPAL', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(), ...$attrs,
        ]);

        return $id;
    }

    public function test_base_tables_are_denied_and_views_are_readable(): void
    {
        $this->assertDenied('select * from public.lideres limit 1', 'permission denied');
        $this->assertDenied('select * from public.users limit 1', 'permission denied');

        $rows = $this->asRole(fn () => DB::select('select * from agent_ro.v_lideres limit 1'));
        $this->assertIsArray($rows);

        foreach (['v_lider_contactos', 'v_lider_info_politica', 'v_nexos', 'v_geo', 'v_persons', 'v_candidacies',
            'v_contests', 'v_events', 'v_results', 'v_turnout', 'v_orgs', 'v_offices', 'v_corporations', 'v_users_min'] as $view) {
            $this->assertIsArray($this->asRole(fn () => DB::select("select * from agent_ro.{$view} limit 1")), $view);
        }
    }

    public function test_role_cannot_write_or_create_anything_even_in_a_read_write_transaction(): void
    {
        $this->assertDenied(
            "insert into agent_ro.v_lider_info_politica (id, lider_id) values ('".Str::uuid()."', '".Str::uuid()."')",
            'permission denied for view'
        );
        $this->assertDenied('create temp table agent_tmp (i int)', 'permission denied to create temporary tables');
        $this->assertDenied('create function pg_temp.agent_fn() returns int language sql as $$ select 1 $$', 'permission denied to create temporary tables');
        $this->assertDenied('create table public.agent_x (i int)', 'permission denied for schema public');
        $this->assertDenied('create table agent_ro.agent_x (i int)', 'permission denied for schema agent_ro');
    }

    public function test_script_keeps_temporary_for_the_app_user_only(): void
    {
        $db = DB::connection()->getDatabaseName();
        $temp = fn (string $role) => DB::selectOne('select has_database_privilege(?, ?, ?) as ok', [$role, $db, 'TEMPORARY'])->ok;

        $this->assertTrue($temp(DB::selectOne('select current_user as u')->u));
        $this->assertFalse($temp($this->role));
        $this->assertFalse($temp('public'));
    }

    public function test_sensitive_columns_are_not_exposed(): void
    {
        $columns = fn (string $view) => collect(DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            ['agent_ro', $view]
        ))->pluck('column_name')->all();

        $lideres = $columns('v_lideres');
        foreach (['cedula', 'telefono', 'email', 'fecha_nacimiento', 'fecha_expedicion_doc', 'estado_civil', 'foto',
            'direccion', 'barrio', 'facebook', 'twitter', 'instagram', 'convenio', 'mesa_votacion', 'observacion'] as $col) {
            $this->assertNotContains($col, $lideres, "v_lideres exposes {$col}");
        }
        $this->assertContains('cedula_last4', $lideres);
        $this->assertContains('referente_id', $lideres);

        $this->assertNotContains('valor', $columns('v_lider_contactos'));
        $this->assertNotContains('observacion', $columns('v_lider_info_politica'));
        foreach (['cedula', 'telefono', 'edad', 'gustos', 'observaciones'] as $col) {
            $this->assertNotContains($col, $columns('v_nexos'));
        }
        foreach (['doc_hash', 'doc_last4', 'birth_date'] as $col) {
            $this->assertNotContains($col, $columns('v_persons'));
        }
        $this->assertNotContains('geom', $columns('v_geo'));
        $this->assertSame(['id', 'name', 'role_code'], $columns('v_users_min'));
    }

    public function test_lider_masking_birthday_and_referente(): void
    {
        $referente = $this->lider(['cedula' => '99887766', 'telefono' => '3001112222', 'autoriza_datos' => true]);
        $lider = $this->lider([
            'cedula' => '1.098.765.432', 'telefono' => '+57 310 555 4321', 'autoriza_datos' => false,
            'fecha_nacimiento' => '1980-03-07', 'referente_documento' => '99887766',
        ]);

        $rows = $this->asRole(fn () => collect(DB::select(
            'select id, cedula_last4, telefono_last4, cumple_mm_dd, referente_id from agent_ro.v_lideres where id in (?, ?)',
            [$referente, $lider]
        ))->keyBy('id'));

        $this->assertSame('5432', $rows[$lider]->cedula_last4);
        $this->assertNull($rows[$lider]->telefono_last4, 'telefono must be hidden without autoriza_datos');
        $this->assertSame('03-07', $rows[$lider]->cumple_mm_dd);
        $this->assertSame($referente, $rows[$lider]->referente_id);

        $this->assertSame('2222', $rows[$referente]->telefono_last4);
        $this->assertSame('7766', $rows[$referente]->cedula_last4);
    }
}
