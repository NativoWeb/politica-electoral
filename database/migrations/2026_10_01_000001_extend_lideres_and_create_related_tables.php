<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lideres', function (Blueprint $table) {
            // partido was added directly via SQL, not through a migration
            if (! Schema::hasColumn('lideres', 'partido')) {
                $table->string('partido')->nullable();
            }

            // These may exist from earlier pending migrations
            if (! Schema::hasColumn('lideres', 'destacado')) {
                $table->boolean('destacado')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'profesion')) {
                $table->string('profesion')->nullable();
            }
            if (! Schema::hasColumn('lideres', 'votos')) {
                $table->integer('votos')->default(0);
            }

            // Identidad
            if (! Schema::hasColumn('lideres', 'tipo_documento')) {
                $table->string('tipo_documento', 20)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'fecha_expedicion_doc')) {
                $table->date('fecha_expedicion_doc')->nullable();
            }
            if (! Schema::hasColumn('lideres', 'genero')) {
                $table->string('genero', 20)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'fecha_nacimiento')) {
                $table->date('fecha_nacimiento')->nullable();
            }
            if (! Schema::hasColumn('lideres', 'foto')) {
                $table->string('foto', 500)->nullable();
            }

            // Personal
            if (! Schema::hasColumn('lideres', 'estado_civil')) {
                $table->string('estado_civil', 30)->nullable();
            }

            // Referente
            if (! Schema::hasColumn('lideres', 'referente_documento')) {
                $table->string('referente_documento', 20)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'referente_nombre')) {
                $table->string('referente_nombre', 150)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'referente_apellido')) {
                $table->string('referente_apellido', 150)->nullable();
            }

            // Puesto de votacion
            if (! Schema::hasColumn('lideres', 'departamento_votacion')) {
                $table->string('departamento_votacion', 100)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'municipio_votacion')) {
                $table->string('municipio_votacion', 100)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'puesto_votacion')) {
                $table->string('puesto_votacion', 200)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'direccion_puesto')) {
                $table->string('direccion_puesto', 300)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'mesa_votacion')) {
                $table->string('mesa_votacion', 20)->nullable();
            }

            // Tags booleanos
            if (! Schema::hasColumn('lideres', 'militante')) {
                $table->boolean('militante')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'autoriza_datos')) {
                $table->boolean('autoriza_datos')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'verificado')) {
                $table->boolean('verificado')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'fallecido')) {
                $table->boolean('fallecido')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'empresario')) {
                $table->boolean('empresario')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'reservista')) {
                $table->boolean('reservista')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'funcionario')) {
                $table->boolean('funcionario')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'exfuncionario')) {
                $table->boolean('exfuncionario')->default(false);
            }
            if (! Schema::hasColumn('lideres', 'gran_elector')) {
                $table->boolean('gran_elector')->default(false);
            }

            // Confianza y convenio
            if (! Schema::hasColumn('lideres', 'nivel_confianza')) {
                $table->string('nivel_confianza', 30)->default('sin_llamar');
            }
            if (! Schema::hasColumn('lideres', 'convenio')) {
                $table->string('convenio', 200)->nullable();
            }

            // Hoja de vida
            if (! Schema::hasColumn('lideres', 'escolaridad')) {
                $table->string('escolaridad', 50)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'tipo_hoja_vida')) {
                $table->string('tipo_hoja_vida', 50)->nullable();
            }
            if (! Schema::hasColumn('lideres', 'fecha_registro_hv')) {
                $table->date('fecha_registro_hv')->nullable();
            }

            // Redes sociales
            if (! Schema::hasColumn('lideres', 'facebook')) {
                $table->string('facebook')->nullable();
            }
            if (! Schema::hasColumn('lideres', 'twitter')) {
                $table->string('twitter')->nullable();
            }
            if (! Schema::hasColumn('lideres', 'instagram')) {
                $table->string('instagram')->nullable();
            }

            // Indices
            $table->index('nivel_confianza');
            $table->index('verificado');
            $table->index('gran_elector');
            $table->index('fallecido');
        });

        Schema::create('lider_contactos', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('lider_id');
            $table->foreign('lider_id')->references('id')->on('lideres')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('valor', 200);
            $table->boolean('principal')->default(false);
            $table->string('estado', 20)->default('activo');
            $table->timestamps();

            $table->index('lider_id');
        });

        Schema::create('lider_info_politica', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('lider_id');
            $table->foreign('lider_id')->references('id')->on('lideres')->cascadeOnDelete();
            $table->string('cargo_politico', 200)->nullable();
            $table->string('departamento', 100)->nullable();
            $table->string('municipio', 100)->nullable();
            $table->string('partido', 200)->nullable();
            $table->string('aliado', 200)->nullable();
            $table->integer('votos')->default(0);
            $table->text('observacion')->nullable();
            $table->string('estado', 20)->default('activo');
            $table->timestamps();

            $table->index('lider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lider_info_politica');
        Schema::dropIfExists('lider_contactos');

        Schema::table('lideres', function (Blueprint $table) {
            // Drop indices first
            $table->dropIndex(['nivel_confianza']);
            $table->dropIndex(['verificado']);
            $table->dropIndex(['gran_elector']);
            $table->dropIndex(['fallecido']);

            // Only drop columns added by THIS migration
            $columnsToDrop = [
                'tipo_documento', 'fecha_expedicion_doc', 'genero', 'fecha_nacimiento', 'foto',
                'estado_civil',
                'referente_documento', 'referente_nombre', 'referente_apellido',
                'departamento_votacion', 'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
                'militante', 'autoriza_datos', 'verificado', 'fallecido', 'empresario', 'reservista',
                'funcionario', 'exfuncionario', 'gran_elector',
                'nivel_confianza', 'convenio',
                'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv',
                'facebook', 'twitter', 'instagram',
            ];

            $existing = [];
            foreach ($columnsToDrop as $col) {
                if (Schema::hasColumn('lideres', $col)) {
                    $existing[] = $col;
                }
            }
            if (! empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
