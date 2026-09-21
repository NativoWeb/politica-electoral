<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('first_name', 150);
            $table->string('last_name', 150);
            $table->string('full_name', 300)->comment('Canonical display name');
            $table->string('normalized_name', 300)->comment('Uppercase, no accents, for matching');
            $table->string('photo_path')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('document_type', 20)->nullable()->comment('CC, CE, etc.');
            $table->string('doc_hash', 64)->nullable()->comment('SHA-256 of document number');
            $table->string('doc_last4', 4)->nullable()->comment('Last 4 digits');
            $table->string('identity_status', 30)->default('unverified')->comment('unverified|verified|merged|suppressed');
            $table->uuid('merged_into_id')->nullable()->comment('If merged, points to survivor');
            $table->string('data_classification', 20)->default('internal')->comment('public|internal|confidential|restricted');
            $table->timestamps();
            $table->softDeletes();

            $table->index('normalized_name');
            $table->index('identity_status');
            $table->index('doc_hash');
        });

        // Self-referencing FK added after table exists
        Schema::table('persons', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('persons')->nullOnDelete();
        });

        // Trigram indexes for fuzzy search
        DB::statement("CREATE INDEX idx_persons_name_trgm ON persons USING GIN (normalized_name gin_trgm_ops)");
        DB::statement("CREATE INDEX idx_persons_fullname_trgm ON persons USING GIN (full_name gin_trgm_ops)");

        Schema::create('person_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_id');
            $table->string('alias_name', 300);
            $table->string('normalized_alias', 300);
            $table->string('alias_type', 30)->default('name')->comment('name|nickname|maiden|typo|electoral');
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->index('normalized_alias');
        });

        DB::statement("CREATE INDEX idx_person_alias_trgm ON person_aliases USING GIN (normalized_alias gin_trgm_ops)");

        Schema::create('person_contact_points', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_id');
            $table->string('type', 30)->comment('phone|mobile|whatsapp|email|social');
            $table->string('value_raw', 200);
            $table->string('value_normalized', 200)->nullable()->comment('E.164 for phone, lowercase for email');
            $table->string('label', 50)->nullable()->comment('personal|work|campaign');
            $table->boolean('is_current')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('data_classification', 20)->default('confidential');
            $table->boolean('allow_export')->default(false);
            $table->boolean('allow_offline')->default(false);
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->index(['person_id', 'type']);
        });

        Schema::create('person_facts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_id');
            $table->string('category', 50)->comment('professional|education|recognition|biographical|contextual');
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('date_precision', 10)->default('day')->comment('year|month|day');
            $table->string('confidence', 20)->default('unverified')->comment('confirmed|verified|unverified|reference');
            $table->string('data_classification', 20)->default('internal');
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->index(['person_id', 'category']);
        });

        Schema::create('relationships', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('person_a_id');
            $table->uuid('person_b_id');
            $table->string('type', 50)->comment('familiar|political|labor|institutional|electoral|professional|ally');
            $table->string('subtype', 80)->nullable()->comment('e.g. spouse, mentor, colleague');
            $table->boolean('is_symmetric')->default(true);
            $table->string('direction_label', 100)->nullable()->comment('e.g. "works for" from A to B');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('confidence', 20)->default('unverified');
            $table->string('data_classification', 20)->default('internal');
            $table->uuid('source_id')->nullable();
            $table->timestamps();

            $table->foreign('person_a_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->foreign('person_b_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->index(['person_a_id', 'type']);
            $table->index(['person_b_id', 'type']);
        });

        Schema::create('person_merge_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('survivor_id');
            $table->uuid('absorbed_id');
            $table->uuid('performed_by')->nullable();
            $table->float('match_score')->nullable();
            $table->jsonb('match_evidence')->nullable();
            $table->jsonb('before_snapshot')->nullable();
            $table->string('status', 20)->default('completed')->comment('completed|rolled_back');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('survivor_id')->references('id')->on('persons');
            $table->foreign('absorbed_id')->references('id')->on('persons');
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_merge_transactions');
        Schema::dropIfExists('relationships');
        Schema::dropIfExists('person_facts');
        Schema::dropIfExists('person_contact_points');
        Schema::dropIfExists('person_aliases');
        Schema::dropIfExists('persons');
    }
};
