<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Eventos especiales, phase 5: printed sign-in sheets read with AI (docs/eventos-api.md §13). Additive only:
// campaigns.ocr_enabled (off), event_imports.kind (excel | ocr), the photographed pages of an OCR load
// (event_import_pages, private files) and the page and per-field confidence of each extracted row. The attendees it
// confirms get source = 'ocr'. data_consents is NOT touched (owner postgres in production): the proof keeps the
// channel 'operador', as the Excel load.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('ocr_enabled')->default(false);
        });

        Schema::table('event_imports', function (Blueprint $table) {
            $table->string('kind', 10)->default('excel');
        });

        Schema::create('event_import_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id');
            $table->uuid('event_id');
            $table->unsignedInteger('sequence');
            $table->string('original_name', 255);
            $table->string('file_path', 255);
            $table->char('file_sha256', 64);
            $table->string('mime', 40);
            $table->unsignedInteger('page_in_file')->default(1);
            $table->unsignedInteger('file_pages')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->string('message', 255)->nullable();
            $table->uuid('qr_event_id')->nullable();
            $table->unsignedInteger('printed_page')->nullable();
            $table->string('qr_origin', 10)->nullable();
            $table->unsignedInteger('rows_found')->default(0);
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('api_calls')->default(0);
            $table->timestamp('last_call_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('import_id')->references('id')->on('event_imports')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events');
            $table->unique(['import_id', 'sequence']);
            $table->unique(['event_id', 'file_sha256', 'page_in_file']);
            $table->index(['status', 'processed_at']);
            $table->index('last_call_at');
        });

        Schema::table('event_import_rows', function (Blueprint $table) {
            $table->uuid('page_id')->nullable();
            $table->unsignedSmallInteger('page_row')->nullable();
            $table->jsonb('confidence')->nullable();
            $table->foreign('page_id')->references('id')->on('event_import_pages')->cascadeOnDelete();
            // One row per printed row of a page: two readings of the same page can never both be stored.
            $table->unique(['page_id', 'page_row']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_imports ADD CONSTRAINT event_imports_kind_check CHECK (kind IN ('excel', 'ocr'))");
            DB::statement("ALTER TABLE event_import_pages ADD CONSTRAINT event_import_pages_status_check CHECK (status IN ('pendiente', 'procesada', 'rechazada', 'error'))");
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_source_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador', 'excel', 'ocr'))");
        }
    }

    // Fails on PostgreSQL while attendees with source = 'ocr' exist: delete or migrate them first, on purpose.
    // The photo files of OCR loads stay on disk (storage/app/private/eventos/ocr): delete them by hand if needed.
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_source_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador', 'excel'))");
        }

        DB::table('event_imports')->where('kind', 'ocr')->delete();

        Schema::table('event_import_rows', function (Blueprint $table) {
            $table->dropForeign(['page_id']);
            $table->dropUnique(['page_id', 'page_row']);
            $table->dropColumn(['page_id', 'page_row', 'confidence']);
        });
        Schema::dropIfExists('event_import_pages');
        Schema::table('event_imports', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('ocr_enabled');
        });
    }
};
