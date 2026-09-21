<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electoral_results', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('contest_id');
            $table->uuid('candidacy_id')->nullable()->comment('Null for org-level or list-level results');
            $table->uuid('electoral_list_id')->nullable();
            $table->uuid('organization_id')->nullable()->comment('For party-aggregate results');
            $table->uuid('geographic_unit_id')->comment('At which territorial grain this result applies');
            $table->string('grain', 30)->comment('municipality|province|department|national');
            $table->string('metric_type', 30)->default('votes')->comment('votes|list_votes|nominal_votes|percentage');
            $table->integer('value')->nullable()->comment('Integer vote count or NULL');
            $table->decimal('percentage', 7, 4)->nullable();
            $table->smallInteger('ranking')->nullable();
            $table->string('raw_value', 50)->nullable()->comment('Original value from source before parsing');
            $table->uuid('source_id')->nullable();
            $table->string('status', 20)->default('validated')->comment('staging|validated|observed|disputed');
            $table->timestamps();

            $table->foreign('contest_id')->references('id')->on('contests')->cascadeOnDelete();
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->nullOnDelete();
            $table->foreign('electoral_list_id')->references('id')->on('electoral_lists')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('political_organizations')->nullOnDelete();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units');
            $table->index(['contest_id', 'geographic_unit_id']);
            $table->index(['candidacy_id', 'geographic_unit_id']);
            $table->index(['organization_id', 'contest_id']);
        });

        Schema::create('turnout_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('contest_id')->nullable();
            $table->uuid('electoral_event_id')->nullable();
            $table->uuid('geographic_unit_id');
            $table->string('grain', 30);
            $table->integer('electoral_potential')->nullable();
            $table->integer('total_voters')->nullable()->comment('Sufragantes');
            $table->integer('valid_votes')->nullable();
            $table->integer('blank_votes')->nullable();
            $table->integer('null_votes')->nullable();
            $table->integer('unmarked_votes')->nullable();
            $table->decimal('participation_pct', 7, 4)->nullable();
            $table->string('raw_potential', 50)->nullable();
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('contest_id')->references('id')->on('contests')->nullOnDelete();
            $table->foreign('electoral_event_id')->references('id')->on('electoral_events')->nullOnDelete();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units');
            $table->index(['contest_id', 'geographic_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnout_metrics');
        Schema::dropIfExists('electoral_results');
    }
};
