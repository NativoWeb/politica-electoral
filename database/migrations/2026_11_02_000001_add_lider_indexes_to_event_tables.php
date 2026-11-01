<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Eventos especiales, phase 4: a person's ficha lists the events they attended or organized, and Votantes filters and
// counts by attendance, both by lider_id. PostgreSQL does not index foreign keys on its own.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->index('lider_id', 'event_attendees_lider_id_index');
        });
        Schema::table('event_organizers', function (Blueprint $table) {
            $table->index('lider_id', 'event_organizers_lider_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_organizers', function (Blueprint $table) {
            $table->dropIndex('event_organizers_lider_id_index');
        });
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropIndex('event_attendees_lider_id_index');
        });
    }
};
