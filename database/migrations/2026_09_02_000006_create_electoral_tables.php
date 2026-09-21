<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 100)->comment('Alcaldía, Gobernación');
            $table->string('type', 30)->comment('executive|legislative');
            $table->boolean('is_uninominal')->default(true)->comment('true for Alcaldía/Gobernación, false for Concejo/Cámara');
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('corporations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 100)->comment('Concejo, Asamblea, Cámara, Senado');
            $table->string('scope', 30)->comment('municipal|departmental|national');
            $table->smallInteger('default_seats')->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('electoral_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 200)->comment('e.g. Elecciones territoriales 2023');
            $table->string('event_type', 50)->comment('territorial|legislative|presidential|consultation|plebiscite');
            $table->date('election_date');
            $table->string('political_period', 20)->nullable()->comment('e.g. 2024-2027');
            $table->string('authority', 100)->nullable()->comment('Registraduría, CNE');
            $table->string('scope', 30)->default('departmental')->comment('municipal|departmental|national');
            $table->string('status', 20)->default('official')->comment('preliminary|official|annulled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('election_date');
            $table->index('event_type');
        });

        // Add FK from coalitions to electoral_events now that table exists
        Schema::table('coalitions', function (Blueprint $table) {
            $table->foreign('electoral_event_id')->references('id')->on('electoral_events')->nullOnDelete();
        });

        Schema::create('electoral_districts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 150);
            $table->string('type', 50)->comment('municipal|provincial|departmental|national|special');
            $table->uuid('geographic_unit_id')->nullable();
            $table->smallInteger('seats')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->nullOnDelete();
        });

        Schema::create('contests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('electoral_event_id');
            $table->uuid('office_id')->nullable()->comment('For executive positions');
            $table->uuid('corporation_id')->nullable()->comment('For legislative bodies');
            $table->uuid('electoral_district_id')->nullable();
            $table->uuid('geographic_unit_id')->nullable()->comment('Territory of this contest');
            $table->string('name', 250)->comment('e.g. Alcaldía Bucaramanga 2023');
            $table->smallInteger('seats')->default(1);
            $table->string('list_type', 30)->nullable()->comment('open|closed|preferential|na');
            $table->string('status', 20)->default('official');
            $table->timestamps();

            $table->foreign('electoral_event_id')->references('id')->on('electoral_events')->cascadeOnDelete();
            $table->foreign('office_id')->references('id')->on('offices')->nullOnDelete();
            $table->foreign('corporation_id')->references('id')->on('corporations')->nullOnDelete();
            $table->foreign('electoral_district_id')->references('id')->on('electoral_districts')->nullOnDelete();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->nullOnDelete();
            $table->index(['electoral_event_id', 'geographic_unit_id']);
        });

        // Add FK from coalitions to contests
        Schema::table('coalitions', function (Blueprint $table) {
            $table->foreign('contest_id')->references('id')->on('contests')->nullOnDelete();
        });

        Schema::create('electoral_lists', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('contest_id');
            $table->uuid('organization_id')->nullable();
            $table->string('list_name', 200)->nullable();
            $table->string('list_number', 20)->nullable();
            $table->string('modality', 30)->nullable()->comment('open|closed|preferential');
            $table->timestamps();

            $table->foreign('contest_id')->references('id')->on('contests')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('political_organizations')->nullOnDelete();
        });

        Schema::create('candidacies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_id')->nullable()->comment('Null for closed-list-only entries');
            $table->uuid('contest_id');
            $table->uuid('electoral_list_id')->nullable();
            $table->smallInteger('list_position')->nullable();
            $table->string('outcome', 30)->nullable()->comment('elected|not_elected|declined|annulled|pending');
            $table->string('outcome_source', 100)->nullable();
            $table->date('outcome_date')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('contest_id')->references('id')->on('contests')->cascadeOnDelete();
            $table->foreign('electoral_list_id')->references('id')->on('electoral_lists')->nullOnDelete();
            $table->unique(['person_id', 'contest_id']);
            $table->index(['contest_id', 'outcome']);
        });

        Schema::create('candidacy_endorsements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('candidacy_id');
            $table->uuid('organization_id')->nullable();
            $table->uuid('coalition_id')->nullable();
            $table->string('endorsement_type', 50)->comment('aval|coaval|support|coalition_member');
            $table->boolean('is_primary')->default(false);
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('candidacy_id')->references('id')->on('candidacies')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('political_organizations')->nullOnDelete();
            $table->foreign('coalition_id')->references('id')->on('coalitions')->nullOnDelete();
        });

        Schema::create('office_tenures', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_id');
            $table->uuid('office_id')->nullable();
            $table->uuid('corporation_id')->nullable();
            $table->uuid('geographic_unit_id')->nullable();
            $table->uuid('candidacy_id')->nullable()->comment('From which candidacy this tenure originated');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active')->comment('active|finished|resigned|removed|replaced');
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->foreign('office_id')->references('id')->on('offices')->nullOnDelete();
            $table->foreign('corporation_id')->references('id')->on('corporations')->nullOnDelete();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->nullOnDelete();
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->nullOnDelete();
            $table->index(['person_id', 'start_date']);
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_id');
            $table->uuid('organization_id');
            $table->string('role', 80)->nullable()->comment('member|leader|founder|president|secretary');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('political_organizations')->cascadeOnDelete();
            $table->index(['person_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('office_tenures');
        Schema::dropIfExists('candidacy_endorsements');
        Schema::dropIfExists('candidacies');
        Schema::dropIfExists('electoral_lists');

        Schema::table('coalitions', function (Blueprint $table) {
            $table->dropForeign(['contest_id']);
            $table->dropForeign(['electoral_event_id']);
        });

        Schema::dropIfExists('contests');
        Schema::dropIfExists('electoral_districts');
        Schema::dropIfExists('electoral_events');
        Schema::dropIfExists('corporations');
        Schema::dropIfExists('offices');
    }
};
