<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// funcionario_entidades / funcionario_contratos: public-sector contracts of a ficha imported from Electo. Politically
// sensitive (it shows bureaucratic quotas): only the superadmin reads them (App\Models\FuncionarioContrato) and the
// salary is deliberately NOT a column. They are not exposed in the agent_ro schema.
//
// electo_import_rows: lineage of the Electo import, one row per source record (entidad + id in Electo) with the local
// row it became and the action taken. No personal data: ids, action and reason only. It is what makes the import
// resumable and lets a rollback find what was created.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funcionario_entidades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->string('nombre', 200);
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('email', 200)->nullable();
            $table->uuid('municipio_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('origen', 20)->nullable();
            $table->bigInteger('origen_id')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('municipio_id')->references('id')->on('geographic_units')->nullOnDelete();
            $table->index('campaign_id');
        });

        Schema::create('funcionario_contratos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('lider_id');
            $table->uuid('entidad_id');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_final')->nullable();
            $table->date('fecha_terminacion')->nullable();
            $table->string('numero_contrato', 100)->nullable();
            $table->boolean('otrosi')->default(false);
            $table->date('otrosi_fecha_final')->nullable();
            $table->text('observacion')->nullable();
            $table->string('estado', 20)->default('activo');
            $table->string('origen', 20)->nullable();
            $table->bigInteger('origen_id')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('lider_id')->references('id')->on('lideres')->cascadeOnDelete();
            $table->foreign('entidad_id')->references('id')->on('funcionario_entidades');
            $table->index('lider_id');
            $table->index('entidad_id');
        });

        Schema::create('electo_import_rows', function (Blueprint $table) {
            $table->id();
            $table->string('entidad', 30);
            $table->bigInteger('origen_id');
            $table->uuid('local_id')->nullable();
            $table->string('accion', 20);
            $table->string('motivo', 60)->nullable();
            $table->uuid('run_id');
            $table->timestamps();

            $table->unique(['entidad', 'origen_id']);
            $table->index(['entidad', 'accion']);
        });

        foreach (['funcionario_entidades', 'funcionario_contratos'] as $name) {
            DB::statement("CREATE UNIQUE INDEX {$name}_origen_unique ON {$name} (origen, origen_id) WHERE origen IS NOT NULL");
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE electo_import_rows ADD CONSTRAINT electo_import_rows_accion_check CHECK (accion IN ('creado', 'fusionado', 'omitido'))");
            DB::statement('REVOKE ALL ON funcionario_entidades, funcionario_contratos FROM PUBLIC');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('electo_import_rows');
        Schema::dropIfExists('funcionario_contratos');
        Schema::dropIfExists('funcionario_entidades');
    }
};
