<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('political_organizations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('type', 30)->comment('party|movement|significant_group|coalition_entity');
            $table->string('canonical_name', 200);
            $table->string('acronym', 30)->nullable();
            $table->string('color_hex', 7)->nullable()->comment('Official party color');
            $table->string('logo_path')->nullable();
            $table->string('status', 20)->default('active')->comment('active|inactive|dissolved|merged');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->timestamps();

            $table->index('canonical_name');
            $table->index('acronym');
        });

        // Self-referencing FK added after table exists
        Schema::table('political_organizations', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('political_organizations')->nullOnDelete();
        });

        DB::statement("CREATE INDEX idx_org_name_trgm ON political_organizations USING GIN (canonical_name gin_trgm_ops)");

        Schema::create('organization_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('organization_id');
            $table->string('raw_alias', 250);
            $table->string('normalized_alias', 250);
            $table->uuid('source_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('political_organizations')->cascadeOnDelete();
            $table->index('normalized_alias');
        });

        DB::statement("CREATE INDEX idx_org_alias_trgm ON organization_aliases USING GIN (normalized_alias gin_trgm_ops)");

        Schema::create('coalitions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 200);
            $table->string('purpose', 100)->nullable()->comment('electoral|legislative|programmatic');
            $table->uuid('electoral_event_id')->nullable()->comment('FK added after electoral_events table');
            $table->uuid('contest_id')->nullable()->comment('FK added after contests table');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('coalition_members', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('coalition_id');
            $table->uuid('organization_id');
            $table->string('role', 50)->nullable()->comment('lead|member|support');
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->timestamps();

            $table->foreign('coalition_id')->references('id')->on('coalitions')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('political_organizations')->cascadeOnDelete();
            $table->unique(['coalition_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coalition_members');
        Schema::dropIfExists('coalitions');
        Schema::dropIfExists('organization_aliases');
        Schema::dropIfExists('political_organizations');
    }
};
