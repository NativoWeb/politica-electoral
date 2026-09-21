<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // #9: Index on electoral_results for candidacy+metric and geo+metric
        Schema::table('electoral_results', function (Blueprint $table) {
            $table->index(['candidacy_id', 'metric_type'], 'idx_er_candidacy_metric');
            $table->index(['geographic_unit_id', 'metric_type'], 'idx_er_geo_metric');
        });

        // #10: Indexes on lideres table
        Schema::table('lideres', function (Blueprint $table) {
            $table->index('geographic_unit_id', 'idx_lideres_geo');
        });
        DB::statement("CREATE INDEX IF NOT EXISTS idx_lideres_nombre_trgm ON lideres USING gin (nombre gin_trgm_ops)");

        // #11: Index on nexos_familiares.person_id
        Schema::table('nexos_familiares', function (Blueprint $table) {
            $table->index('person_id', 'idx_nexos_person');
        });

        // #12: Composite index on candidacy_endorsements
        Schema::table('candidacy_endorsements', function (Blueprint $table) {
            $table->index(['candidacy_id', 'is_primary'], 'idx_ce_candidacy_primary');
        });

        // #16: Drop FK on nexos_familiares so person_id can reference both persons and lideres
        Schema::table('nexos_familiares', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
        });
    }

    public function down(): void
    {
        Schema::table('electoral_results', function (Blueprint $table) {
            $table->dropIndex('idx_er_candidacy_metric');
            $table->dropIndex('idx_er_geo_metric');
        });
        Schema::table('lideres', function (Blueprint $table) {
            $table->dropIndex('idx_lideres_geo');
        });
        DB::statement("DROP INDEX IF EXISTS idx_lideres_nombre_trgm");
        Schema::table('nexos_familiares', function (Blueprint $table) {
            $table->dropIndex('idx_nexos_person');
            $table->foreign('person_id')->references('id')->on('persons');
        });
        Schema::table('candidacy_endorsements', function (Blueprint $table) {
            $table->dropIndex('idx_ce_candidacy_primary');
        });
    }
};
