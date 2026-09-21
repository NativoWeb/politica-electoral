<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lideres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->string('provincia')->nullable();
            $table->string('municipio');
            $table->string('cargo');
            $table->string('tipo')->default('directorio');
            $table->string('telefono')->nullable();
            $table->string('cedula')->nullable();
            $table->string('email')->nullable();
            $table->string('observacion')->nullable();
            $table->uuid('geographic_unit_id')->nullable();
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lideres');
    }
};
