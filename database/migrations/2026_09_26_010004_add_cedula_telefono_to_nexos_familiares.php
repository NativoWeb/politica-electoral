<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nexos_familiares', function (Blueprint $table) {
            $table->string('cedula', 20)->nullable();
            $table->string('telefono', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nexos_familiares', function (Blueprint $table) {
            $table->dropColumn(['cedula', 'telefono']);
        });
    }
};
