<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Eventos especiales, phase 3: attendees loaded from an Excel/CSV file go through a staging area (event_imports and
// event_import_rows) before RegisterAttendee creates them with source = 'excel' and import_id = the import.
// data_consents is NOT touched (its owner in production is postgres): the consent of an Excel row is stored with the
// already allowed channel 'operador'; the Excel origin lives in event_attendees (source, import_id) and in the import
// tables, which keep who confirmed, when and the signed-sheets declaration per imported row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('campaign_id');
            $table->string('original_name', 255);
            $table->string('file_path', 255);
            $table->char('file_sha256', 64);
            $table->unsignedInteger('header_row')->default(1);
            $table->jsonb('headers');
            $table->jsonb('column_mapping')->nullable();
            $table->string('status', 20)->default('subido');
            $table->unsignedInteger('total_rows')->default(0);
            $table->jsonb('counts')->nullable();
            $table->uuid('consent_text_id')->nullable();
            $table->boolean('consent_declared')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('staging_purged_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('consent_text_id')->references('id')->on('consent_texts');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['event_id', 'file_sha256']);
            $table->index(['campaign_id', 'updated_at']);
            $table->index(['status', 'created_at']);
        });

        // raw/mapped hold personal data: eventos:limpiar-importaciones empties them 30 days after the confirmation.
        Schema::create('event_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id');
            $table->unsignedInteger('row_number');
            $table->jsonb('raw')->nullable();
            $table->jsonb('mapped')->nullable();
            $table->jsonb('errors')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->uuid('match_lider_id')->nullable();
            $table->string('decision', 20)->default('omitir');
            $table->boolean('edited')->default(false);
            $table->uuid('attendee_id')->nullable();
            $table->boolean('declared')->default(false);
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('import_id')->references('id')->on('event_imports')->cascadeOnDelete();
            $table->foreign('match_lider_id')->references('id')->on('lideres')->nullOnDelete();
            $table->foreign('attendee_id')->references('id')->on('event_attendees')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['import_id', 'row_number']);
            $table->index(['import_id', 'status']);
        });

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->uuid('import_id')->nullable();
            $table->foreign('import_id')->references('id')->on('event_imports')->nullOnDelete();
            $table->index('import_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_imports ADD CONSTRAINT event_imports_status_check CHECK (status IN ('subido', 'revisado', 'confirmado'))");
            DB::statement("ALTER TABLE event_import_rows ADD CONSTRAINT event_import_rows_status_check CHECK (status IN ('pendiente', 'valida', 'error', 'no_autorizo', 'duplicada', 'en_evento'))");
            DB::statement("ALTER TABLE event_import_rows ADD CONSTRAINT event_import_rows_decision_check CHECK (decision IN ('crear', 'vincular', 'omitir'))");
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_source_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador', 'excel'))");
        }
    }

    // Fails on PostgreSQL while attendees with source = 'excel' exist: delete or migrate them first, on purpose.
    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropForeign(['import_id']);
            $table->dropIndex(['import_id']);
            $table->dropColumn('import_id');
        });
        Schema::dropIfExists('event_import_rows');
        Schema::dropIfExists('event_imports');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_source_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador'))");
        }
    }
};
