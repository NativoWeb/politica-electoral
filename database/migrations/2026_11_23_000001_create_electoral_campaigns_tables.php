<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Campañas electorales (docs/campanas-electorales.md): a contest a grupo político prepares (campaign_id is the owner
// group, never the electoral campaign itself), the units it covers and a goal per municipio, comuna or corregimiento.
// Nothing here alters a table owned by postgres: the foreign keys only need REFERENCES on geographic_units/persons.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electoral_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->string('name', 120);
            $table->string('cargo', 20);
            $table->smallInteger('election_year');
            $table->date('election_date')->nullable();
            $table->uuid('candidate_person_id')->nullable();
            $table->string('candidate_name', 160)->nullable();
            $table->uuid('political_organization_id')->nullable();
            $table->string('scope_level', 20);
            $table->decimal('factor_votos', 4, 2)->default(1.10);
            $table->decimal('factor_registro', 4, 2)->default(1.00);
            $table->integer('votos_por_lider')->default(50);
            $table->string('status', 10)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->restrictOnDelete();
            $table->foreign('candidate_person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('political_organization_id')->references('id')->on('political_organizations')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['campaign_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX electoral_campaigns_active_name_unique ON electoral_campaigns (campaign_id, lower(name)) WHERE status = 'active'");

        Schema::create('electoral_campaign_territories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('electoral_campaign_id');
            $table->uuid('geographic_unit_id');
            $table->timestamps();

            $table->foreign('electoral_campaign_id')->references('id')->on('electoral_campaigns')->cascadeOnDelete();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->restrictOnDelete();
            $table->unique(['electoral_campaign_id', 'geographic_unit_id'], 'electoral_campaign_territories_unique');
        });

        Schema::create('electoral_campaign_goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('electoral_campaign_id');
            $table->uuid('geographic_unit_id');
            $table->integer('votos_meta')->nullable();
            $table->integer('lideres_meta')->nullable();
            $table->integer('votantes_meta')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('electoral_campaign_id')->references('id')->on('electoral_campaigns')->cascadeOnDelete();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['electoral_campaign_id', 'geographic_unit_id'], 'electoral_campaign_goals_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_cargo_check CHECK (cargo IN ('gobernacion', 'alcaldia', 'concejo', 'asamblea', 'senado', 'camara', 'jal'))");
            DB::statement("ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_scope_level_check CHECK (scope_level IN ('department', 'municipality', 'comuna'))");
            DB::statement("ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_status_check CHECK (status IN ('active', 'archived'))");
            DB::statement('ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_election_year_check CHECK (election_year BETWEEN 2024 AND 2040)');
            DB::statement('ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_factor_votos_check CHECK (factor_votos BETWEEN 0.50 AND 3.00)');
            DB::statement('ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_factor_registro_check CHECK (factor_registro BETWEEN 0.10 AND 3.00)');
            DB::statement('ALTER TABLE electoral_campaigns ADD CONSTRAINT electoral_campaigns_votos_por_lider_check CHECK (votos_por_lider BETWEEN 5 AND 1000)');
            DB::statement('ALTER TABLE electoral_campaign_goals ADD CONSTRAINT electoral_campaign_goals_values_check CHECK ((votos_meta IS NULL OR votos_meta >= 0) AND (lideres_meta IS NULL OR lideres_meta >= 0) AND (votantes_meta IS NULL OR votantes_meta >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('electoral_campaign_goals');
        Schema::dropIfExists('electoral_campaign_territories');
        Schema::dropIfExists('electoral_campaigns');
    }
};
