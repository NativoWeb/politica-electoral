<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->nullable();
            $table->string('action', 80);
            $table->string('entity_type', 80)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
            $table->index('correlation_id');
        });

        // Append-only: revoke UPDATE and DELETE
        DB::statement('REVOKE UPDATE, DELETE ON audit_logs FROM PUBLIC');

        Schema::create('favorites', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('favoritable_type', 80)->comment('person|geographic_unit|political_organization|contest');
            $table->uuid('favoritable_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'favoritable_type', 'favoritable_id']);
        });

        Schema::create('recent_views', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('viewable_type', 80);
            $table->uuid('viewable_id');
            $table->timestamp('viewed_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'viewed_at']);
        });

        Schema::create('dataset_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 150);
            $table->string('dataset_type', 50);
            $table->uuid('source_file_id')->nullable();
            $table->uuid('import_job_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->date('data_cutoff_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('source_file_id')->references('id')->on('source_files')->nullOnDelete();
            $table->foreign('import_job_id')->references('id')->on('import_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_versions');
        Schema::dropIfExists('recent_views');
        Schema::dropIfExists('favorites');
        DB::statement('GRANT UPDATE, DELETE ON audit_logs TO PUBLIC');
        Schema::dropIfExists('audit_logs');
    }
};
