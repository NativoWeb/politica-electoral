<?php

use App\Services\Configuracion\AjusteStore;
use App\Services\Configuracion\CatalogoStore;
use App\Support\Configuracion\CatalogoRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Centro de configuración (superadmin): editable lists of the forms, ajustes del sistema and the history of every
// change. The lists start with the exact options the screens had hardcoded, so nothing changes on deploy. No foreign
// key to users: in production that table belongs to postgres.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_opciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('catalogo', 60);
            $table->string('valor', 100);
            $table->string('etiqueta', 150);
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->string('color', 20)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['catalogo', 'valor']);
            $table->index(['catalogo', 'orden']);
        });

        Schema::create('ajustes', function (Blueprint $table) {
            $table->string('clave', 100)->primary();
            $table->jsonb('valor');
            $table->string('tipo', 20);
            $table->uuid('actualizado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('config_cambios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tipo', 20)->comment('catalogo|ajuste');
            $table->string('catalogo', 60)->nullable();
            $table->string('referencia', 150)->comment('valor de la opción o clave del ajuste');
            $table->string('accion', 30);
            $table->jsonb('antes')->nullable();
            $table->jsonb('despues')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('revierte_id')->nullable()->comment('cambio que este deshace');
            $table->uuid('revertido_por_id')->nullable()->comment('cambio que deshizo este');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['tipo', 'catalogo', 'referencia']);
        });

        $now = now();
        $rows = [];
        foreach (array_keys(CatalogoRegistry::DEFINICIONES) as $catalogo) {
            foreach (CatalogoRegistry::opcionesIniciales($catalogo) as $o) {
                $rows[] = [
                    'id' => (string) Str::uuid(), 'catalogo' => $catalogo, 'valor' => $o['valor'], 'etiqueta' => $o['etiqueta'],
                    'orden' => $o['orden'], 'activo' => $o['activo'], 'color' => $o['color'],
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::table('catalogo_opciones')->insert($rows);
        $this->forgetCache();
    }

    public function down(): void
    {
        Schema::dropIfExists('config_cambios');
        Schema::dropIfExists('ajustes');
        Schema::dropIfExists('catalogo_opciones');
        $this->forgetCache();
    }

    private function forgetCache(): void
    {
        (new CatalogoStore)->olvidar();
        (new AjusteStore)->olvidar();
    }
};
