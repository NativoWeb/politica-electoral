<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Comunas and corregimientos are geographic_units below their municipio (type 'comuna' | 'corregimiento', loaded by
// geo:import-comunas); metadata keeps their number, population and source. barrios maps each barrio or vereda to
// its comuna; barrio_aliases are the texts a grupo político assigned by hand (docs/geografia.md).
return new class extends Migration
{
    public function up(): void
    {
        // In production geographic_units belongs to postgres: its owner adds the column beforehand
        // (ALTER TABLE geographic_units ADD COLUMN metadata jsonb), so it is only added here when missing.
        if (! Schema::hasColumn('geographic_units', 'metadata')) {
            Schema::table('geographic_units', function (Blueprint $table) {
                $table->jsonb('metadata')->nullable();
            });
        }

        Schema::create('barrios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('municipio_id');
            $table->uuid('comuna_unit_id');
            $table->string('nombre', 150);
            $table->string('nombre_normalizado', 150);
            $table->string('fuente', 20)->comment('amb|planeacion|datosgov');
            $table->integer('poblacion')->nullable()->comment('datos.gov.co kn95-8dei, 2024');
            $table->timestamps();

            $table->foreign('municipio_id')->references('id')->on('geographic_units')->cascadeOnDelete();
            $table->foreign('comuna_unit_id')->references('id')->on('geographic_units')->cascadeOnDelete();
            $table->unique(['municipio_id', 'nombre_normalizado']);
            $table->index('comuna_unit_id');
        });

        Schema::create('barrio_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('municipio_id');
            $table->string('texto_normalizado', 150);
            $table->uuid('barrio_id')->nullable();
            $table->uuid('comuna_unit_id');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('municipio_id')->references('id')->on('geographic_units')->cascadeOnDelete();
            $table->foreign('barrio_id')->references('id')->on('barrios')->nullOnDelete();
            $table->foreign('comuna_unit_id')->references('id')->on('geographic_units')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['campaign_id', 'municipio_id', 'texto_normalizado']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE barrios ADD COLUMN geom geometry(MultiPolygon, 4326)');
            DB::statement('CREATE INDEX barrios_geom_gist ON barrios USING GIST (geom)');
            DB::statement('CREATE INDEX barrios_nombre_normalizado_trgm ON barrios USING GIN (nombre_normalizado gin_trgm_ops)');
            DB::statement("ALTER TABLE barrios ADD CONSTRAINT barrios_fuente_check CHECK (fuente IN ('amb', 'planeacion', 'datosgov'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('barrio_aliases');
        Schema::dropIfExists('barrios');
        DB::table('geographic_units')->whereIn('type', ['comuna', 'corregimiento'])->delete();
        Schema::table('geographic_units', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
