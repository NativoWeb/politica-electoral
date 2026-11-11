<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Eventos especiales: "Acta de reunión" of an event and the follow-up of its compromisos (docs/eventos-api.md §14).
// An acta is a borrador (editable, nothing written to Votantes or the attendees) until someone confirms it.
// Additive only: five new tables and the attendee source 'acta' (participants of an acta are also attendees).
// data_consents is NOT touched (owner postgres in production).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_actas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('campaign_id');
            $table->string('titulo', 255);
            $table->date('fecha')->nullable();
            $table->string('municipio', 120)->nullable();
            $table->string('sitio', 255)->nullable();
            $table->string('hora_inicio', 5)->nullable();
            $table->string('hora_fin', 5)->nullable();
            $table->text('introduccion')->nullable();
            $table->jsonb('orden_dia')->nullable();
            $table->jsonb('desarrollo')->nullable();
            $table->text('cierre')->nullable();
            $table->string('archivo_path', 255)->nullable();
            $table->string('archivo_mime', 100)->nullable();
            $table->string('archivo_nombre', 255)->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('event_acta_participantes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('acta_id');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->string('nombre', 200);
            $table->string('perfil', 200)->nullable();
            $table->string('municipio', 120)->nullable();
            $table->string('telefono', 60)->nullable();
            $table->string('cedula', 20)->nullable();
            $table->uuid('lider_id')->nullable();
            $table->uuid('sugerencia_rechazada')->nullable();
            $table->uuid('attendee_id')->nullable();
            $table->timestamps();

            $table->foreign('acta_id')->references('id')->on('event_actas')->cascadeOnDelete();
            $table->foreign('lider_id')->references('id')->on('lideres')->nullOnDelete();
            $table->foreign('attendee_id')->references('id')->on('event_attendees')->nullOnDelete();
            $table->index('acta_id');
            $table->index('lider_id');
        });

        Schema::create('event_compromisos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('acta_id');
            $table->uuid('event_id');
            $table->uuid('campaign_id');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->text('descripcion');
            $table->date('fecha_limite')->nullable();
            $table->date('recordar_el')->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->timestamps();

            $table->foreign('acta_id')->references('id')->on('event_actas')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->index(['campaign_id', 'estado']);
            $table->index('event_id');
            $table->index('fecha_limite');
            $table->index('recordar_el');
        });

        Schema::create('event_compromiso_responsables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('compromiso_id');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->string('nombre', 200);
            $table->string('telefono', 60)->nullable();
            $table->uuid('lider_id')->nullable();
            $table->uuid('sugerencia_rechazada')->nullable();
            $table->timestamps();

            $table->foreign('compromiso_id')->references('id')->on('event_compromisos')->cascadeOnDelete();
            $table->foreign('lider_id')->references('id')->on('lideres')->nullOnDelete();
            $table->index('compromiso_id');
            $table->index('lider_id');
        });

        Schema::create('event_compromiso_avances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('compromiso_id');
            $table->text('nota')->nullable();
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->string('archivo_path', 255)->nullable();
            $table->string('archivo_mime', 100)->nullable();
            $table->string('archivo_nombre', 255)->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('compromiso_id')->references('id')->on('event_compromisos')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compromiso_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_actas ADD CONSTRAINT event_actas_estado_check CHECK (estado IN ('borrador', 'confirmada'))");
            $estados = "'pendiente', 'en_curso', 'cumplido', 'no_cumplido'";
            DB::statement("ALTER TABLE event_compromisos ADD CONSTRAINT event_compromisos_estado_check CHECK (estado IN ({$estados}))");
            DB::statement("ALTER TABLE event_compromiso_avances ADD CONSTRAINT event_compromiso_avances_estado_check CHECK ((estado_nuevo IS NULL OR estado_nuevo IN ({$estados})) AND (estado_anterior IS NULL OR estado_anterior IN ({$estados})))");
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_source_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador', 'excel', 'ocr', 'acta'))");
        }
    }

    // Fails on PostgreSQL while attendees with source 'acta' exist: delete or migrate them first, on purpose.
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_attendees DROP CONSTRAINT IF EXISTS event_attendees_source_check');
            DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador', 'excel', 'ocr'))");
        }

        Schema::dropIfExists('event_compromiso_avances');
        Schema::dropIfExists('event_compromiso_responsables');
        Schema::dropIfExists('event_compromisos');
        Schema::dropIfExists('event_acta_participantes');
        Schema::dropIfExists('event_actas');
    }
};
