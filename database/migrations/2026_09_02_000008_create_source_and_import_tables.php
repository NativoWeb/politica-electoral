<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_files', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('original_name', 250);
            $table->string('storage_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256_hash', 64)->nullable();
            $table->string('file_type', 20)->comment('xlsx|csv|pdf|image|other');
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->index('sha256_hash');
        });

        Schema::create('mapping_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 150);
            $table->string('dataset_type', 50)->comment('alcaldia|concejo|camara|senado|consulta|gobernacion|asamblea|other');
            $table->jsonb('column_mappings')->comment('Array of {source_column, target_entity, target_field, transformation, validation}');
            $table->smallInteger('header_row')->default(1);
            $table->smallInteger('data_start_row')->default(2);
            $table->smallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('source_file_id');
            $table->uuid('mapping_template_id')->nullable();
            $table->string('status', 30)->default('uploaded')
                ->comment('uploaded|profiled|mapped|validating|needs_review|ready_to_commit|committing|completed|completed_with_issues|failed|rolled_back');
            $table->string('dataset_type', 50)->nullable();
            $table->string('sheet_name', 100)->nullable();
            $table->jsonb('profiling_result')->nullable()->comment('Counts, types, blanks, cardinality');
            $table->integer('total_rows')->nullable();
            $table->integer('valid_rows')->nullable();
            $table->integer('error_rows')->nullable();
            $table->integer('warning_rows')->nullable();
            $table->jsonb('issues')->nullable()->comment('Array of {row, column, type, message, severity}');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('committed_by')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->text('rollback_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('source_file_id')->references('id')->on('source_files');
            $table->foreign('mapping_template_id')->references('id')->on('mapping_templates')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('committed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });

        Schema::create('import_staging_rows', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('import_job_id');
            $table->integer('source_row_number');
            $table->jsonb('raw_data')->comment('Original row as key-value pairs');
            $table->jsonb('normalized_data')->nullable()->comment('After transformations');
            $table->jsonb('matched_entities')->nullable()->comment('{person_id, org_id, geo_id} with scores');
            $table->string('status', 20)->default('pending')->comment('pending|valid|warning|error|committed|skipped');
            $table->jsonb('issues')->nullable();
            $table->timestamps();

            $table->foreign('import_job_id')->references('id')->on('import_jobs')->cascadeOnDelete();
            $table->index(['import_job_id', 'status']);
        });

        Schema::create('source_records', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('source_file_id');
            $table->uuid('import_job_id')->nullable();
            $table->string('sheet_name', 100)->nullable();
            $table->integer('row_number')->nullable();
            $table->string('cell_reference', 20)->nullable()->comment('e.g. D14');
            $table->text('raw_value')->nullable();
            $table->string('target_entity', 80)->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('target_field', 80)->nullable();
            $table->text('transformation_applied')->nullable();
            $table->timestamps();

            $table->foreign('source_file_id')->references('id')->on('source_files');
            $table->foreign('import_job_id')->references('id')->on('import_jobs')->nullOnDelete();
            $table->index(['target_entity', 'target_id']);
        });

        // Now add source_id FKs to all tables that reference source_files
        // These are soft references (nullable, no cascade) so we don't add formal FKs
        // to avoid circular dependencies. The source_id columns already exist.
    }

    public function down(): void
    {
        Schema::dropIfExists('source_records');
        Schema::dropIfExists('import_staging_rows');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('mapping_templates');
        Schema::dropIfExists('source_files');
    }
};
