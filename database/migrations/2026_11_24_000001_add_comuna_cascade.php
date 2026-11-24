<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The comuna cascade (docs/geografia.md): comuna_source also takes 'texto' ("COMUNA 5" written), 'direccion' (phase B)
// and 'puesto' (the polling station); comuna_cambios keeps every change of geo:asignar-comunas by batch so it can be
// reverted; puestos_votacion is the Registraduría's list of polling stations with their comuna. Only constraints are
// replaced on the existing tables (never ->change(): the agent_ro views depend on lideres).
return new class extends Migration
{
    private const TABLES = ['lideres', 'event_attendees', 'organizaciones'];

    private const NEW_SOURCES = ['texto', 'direccion', 'puesto'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::TABLES as $name) {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_comuna_source_check");
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_comuna_source_check CHECK (comuna_source IN ('auto', 'texto', 'direccion', 'puesto', 'manual'))");
            }
        }

        Schema::create('comuna_cambios', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('lote');
            $table->string('tabla', 30);
            $table->uuid('registro_id');
            $table->uuid('antes_comuna_id')->nullable();
            $table->string('antes_source', 10)->nullable();
            $table->uuid('despues_comuna_id')->nullable();
            $table->string('despues_source', 10)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('lote');
        });

        Schema::create('puestos_votacion', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('municipio_id');
            $table->string('nombre', 200);
            $table->string('nombre_normalizado', 200);
            $table->uuid('comuna_unit_id')->nullable();
            $table->string('comuna_origen', 10)->nullable()->comment('dataset|poligono');
            $table->string('direccion', 300)->nullable();
            $table->string('fuente', 60);
            $table->smallInteger('anio');
            $table->jsonb('alias')->nullable();
            $table->timestamps();

            $table->foreign('municipio_id')->references('id')->on('geographic_units')->cascadeOnDelete();
            $table->foreign('comuna_unit_id')->references('id')->on('geographic_units')->nullOnDelete();
            $table->unique(['municipio_id', 'nombre_normalizado']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE puestos_votacion ADD COLUMN geom geometry(Point, 4326)');
            DB::statement('CREATE INDEX puestos_votacion_nombre_trgm ON puestos_votacion USING GIN (nombre_normalizado gin_trgm_ops)');
            DB::statement("ALTER TABLE puestos_votacion ADD CONSTRAINT puestos_votacion_comuna_origen_check CHECK (comuna_origen IN ('dataset', 'poligono'))");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $n = DB::table($name)->whereIn('comuna_source', self::NEW_SOURCES)->count();
            if ($n > 0) {
                throw new RuntimeException("No se puede revertir: {$name} tiene {$n} registros con comuna por texto, dirección o puesto. "
                    .'Reviértalos antes con geo:revertir-comunas --lote=… (o pase esos registros a sin comuna).');
            }
        }

        Schema::dropIfExists('puestos_votacion');
        Schema::dropIfExists('comuna_cambios');

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::TABLES as $name) {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_comuna_source_check");
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_comuna_source_check CHECK (comuna_source IN ('auto', 'manual'))");
            }
        }
    }
};
