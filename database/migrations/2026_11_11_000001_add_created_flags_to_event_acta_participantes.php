<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What confirming an acta created for each participant (ficha and attendance), so deleting a confirmed acta
// (docs/eventos-api.md §14) undoes only that. Additive; actas confirmed before this have false = "not created".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_acta_participantes', function (Blueprint $table) {
            $table->boolean('created_lider')->default(false);
            $table->boolean('created_attendee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('event_acta_participantes', function (Blueprint $table) {
            $table->dropColumn(['created_lider', 'created_attendee']);
        });
    }
};
