<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('title', 250);
            $table->string('mime_type', 100)->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256_hash', 64)->nullable();
            $table->string('data_classification', 20)->default('internal');
            $table->string('status', 20)->default('active')->comment('active|archived|deleted');
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('document_id');
            $table->smallInteger('version_number')->default(1);
            $table->string('storage_path');
            $table->string('sha256_hash', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('document_associations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('document_id');
            $table->string('associable_type', 80)->comment('person|geographic_unit|electoral_event|political_organization|activity');
            $table->uuid('associable_id');
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->index(['associable_type', 'associable_id']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('type', 50)->comment('meeting|event|recognition|public_participation|note|other');
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('date_precision', 10)->default('day');
            $table->uuid('geographic_unit_id')->nullable();
            $table->string('data_classification', 20)->default('internal');
            $table->uuid('source_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('type');
            $table->index('occurred_at');
        });

        // PostGIS point for activity location
        DB::statement("SELECT AddGeometryColumn('public', 'activities', 'location', 4326, 'POINT', 2)");

        Schema::create('activity_participants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('activity_id');
            $table->string('participant_type', 50)->comment('person|political_organization');
            $table->uuid('participant_id');
            $table->string('role', 50)->nullable()->comment('organizer|attendee|speaker|mentioned');
            $table->timestamps();

            $table->foreign('activity_id')->references('id')->on('activities')->cascadeOnDelete();
            $table->index(['participant_type', 'participant_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 80)->unique();
            $table->string('category', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->uuid('tag_id');
            $table->string('taggable_type', 80);
            $table->uuid('taggable_id');
            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('activity_participants');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('document_associations');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
