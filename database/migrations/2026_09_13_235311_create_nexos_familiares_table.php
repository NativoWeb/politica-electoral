<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexos_familiares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('parentesco')->nullable(); // esposa, hijo, hermano, etc.
            $table->string('cargo')->nullable();
            $table->integer('edad')->nullable();
            $table->string('gustos')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexos_familiares');
    }
};
