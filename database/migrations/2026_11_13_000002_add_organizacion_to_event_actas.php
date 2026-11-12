<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// An acta (and its compromisos) belongs to an EVENT or to an ORGANIZATION (docs/empresarios.md): organizacion_id is
// added and event_id becomes nullable, with a CHECK of exactly one in PostgreSQL. Existing actas keep their event_id
// and do not change. created_miembro marks the memberships an organization's acta created on confirmation (what
// deleting that confirmed acta undoes). Does not touch data_consents nor the agent_ro views.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['event_actas', 'event_compromisos'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('organizacion_id')->nullable();
                $table->foreign('organizacion_id')->references('id')->on('organizaciones');
                $table->index('organizacion_id');
            });
        }
        Schema::table('event_acta_participantes', fn (Blueprint $table) => $table->boolean('created_miembro')->default(false));

        if (DB::getDriverName() === 'pgsql') {
            foreach (['event_actas', 'event_compromisos'] as $name) {
                DB::statement("ALTER TABLE {$name} ALTER COLUMN event_id DROP NOT NULL");
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_origen_check CHECK ((event_id IS NULL) <> (organizacion_id IS NULL))");
            }

            return;
        }
        foreach (['event_actas', 'event_compromisos'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->uuid('event_id')->nullable()->change());
        }
    }

    // Fails on PostgreSQL while an organization has actas (event_id would be null): delete them first, on purpose.
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['event_actas', 'event_compromisos'] as $name) {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_origen_check");
                DB::statement("ALTER TABLE {$name} ALTER COLUMN event_id SET NOT NULL");
            }
        } else {
            foreach (['event_actas', 'event_compromisos'] as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->uuid('event_id')->nullable(false)->change());
            }
        }
        Schema::table('event_acta_participantes', fn (Blueprint $table) => $table->dropColumn('created_miembro'));
        foreach (['event_compromisos', 'event_actas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['organizacion_id']);
                $table->dropIndex(['organizacion_id']);
                $table->dropColumn('organizacion_id');
            });
        }
    }
};
