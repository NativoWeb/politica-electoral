<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Eventos especiales: lists of attendees without cédula or authorization (docs/eventos-api.md §11, "Listas sin
// autorización"). Additive only: the attendee's note (the list's "liderazgo"), the suggestion by phone + name of each
// import row and whether the row came without authorization, plus the new row statuses (sugerida, sin_ficha) and the
// attendee status sin_autorizacion (kept only in the event's list, never approvable to Votantes).
// data_consents is NOT touched (owner postgres in production).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->string('nota', 255)->nullable();
        });

        // A workbook with one sheet per jornada: the sheet loaded for this event and the list to choose from.
        Schema::table('event_imports', function (Blueprint $table) {
            $table->string('sheet_name', 100)->nullable();
            $table->jsonb('sheets')->nullable();
        });

        Schema::table('event_import_rows', function (Blueprint $table) {
            $table->boolean('sin_autorizacion')->default(false);
            $table->uuid('suggested_lider_id')->nullable();
            $table->string('suggestion', 12)->nullable();
            $table->foreign('suggested_lider_id')->references('id')->on('lideres')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_import_rows DROP CONSTRAINT IF EXISTS event_import_rows_status_check');
            DB::statement("ALTER TABLE event_import_rows ADD CONSTRAINT event_import_rows_status_check CHECK (status IN ('pendiente', 'valida', 'error', 'no_autorizo', 'duplicada', 'en_evento', 'sugerida', 'sin_ficha'))");
            DB::statement("ALTER TABLE event_import_rows ADD CONSTRAINT event_import_rows_suggestion_check CHECK (suggestion IS NULL OR suggestion IN ('confirmada', 'rechazada'))");
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_link_status_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_link_status_check CHECK (link_status IN ('vinculado', 'pendiente', 'sin_cedula', 'aprobado', 'descartado', 'sin_autorizacion'))");
        }
    }

    // Fails on PostgreSQL while rows or attendees with the new statuses exist: delete or migrate them first, on purpose.
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_link_status_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_link_status_check CHECK (link_status IN ('vinculado', 'pendiente', 'sin_cedula', 'aprobado', 'descartado'))");
            DB::statement('ALTER TABLE event_import_rows DROP CONSTRAINT IF EXISTS event_import_rows_suggestion_check');
            DB::statement('ALTER TABLE event_import_rows DROP CONSTRAINT IF EXISTS event_import_rows_status_check');
            DB::statement("ALTER TABLE event_import_rows ADD CONSTRAINT event_import_rows_status_check CHECK (status IN ('pendiente', 'valida', 'error', 'no_autorizo', 'duplicada', 'en_evento'))");
        }

        Schema::table('event_import_rows', function (Blueprint $table) {
            $table->dropForeign(['suggested_lider_id']);
            $table->dropColumn(['sin_autorizacion', 'suggested_lider_id', 'suggestion']);
        });
        Schema::table('event_imports', function (Blueprint $table) {
            $table->dropColumn(['sheet_name', 'sheets']);
        });
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropColumn('nota');
        });
    }
};
