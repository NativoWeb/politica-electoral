<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Indexes for ~130.000 fichas (Electo migration): the Mapa Político and Votantes filters, the name lookups that unify
// electoral rows with fichas, cédula/phone searches and birthdays. Additive and CONCURRENTLY (no write lock), so this
// migration runs outside a transaction. A table the app user does not own is skipped with the SQL to run as postgres.
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'lideres_geo_nombre_idx' => ['lideres', '(geographic_unit_id, nombre)'],
        'lideres_campaign_nombre_idx' => ['lideres', '(campaign_id, nombre, id)'],
        'lideres_geo_tipo_idx' => ['lideres', '(geographic_unit_id, tipo)'],
        'lideres_lower_trim_nombre_idx' => ['lideres', '(lower(trim(nombre)))'],
        'lideres_municipio_idx' => ['lideres', '(municipio)'],
        'lideres_lower_nombre_trgm' => ['lideres', 'USING gin (lower(nombre) gin_trgm_ops)'],
        // Equality (referidos: referente_documento = cedula) needs a btree: with only the trigram index the planner
        // probes it once per row (Votantes' "con referidos" counter went from 40 ms to 600 ms).
        'lideres_cedula_idx' => ['lideres', '(cedula)'],
        'lideres_cedula_trgm' => ['lideres', 'USING gin (cedula gin_trgm_ops)'],
        'lideres_telefono_trgm' => ['lideres', 'USING gin (telefono gin_trgm_ops)'],
        'lideres_cumpleanos_idx' => ['lideres', '((extract(month from fecha_nacimiento)), (extract(day from fecha_nacimiento))) WHERE fecha_nacimiento IS NOT NULL'],
        'lider_contactos_valor_trgm' => ['lider_contactos', 'USING gin (valor gin_trgm_ops)'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => [$table, $definition]) {
            $sql = "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} {$definition}";
            if (! $this->ownsTable($table)) {
                fwrite(STDERR, "AVISO: {$table} no es del usuario de la app; correr como postgres: {$sql};\n");

                continue;
            }
            // A CONCURRENTLY build that failed halfway leaves an INVALID index that IF NOT EXISTS would keep.
            if ($this->invalid($name)) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            DB::statement($sql);
        }
        DB::statement('ANALYZE lideres');
        DB::statement('ANALYZE lider_contactos');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => [$table]) {
            if ($this->ownsTable($table)) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }
    }

    private function ownsTable(string $table): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 AS ok FROM pg_tables WHERE schemaname = current_schema() AND tablename = ? AND tableowner = current_user',
            [$table],
        );
    }

    private function invalid(string $index): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 AS ok FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND NOT i.indisvalid',
            [$index],
        );
    }
};
