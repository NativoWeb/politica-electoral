<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lideres', function (Blueprint $table) {
            $table->index('referente_documento', 'lideres_referente_documento_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lideres', function (Blueprint $table) {
            $table->dropIndex('lideres_referente_documento_idx');
        });
    }
};
