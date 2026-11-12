<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Empresarios y gremios (docs/empresarios.md): organizations of a campaign (empresa | gremio), their members (fichas
// of Votantes with a role), empresa ↔ gremio affiliations, the editable catalogs (sector económico, sector) and the
// staging of the Excel load. Additive only: nothing existing is touched.
return new class extends Migration
{
    public const SECTORES_ECONOMICOS = ['Agropecuario', 'Comercio', 'Industria/Manufactura', 'Construcción', 'Transporte y logística',
        'Turismo y hotelería', 'Servicios', 'Tecnología', 'Minería y energía', 'Financiero', 'Salud', 'Educación', 'Otro'];

    public const SECTORES = ['Público', 'Privado', 'Mixto', 'ONG', 'Solidario'];

    public function up(): void
    {
        // campaign_id null = the shared starting values (only a superadmin changes them); otherwise the campaign's own.
        Schema::create('org_catalogos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->nullable();
            $table->string('tipo', 30);
            $table->string('nombre', 120);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->index(['tipo', 'campaign_id']);
        });

        Schema::create('organizaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->string('tipo', 10);
            $table->string('nombre', 255);
            $table->string('nit', 10)->nullable();
            $table->string('nit_dv', 1)->nullable();
            $table->string('representante_nombre', 200)->nullable();
            $table->string('representante_cedula', 20)->nullable();
            $table->string('representante_celular', 60)->nullable();
            $table->uuid('representante_lider_id')->nullable();
            $table->string('telefono', 60)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('sitio_web', 255)->nullable();
            $table->uuid('sector_economico_id')->nullable();
            $table->uuid('sector_id')->nullable();
            $table->string('departamento', 80)->nullable();
            $table->uuid('municipio_id')->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('barrio', 150)->nullable();
            $table->unsignedInteger('empleados')->nullable();
            $table->string('empleados_rango', 20)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('representante_lider_id')->references('id')->on('lideres')->nullOnDelete();
            $table->foreign('sector_economico_id')->references('id')->on('org_catalogos');
            $table->foreign('sector_id')->references('id')->on('org_catalogos');
            $table->foreign('municipio_id')->references('id')->on('geographic_units');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['campaign_id', 'tipo']);
            $table->index('municipio_id');
            $table->index('representante_lider_id');
        });
        // One NIT per campaign among the organizations not deleted.
        DB::statement('CREATE UNIQUE INDEX organizaciones_campaign_nit_unique ON organizaciones (campaign_id, nit) WHERE nit IS NOT NULL AND deleted_at IS NULL');

        Schema::create('organizacion_miembros', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organizacion_id');
            $table->uuid('lider_id');
            $table->string('rol', 30);
            $table->string('nota', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organizacion_id')->references('id')->on('organizaciones')->cascadeOnDelete();
            $table->foreign('lider_id')->references('id')->on('lideres')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['organizacion_id', 'lider_id', 'rol']);
            $table->index('lider_id');
        });

        Schema::create('organizacion_gremios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('empresa_id');
            $table->uuid('gremio_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('empresa_id')->references('id')->on('organizaciones')->cascadeOnDelete();
            $table->foreign('gremio_id')->references('id')->on('organizaciones')->cascadeOnDelete();
            $table->unique(['empresa_id', 'gremio_id']);
            $table->index('gremio_id');
        });

        Schema::create('organizacion_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('user_id')->nullable();
            $table->string('archivo_nombre', 255)->nullable();
            $table->string('estado', 20)->default('revision');
            $table->json('resumen')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['campaign_id', 'estado']);
        });

        Schema::create('organizacion_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id');
            $table->unsignedInteger('row_number');
            $table->json('datos');
            $table->json('errores')->nullable();
            $table->uuid('existente_id')->nullable();
            $table->string('accion', 20);
            $table->uuid('organizacion_id')->nullable();
            $table->timestamps();

            $table->foreign('import_id')->references('id')->on('organizacion_imports')->cascadeOnDelete();
            $table->index(['import_id', 'row_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE org_catalogos ADD CONSTRAINT org_catalogos_tipo_check CHECK (tipo IN ('sector_economico', 'sector'))");
            DB::statement("ALTER TABLE organizaciones ADD CONSTRAINT organizaciones_tipo_check CHECK (tipo IN ('empresa', 'gremio'))");
            DB::statement("ALTER TABLE organizaciones ADD CONSTRAINT organizaciones_rango_check CHECK (empleados_rango IS NULL OR empleados_rango IN ('1-10', '11-50', '51-200', '201-500', '500+'))");
            DB::statement("ALTER TABLE organizacion_miembros ADD CONSTRAINT organizacion_miembros_rol_check CHECK (rol IN ('representante_legal', 'gerente', 'socio', 'afiliado', 'empleado', 'otro'))");
            DB::statement('ALTER TABLE organizacion_gremios ADD CONSTRAINT organizacion_gremios_distintos_check CHECK (empresa_id <> gremio_id)');
            DB::statement("ALTER TABLE organizacion_imports ADD CONSTRAINT organizacion_imports_estado_check CHECK (estado IN ('revision', 'confirmada', 'descartada'))");
            DB::statement("ALTER TABLE organizacion_import_rows ADD CONSTRAINT organizacion_import_rows_accion_check CHECK (accion IN ('crear', 'completar', 'saltar', 'error'))");
        }

        $now = now();
        $rows = [];
        foreach (['sector_economico' => self::SECTORES_ECONOMICOS, 'sector' => self::SECTORES] as $tipo => $names) {
            foreach ($names as $i => $nombre) {
                $rows[] = ['id' => (string) Str::uuid(), 'campaign_id' => null, 'tipo' => $tipo, 'nombre' => $nombre, 'orden' => $i,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('org_catalogos')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacion_import_rows');
        Schema::dropIfExists('organizacion_imports');
        Schema::dropIfExists('organizacion_gremios');
        Schema::dropIfExists('organizacion_miembros');
        Schema::dropIfExists('organizaciones');
        Schema::dropIfExists('org_catalogos');
    }
};
