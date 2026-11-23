<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Correcting a confirmed acta (docs/eventos-api.md §14, permission edit.actas): every saved correction bumps `version`, so
// a correction sent with an older one (another user, or a double click) gets a 409 instead of overwriting. Additive; no
// agent_ro view reads event_actas and data_consents is not touched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_actas', function (Blueprint $table) {
            $table->integer('version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('event_actas', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
