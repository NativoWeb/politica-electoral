<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Eventos especiales, phase 1: campaign events with attendee registration by public QR and by operator.
// Only new tables: lideres is not touched (an attendee becomes a lider only when someone approves it).
// data_consents is append-only; on PostgreSQL a trigger rejects UPDATE, DELETE and TRUNCATE even for the owner.
return new class extends Migration
{
    public const EVENT_TYPES = ['Del Senado al barrio', 'Gira', 'Novena', 'Reunión'];

    public const PROVISIONAL_TEXT = 'TEXTO PROVISIONAL (pendiente de aprobación por la campaña). '
        .'Autorizo de manera previa, expresa e informada a la campaña organizadora de este evento para recolectar, '
        .'almacenar y usar mis datos personales (nombre, cédula, teléfonos, fecha de nacimiento, dirección y barrio) '
        .'y el dato de mi asistencia a este evento, con el fin de registrar la asistencia y contactarme sobre las '
        .'actividades de la campaña, conforme a la Ley 1581 de 2012 y el Decreto 1377 de 2013. Puedo conocer, '
        .'actualizar, rectificar y suprimir mis datos y revocar esta autorización en cualquier momento.';

    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('nombre', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
        });

        Schema::create('consent_texts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->nullable();
            $table->string('version', 50);
            $table->text('cuerpo');
            $table->char('sha256', 64);
            $table->timestamp('vigente_desde');
            $table->boolean('is_active')->default(true);
            $table->boolean('approved')->default(false);
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->index(['campaign_id', 'is_active']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('event_type_id');
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('sitio_encuentro', 255);
            $table->uuid('provincia_id')->nullable();
            $table->uuid('municipio_id');
            $table->string('status', 20)->default('borrador');
            $table->string('public_token', 64)->unique();
            $table->timestamp('registration_closes_at')->nullable();
            $table->uuid('consent_text_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('event_type_id')->references('id')->on('event_types');
            $table->foreign('provincia_id')->references('id')->on('geographic_units');
            $table->foreign('municipio_id')->references('id')->on('geographic_units');
            $table->foreign('consent_text_id')->references('id')->on('consent_texts');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['campaign_id', 'starts_at']);
            $table->index('municipio_id');
        });

        Schema::create('event_barrios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('nombre', 120);
            $table->string('nombre_normalizado', 120);
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->unique(['event_id', 'nombre_normalizado']);
            $table->index('nombre_normalizado');
        });

        Schema::create('event_organizers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('lider_id')->nullable();
            $table->string('nombre', 200);
            $table->string('cedula', 20)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('rol', 100)->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('lider_id')->references('id')->on('lideres')->nullOnDelete();
            $table->index('event_id');
        });

        Schema::create('event_figures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('person_id')->nullable();
            $table->string('nombre', 200);
            $table->string('cargo', 150)->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->index('event_id');
        });

        Schema::create('event_attendees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('campaign_id');
            $table->uuid('lider_id')->nullable();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('cedula', 20)->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('fijo', 20)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('barrio', 120)->nullable();
            $table->string('barrio_normalizado', 120)->nullable();
            $table->boolean('autoriza_datos')->default(false);
            $table->uuid('consent_id')->nullable();
            $table->string('source', 20);
            $table->string('link_status', 20);
            $table->uuid('registered_by')->nullable();
            $table->uuid('client_uuid')->unique();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('lider_id')->references('id')->on('lideres')->nullOnDelete();
            $table->foreign('registered_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['event_id', 'link_status']);
            $table->index(['campaign_id', 'cedula']);
            $table->index('barrio_normalizado');
        });
        DB::statement('CREATE UNIQUE INDEX event_attendees_event_id_cedula_unique ON event_attendees (event_id, cedula) WHERE cedula IS NOT NULL');

        // lider_id carries no foreign key: a lider may be deleted (merge) and this table is never updated.
        // event_attendees.consent_id points here without a foreign key, to avoid a cycle between both tables.
        Schema::create('data_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('consent_text_id');
            $table->char('text_sha256', 64);
            $table->string('cedula', 20)->nullable();
            $table->uuid('lider_id')->nullable();
            $table->uuid('event_id')->nullable();
            $table->uuid('attendee_id')->nullable();
            $table->string('channel', 20);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');

            $table->foreign('campaign_id')->references('id')->on('campaigns');
            $table->foreign('consent_text_id')->references('id')->on('consent_texts');
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('attendee_id')->references('id')->on('event_attendees');
            $table->index(['campaign_id', 'cedula']);
            $table->index('event_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->upPgsql();
        }

        $this->seed();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS data_consents_append_only_row ON data_consents');
            DB::statement('DROP TRIGGER IF EXISTS data_consents_append_only_truncate ON data_consents');
            DB::statement('DROP FUNCTION IF EXISTS data_consents_append_only()');
        }

        Schema::dropIfExists('data_consents');
        Schema::dropIfExists('event_attendees');
        Schema::dropIfExists('event_figures');
        Schema::dropIfExists('event_organizers');
        Schema::dropIfExists('event_barrios');
        Schema::dropIfExists('events');
        Schema::dropIfExists('consent_texts');
        Schema::dropIfExists('event_types');
    }

    private function upPgsql(): void
    {
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('borrador', 'abierto', 'cerrado', 'cancelado'))");
        DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_source_check CHECK (source IN ('qr', 'operador'))");
        DB::statement("ALTER TABLE event_attendees ADD CONSTRAINT event_attendees_link_status_check CHECK (link_status IN ('vinculado', 'pendiente', 'sin_cedula', 'aprobado', 'descartado'))");
        DB::statement("ALTER TABLE data_consents ADD CONSTRAINT data_consents_channel_check CHECK (channel IN ('qr', 'operador'))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION data_consents_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'data_consents es de solo insercion: % no permitido', TG_OP;
            END
            $$
        SQL);
        DB::statement('CREATE TRIGGER data_consents_append_only_row BEFORE UPDATE OR DELETE ON data_consents FOR EACH ROW EXECUTE FUNCTION data_consents_append_only()');
        DB::statement('CREATE TRIGGER data_consents_append_only_truncate BEFORE TRUNCATE ON data_consents FOR EACH STATEMENT EXECUTE FUNCTION data_consents_append_only()');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON data_consents FROM PUBLIC');
    }

    private function seed(): void
    {
        $now = now();
        foreach (self::EVENT_TYPES as $nombre) {
            DB::table('event_types')->insert([
                'id' => (string) Str::uuid(), 'campaign_id' => null, 'nombre' => $nombre, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Global fallback for campaigns without their own text: lets the operator register, never opens the QR.
        DB::table('consent_texts')->insert([
            'id' => (string) Str::uuid(), 'campaign_id' => null, 'version' => 'provisional-1',
            'cuerpo' => self::PROVISIONAL_TEXT, 'sha256' => hash('sha256', self::PROVISIONAL_TEXT),
            'vigente_desde' => $now, 'is_active' => true, 'approved' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
