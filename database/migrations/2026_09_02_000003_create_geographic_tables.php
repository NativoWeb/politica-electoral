<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geographic_units', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('parent_id')->nullable();
            $table->string('type', 30)->comment('country|department|province|subregion|municipality|district');
            $table->string('canonical_name', 150);
            $table->string('official_code', 20)->nullable()->comment('DANE code or similar');
            $table->smallInteger('level')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type');
            $table->index('parent_id');
            $table->unique(['official_code', 'type']);
        });

        // Self-referencing FK must be added after table creation
        Schema::table('geographic_units', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('geographic_units')->nullOnDelete();
        });

        // PostGIS geometry columns using native type syntax (PostGIS 2+)
        DB::statement("ALTER TABLE geographic_units ADD COLUMN geom geometry(MultiPolygon, 4326)");
        DB::statement("ALTER TABLE geographic_units ADD COLUMN geom_simplified geometry(MultiPolygon, 4326)");
        DB::statement("CREATE INDEX idx_geographic_units_geom ON geographic_units USING GIST (geom)");

        Schema::create('geographic_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('geographic_unit_id');
            $table->string('raw_alias', 200);
            $table->string('normalized_alias', 200)->comment('Uppercase, no accents, trimmed');
            $table->uuid('source_id')->nullable()->comment('FK to source_files');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units')->cascadeOnDelete();
            $table->index('normalized_alias');
        });

        // Trigram index for fuzzy search on geographic aliases
        DB::statement("CREATE INDEX idx_geo_alias_trgm ON geographic_aliases USING GIN (normalized_alias gin_trgm_ops)");
    }

    public function down(): void
    {
        Schema::dropIfExists('geographic_aliases');
        Schema::dropIfExists('geographic_units');
    }
};
