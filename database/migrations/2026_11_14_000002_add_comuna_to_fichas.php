<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The comuna (or corregimiento) of a ficha, an event attendee and an organization: 'auto' when it came from the barrio
// text, 'manual' when a person chose it (never overwritten by the automatic assignment). Additive only: the agent_ro
// views depend on lideres.
return new class extends Migration
{
    private const TABLES = ['lideres', 'event_attendees', 'organizaciones'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->uuid('comuna_id')->nullable();
                $table->string('comuna_source', 10)->nullable();
                $table->foreign('comuna_id', "{$name}_comuna_id_foreign")->references('id')->on('geographic_units')->nullOnDelete();
                $table->index('comuna_id', "{$name}_comuna_id_index");
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_comuna_source_check CHECK (comuna_source IN ('auto', 'manual'))");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_comuna_source_check");
            }
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign("{$name}_comuna_id_foreign");
                $table->dropIndex("{$name}_comuna_id_index");
                $table->dropColumn(['comuna_id', 'comuna_source']);
            });
        }
    }
};
