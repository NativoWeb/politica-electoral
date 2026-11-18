<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Migration from the previous system "Electo" (electo:importar). Additive only: the agent_ro views depend on lideres,
// so nothing is changed with ->change(). origen/origen_id mark the rows the importer CREATED (a partial unique index
// makes a second run skip them); rows that already existed and only got empty fields filled keep origen NULL and are
// traced in electo_import_rows. migracion_flags (lideres, lider_info_politica, events) holds what needs review: menor,
// fallecido, sin_municipio, fecha_invalida, posible_duplicado, genero_inferido, contradicciones {campo: valor de Electo},
// electo_codigos {campo: código} and por_completar [campos] for the catalogs Electo did not send (barrio, confianza,
// convenio, hoja de vida, cargo político, estado del mapa...). lideres.origen_meta keeps other Electo trace data.
//
// data_consents is owned by postgres in production (deploy/sql/data_consents_owner.sql): its CHECK is only widened
// here when the migrating user owns the table; otherwise the SQL in docs/migracion-electo.md is run by hand as postgres.
return new class extends Migration
{
    private const ORIGIN_TABLES = ['lideres', 'lider_contactos', 'lider_info_politica', 'events', 'event_attendees'];

    private const ATTENDEE_SOURCES = ['qr', 'operador', 'excel', 'ocr', 'acta'];

    private const CONSENT_CHANNELS = ['qr', 'operador'];

    public function up(): void
    {
        foreach (self::ORIGIN_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('origen', 20)->nullable();
                $table->bigInteger('origen_id')->nullable();
            });
            $this->originIndex($name);
        }

        Schema::table('lideres', function (Blueprint $table) {
            $table->jsonb('origen_meta')->nullable();
            $table->jsonb('migracion_flags')->nullable();
        });
        Schema::table('lider_contactos', function (Blueprint $table) {
            $table->boolean('whatsapp')->default(false);
        });
        Schema::table('lider_info_politica', function (Blueprint $table) {
            $table->jsonb('migracion_flags')->nullable();
            $table->date('periodo_desde')->nullable();
            $table->date('periodo_hasta')->nullable();
        });
        Schema::table('lider_municipios_influencia', function (Blueprint $table) {
            $table->string('origen', 20)->nullable();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('migracion_flags')->nullable();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX lideres_migracion_flags_gin ON lideres USING gin (migracion_flags) WHERE migracion_flags IS NOT NULL');
        $this->replaceCheck('event_attendees', 'event_attendees_source_check', 'source', [...self::ATTENDEE_SOURCES, 'electo']);

        if ($this->ownsTable('data_consents')) {
            $this->replaceCheck('data_consents', 'data_consents_channel_check', 'channel', [...self::CONSENT_CHANNELS, 'migracion_electo']);
        } else {
            fwrite(STDERR, "AVISO: data_consents no es del usuario de la app; ampliar su CHECK a mano como postgres (docs/migracion-electo.md).\n");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // NOT VALID: rows already imported with the new values stay (data_consents can never be deleted).
            if ($this->ownsTable('data_consents')) {
                $this->replaceCheck('data_consents', 'data_consents_channel_check', 'channel', self::CONSENT_CHANNELS, true);
            }
            $this->replaceCheck('event_attendees', 'event_attendees_source_check', 'source', self::ATTENDEE_SOURCES, true);
            DB::statement('DROP INDEX IF EXISTS lideres_migracion_flags_gin');
        }

        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('migracion_flags'));
        Schema::table('lider_municipios_influencia', fn (Blueprint $table) => $table->dropColumn('origen'));
        Schema::table('lider_info_politica', fn (Blueprint $table) => $table->dropColumn(['migracion_flags', 'periodo_desde', 'periodo_hasta']));
        Schema::table('lider_contactos', fn (Blueprint $table) => $table->dropColumn('whatsapp'));
        Schema::table('lideres', fn (Blueprint $table) => $table->dropColumn(['origen_meta', 'migracion_flags']));

        foreach (array_reverse(self::ORIGIN_TABLES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}_origen_unique");
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['origen', 'origen_id']));
        }
    }

    private function originIndex(string $name): void
    {
        DB::statement("CREATE UNIQUE INDEX {$name}_origen_unique ON {$name} (origen, origen_id) WHERE origen IS NOT NULL");
    }

    private function replaceCheck(string $table, string $constraint, string $column, array $values, bool $notValid = false): void
    {
        $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$list}))".($notValid ? ' NOT VALID' : ''));
    }

    private function ownsTable(string $table): bool
    {
        return (bool) DB::scalar('SELECT pg_has_role(current_user, tableowner, \'USAGE\') FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?', [$table]);
    }
};
