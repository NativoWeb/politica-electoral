<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lider_municipios_influencia', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('lider_id');
            $table->uuid('geographic_unit_id');
            $table->string('municipio_name');
            $table->string('provincia_name')->nullable();
            $table->text('nota')->nullable();
            $table->timestamps();

            $table->foreign('lider_id')->references('id')->on('lideres')->onDelete('cascade');
            $table->foreign('geographic_unit_id')->references('id')->on('geographic_units');
            $table->unique(['lider_id', 'geographic_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lider_municipios_influencia');
    }
};
