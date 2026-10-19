<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lider_seguimientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lider_id')->constrained('lideres')->cascadeOnDelete();
            // Users can be deleted from the admin; their follow-ups stay, without author.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('tipo', ['llamada', 'visita', 'reunion', 'mensaje', 'otro']);
            $table->text('nota');
            $table->dateTime('fecha');
            $table->timestamps();

            $table->index(['lider_id', 'fecha']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lider_seguimientos');
    }
};
