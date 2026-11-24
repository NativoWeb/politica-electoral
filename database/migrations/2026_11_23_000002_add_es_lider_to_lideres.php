<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Es líder (cuenta para las metas)": a manual mark of the ficha, false for everyone (nobody is marked automatically).
// Additive only, never ->change(): the agent_ro views depend on lideres.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lideres', function (Blueprint $table) {
            $table->boolean('es_lider')->default(false);
        });
        DB::statement('CREATE INDEX lideres_es_lider_geo_idx ON lideres (campaign_id, geographic_unit_id) WHERE es_lider');
        DB::statement('CREATE INDEX lideres_es_lider_comuna_idx ON lideres (campaign_id, comuna_id) WHERE es_lider');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lideres_es_lider_comuna_idx');
        DB::statement('DROP INDEX IF EXISTS lideres_es_lider_geo_idx');
        Schema::table('lideres', function (Blueprint $table) {
            $table->dropColumn('es_lider');
        });
    }
};
