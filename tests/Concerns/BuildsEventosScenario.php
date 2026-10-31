<?php

namespace Tests\Concerns;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// SQLite scenario for Eventos especiales: the tables the module reads are built by hand (the full migration chain
// needs PostgreSQL extensions) and the real eventos migration runs on top of them.
// Campaigns: CD (root) with subcampaign X, and independent Y. Municipios Girón and Lebrija (Soto) plus Vélez.
trait BuildsEventosScenario
{
    protected string $campX;

    protected string $campY;

    protected string $giron;

    protected string $lebrija;

    protected string $velez;

    protected string $soto;

    protected array $roles = [];

    protected function setUpEventos(): void
    {
        $this->withoutVite();
        $this->createBaseSchema();
        (require database_path('migrations/2026_10_19_000001_add_whatsapp_link_to_users.php'))->up();
        (require database_path('migrations/2026_10_31_000001_create_eventos_tables.php'))->up();
        $this->seedBase();
    }

    private function createBaseSchema(): void
    {
        Schema::create('campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->uuid('parent_id')->nullable();
            $t->uuid('candidate_person_id')->nullable();
            $t->boolean('whatsapp_enabled')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code');
            $t->string('name');
            $t->string('description')->nullable();
            $t->integer('level')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->uuid('role_id')->nullable();
            $t->uuid('campaign_id')->default(Campaign::DEFAULT_ID);
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->string('preferred_density')->nullable();
            $t->boolean('executive_mode')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('geographic_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('canonical_name');
            $t->uuid('parent_id')->nullable();
            $t->timestamps();
        });

        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->string('normalized_name')->nullable();
            $t->uuid('merged_into_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        foreach (['offices', 'corporations'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name');
            });
        }
        Schema::create('electoral_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->date('election_date');
        });
        Schema::create('contests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('electoral_event_id');
            $t->uuid('office_id')->nullable();
            $t->uuid('corporation_id')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->string('name');
        });
        Schema::create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->uuid('contest_id');
        });

        $booleans = ['is_active', 'destacado', 'militante', 'autoriza_datos', 'verificado', 'fallecido',
            'empresario', 'reservista', 'funcionario', 'exfuncionario', 'gran_elector'];
        Schema::create('lideres', function (Blueprint $t) use ($booleans) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->uuid('geographic_unit_id')->nullable();
            $t->integer('votos')->default(0);
            $t->string('nombre');
            $t->string('municipio');
            $t->string('tipo')->default('directorio');
            $t->string('nivel_confianza')->default('sin_llamar');
            foreach ($booleans as $col) {
                $t->boolean($col)->default($col === 'is_active');
            }
            foreach (['provincia', 'cargo', 'telefono', 'cedula', 'email', 'observacion', 'partido', 'direccion',
                'barrio', 'zona', 'profesion', 'tipo_documento', 'fecha_expedicion_doc', 'genero', 'fecha_nacimiento',
                'foto', 'estado_civil', 'referente_documento', 'referente_nombre', 'referente_apellido',
                'departamento_votacion', 'municipio_votacion', 'puesto_votacion', 'direccion_puesto', 'mesa_votacion',
                'convenio', 'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv', 'facebook', 'twitter', 'instagram'] as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX lideres_campaign_id_cedula_unique ON lideres (campaign_id, cedula) WHERE cedula IS NOT NULL AND cedula <> ''");

        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('tipo');
            $t->string('valor');
            $t->boolean('principal')->default(false);
            $t->string('estado')->default('activo');
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 80)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->json('metadata')->nullable();
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('correlation_id', 50)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    private function seedBase(): void
    {
        DB::table('campaigns')->insert([
            ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => null],
            ['id' => $this->campX = (string) Str::uuid(), 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID],
            ['id' => $this->campY = (string) Str::uuid(), 'name' => 'Otra', 'slug' => 'otra', 'parent_id' => null],
        ]);

        $this->soto = $this->geo('province', 'Soto');
        $this->giron = $this->geo('municipality', 'Girón', $this->soto);
        $this->lebrija = $this->geo('municipality', 'Lebrija', $this->soto);
        $this->velez = $this->geo('municipality', 'Vélez', $this->geo('province', 'Vélez'));

        foreach (['R02_ADMIN_FUNCIONAL' => 9, 'R09_CAMPO' => 3, 'R10_CONSULTA_EJECUTIVA' => 2] as $code => $level) {
            $this->roles[$code] = Role::create(['code' => $code, 'name' => $code, 'level' => $level])->id;
        }
    }

    protected function geo(string $type, string $name, ?string $parent = null): string
    {
        $id = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $id, 'type' => $type, 'canonical_name' => $name, 'parent_id' => $parent]);

        return $id;
    }

    protected function eventUser(string $campaignId, array $permissions, string $role = 'R02_ADMIN_FUNCIONAL', ?array $territory = null): User
    {
        $user = User::create([
            'name' => 'Usuario '.Str::random(4), 'email' => Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => $this->roles[$role], 'permissions' => $permissions, 'territory_scope' => $territory,
        ]);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    protected function makeEvent(string $campaignId, array $extra = []): CampaignEvent
    {
        $event = new CampaignEvent(array_merge([
            'event_type_id' => DB::table('event_types')->where('nombre', 'Gira')->value('id'),
            'nombre' => 'Gira Girón',
            'starts_at' => now()->subHour(),
            'sitio_encuentro' => 'Parque principal',
            'provincia_id' => $this->soto,
            'municipio_id' => $this->giron,
        ], array_intersect_key($extra, array_flip((new CampaignEvent)->getFillable()))));
        $event->forceFill(array_merge(
            ['campaign_id' => $campaignId, 'status' => 'abierto', 'public_token' => Str::random(43)],
            array_intersect_key($extra, array_flip(['status', 'public_token', 'deleted_at'])),
        ))->save();

        return $event;
    }

    protected function approvedText(string $campaignId, string $body = 'Autorizo el tratamiento de mis datos (texto aprobado).'): ConsentText
    {
        $text = (new ConsentText)->forceFill([
            'campaign_id' => $campaignId, 'version' => 'v1', 'cuerpo' => $body, 'sha256' => hash('sha256', $body),
            'vigente_desde' => now()->subDay(), 'is_active' => true, 'approved' => true,
        ]);
        $text->save();

        return $text;
    }

    protected function provisionalText(): ConsentText
    {
        return ConsentText::whereNull('campaign_id')->where('approved', false)->firstOrFail();
    }

    protected function lider(string $campaignId, string $nombre, ?string $cedula, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lideres')->insert(array_merge([
            'id' => $id, 'campaign_id' => $campaignId, 'geographic_unit_id' => $this->giron, 'nombre' => $nombre,
            'cedula' => $cedula, 'municipio' => 'Girón', 'telefono' => '3000000000', 'cargo' => 'Líder',
            'tipo' => 'directorio', 'barrio' => 'Barrio original', 'observacion' => 'Nota original',
            'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ], $extra));

        return $id;
    }

    protected function attendeePayload(string $consentId, array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'consent_text_id' => $consentId,
            'autoriza_datos' => true,
            'nombres' => 'Ana María',
            'apellidos' => 'Pérez Gómez',
            'cedula' => '1098765432',
            'celular' => '300 123 4567',
            'barrio' => 'El Poblado',
        ], $overrides);
    }

    // A form token issued 10 s ago, as the public page hands it out.
    protected function formToken(CampaignEvent $event, int $secondsAgo = 10): string
    {
        return Crypt::encryptString(json_encode(['e' => $event->id, 't' => now()->timestamp - $secondsAgo]));
    }

    protected function publicPayload(CampaignEvent $event, string $consentId, array $overrides = []): array
    {
        return array_merge($this->attendeePayload($consentId), ['form_token' => $this->formToken($event), 'website' => ''], $overrides);
    }
}
