<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every change of data:limpiar-nombres-numeros by batch (lote), so --revertir can undo it: the changed row (tabla +
// registro_id: the ficha, a contact it added or the renamed electoral person), the ficha it belongs to, and the value
// before and after. A new table: comuna_cambios only holds comunas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limpieza_cambios', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('lote');
            $table->string('tabla', 30);
            $table->uuid('registro_id');
            $table->uuid('lider_id');
            $table->string('campo', 30);
            $table->text('antes')->nullable();
            $table->text('despues')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('lote');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limpieza_cambios');
    }
};
