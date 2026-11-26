<?php

namespace Tests\Concerns;

use App\Models\Campaign;
use App\Models\ElectoralCampaign;
use App\Models\ElectoralCampaignTerritory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// SQLite scenario of Campañas electorales (synthetic data): the Eventos scenario (CD with subgroup X «Alejandro»,
// independent Y), Santander (68) with Soto, Vélez and its municipios, Bucaramanga with two comunas and a
// corregimiento, a municipio of another departamento, the comunas migrations and the module's own migrations.
trait BuildsCampanasScenario
{
    use BuildsEventosScenario;

    protected string $santander;

    protected string $bucaramanga;

    protected string $comuna1;

    protected string $comuna2;

    protected string $corregimiento1;

    protected string $bogota;

    protected string $cd;

    protected string $superRole;

    protected function setUpCampanas(): void
    {
        $this->setUpEventos();
        $this->addMapaVotantesTables();

        Schema::table('geographic_units', function (Blueprint $t) {
            $t->string('official_code', 20)->nullable();
            $t->smallInteger('level')->default(0);
            $t->boolean('is_active')->default(true);
        });
        Schema::table('political_organizations', function (Blueprint $t) {
            $t->uuid('merged_into_id')->nullable();
            $t->string('acronym')->nullable();
            $t->string('color_hex')->nullable();
        });
        Schema::table('electoral_events', fn (Blueprint $t) => $t->string('event_type')->nullable());
        Schema::table('candidacies', function (Blueprint $t) {
            $t->integer('list_position')->nullable();
            $t->timestamps();
        });
        Schema::table('contests', fn (Blueprint $t) => $t->integer('seats')->nullable());
        Schema::table('electoral_results', fn (Blueprint $t) => $t->string('grain')->nullable());
        (require database_path('migrations/2026_11_14_000001_create_barrios_and_comunas.php'))->up();
        (require database_path('migrations/2026_11_14_000002_add_comuna_to_fichas.php'))->up();
        (require database_path('migrations/2026_11_23_000001_create_electoral_campaigns_tables.php'))->up();
        (require database_path('migrations/2026_11_23_000002_add_es_lider_to_lideres.php'))->up();

        $this->cd = Campaign::DEFAULT_ID;
        $this->superRole = Role::create(['code' => 'R01_SUPERADMIN', 'name' => 'Superadmin', 'level' => 10])->id;
        $this->roles['R01_SUPERADMIN'] = $this->superRole;

        $colombia = $this->geo('country', 'Colombia');
        $this->santander = $this->geo('department', 'Santander', $colombia);
        DB::table('geographic_units')->where('id', $this->santander)->update(['official_code' => '68']);
        DB::table('geographic_units')->whereIn('id', [$this->soto])->update(['parent_id' => $this->santander]);
        $velezProv = DB::table('geographic_units')->where('id', $this->velez)->value('parent_id');
        DB::table('geographic_units')->where('id', $velezProv)->update(['parent_id' => $this->santander]);
        DB::table('geographic_units')->where('id', $this->giron)->update(['official_code' => '68307']);
        DB::table('geographic_units')->where('id', $this->lebrija)->update(['official_code' => '68406']);
        DB::table('geographic_units')->where('id', $this->velez)->update(['official_code' => '68861']);
        $this->bucaramanga = $this->geo('municipality', 'Bucaramanga', $this->soto);
        DB::table('geographic_units')->where('id', $this->bucaramanga)->update(['official_code' => '68001']);
        $this->comuna1 = $this->unidad('comuna', 'Comuna 1 - Norte', $this->bucaramanga, '68001-C01');
        $this->comuna2 = $this->unidad('comuna', 'Comuna 2 - Nororiental', $this->bucaramanga, '68001-C02');
        $this->corregimiento1 = $this->unidad('corregimiento', 'Corregimiento 1', $this->bucaramanga, '68001-R1');
        $cundinamarca = $this->unidad('department', 'Bogotá D.C.', $colombia, '11');
        $this->bogota = $this->unidad('municipality', 'Bogotá', $cundinamarca, '11001');
    }

    protected function unidad(string $type, string $name, ?string $parent, string $code): string
    {
        $id = $this->geo($type, $name, $parent);
        DB::table('geographic_units')->where('id', $id)->update(['official_code' => $code]);

        return $id;
    }

    protected function flag(bool $on, array $beta = []): void
    {
        config(['features.campanas_electorales' => ['enabled' => $on, 'beta_users' => $beta]]);
    }

    protected function superadmin(string $campaignId = Campaign::DEFAULT_ID): User
    {
        return $this->eventUser($campaignId, [], 'R01_SUPERADMIN');
    }

    protected function manager(string $campaignId = Campaign::DEFAULT_ID, ?array $territory = null, array $extra = []): User
    {
        return $this->eventUser($campaignId, ['view.mapa', 'view.votantes', 'write.data', 'view.campanas', 'manage.campanas', ...$extra], 'R02_ADMIN_FUNCIONAL', $territory);
    }

    protected function viewer(string $campaignId = Campaign::DEFAULT_ID, ?array $territory = null): User
    {
        return $this->eventUser($campaignId, ['view.mapa', 'view.campanas'], 'R02_ADMIN_FUNCIONAL', $territory);
    }

    // A campaign stored as the server would, with its territory units.
    protected function campana(array $attrs = [], ?array $territorio = null, string $grupo = Campaign::DEFAULT_ID, string $status = ElectoralCampaign::ACTIVE): ElectoralCampaign
    {
        $c = ElectoralCampaign::factory()->forGroup($grupo)->make($attrs);
        $c->forceFill(['status' => $status, 'archived_at' => $status === ElectoralCampaign::ARCHIVED ? now() : null])->save();
        $territorio ??= $c->scope_level === ElectoralCampaign::SCOPE_DEPARTMENT ? [$this->santander] : [$this->giron];
        foreach ($territorio as $unit) {
            ElectoralCampaignTerritory::create(['electoral_campaign_id' => $c->id, 'geographic_unit_id' => $unit]);
        }

        return $c->fresh();
    }

    protected function grupoSinResultados(): void
    {
        DB::table('campaigns')->where('id', $this->campX)->update(['ve_resultados_electorales' => false]);
        DB::table('campaigns')->where('id', $this->campY)->update(['ve_resultados_electorales' => false]);
    }

    // --- electoral data

    protected function partido(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('political_organizations')->insert(['id' => $id, 'canonical_name' => $name]);

        return $id;
    }

    protected function catalogo(string $table, string $name): string
    {
        return DB::table($table)->where('name', $name)->value('id') ?? tap((string) Str::uuid(), fn ($id) => DB::table($table)->insert(['id' => $id, 'name' => $name]));
    }

    protected function evento(string $name, string $date, string $type = 'territorial'): string
    {
        $id = (string) Str::uuid();
        DB::table('electoral_events')->insert(['id' => $id, 'name' => $name, 'election_date' => $date, 'event_type' => $type]);

        return $id;
    }

    // $tipo 'offices' (Alcaldía, Gobernación) or 'corporations' (Concejo, Asamblea, Senado, Cámara de Representantes).
    protected function contienda(string $eventId, string $tipo, string $cargo, ?string $geo): string
    {
        $id = (string) Str::uuid();
        DB::table('contests')->insert([
            'id' => $id, 'electoral_event_id' => $eventId, 'geographic_unit_id' => $geo, 'name' => "{$cargo} prueba",
            $tipo === 'offices' ? 'office_id' : 'corporation_id' => $this->catalogo($tipo, $cargo),
        ]);

        return $id;
    }

    protected function persona(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $id, 'full_name' => $name, 'normalized_name' => mb_strtoupper($name)]);

        return $id;
    }

    protected function candidatura(string $contestId, string $personId, ?string $partyId, bool $primary = true): string
    {
        $id = (string) Str::uuid();
        DB::table('candidacies')->insert(['id' => $id, 'person_id' => $personId, 'contest_id' => $contestId]);
        if ($partyId) {
            DB::table('candidacy_endorsements')->insert(['id' => (string) Str::uuid(), 'candidacy_id' => $id, 'organization_id' => $partyId, 'is_primary' => $primary]);
        }

        return $id;
    }

    protected function votos(string $contestId, ?string $candidacyId, ?string $orgId, string $geo, ?int $value, string $metric = 'votes'): void
    {
        DB::table('electoral_results')->insert([
            'id' => (string) Str::uuid(), 'contest_id' => $contestId, 'candidacy_id' => $candidacyId, 'organization_id' => $orgId,
            'geographic_unit_id' => $geo, 'metric_type' => $metric, 'value' => $value,
        ]);
    }

    // A plain votante: without the cargo «Líder» of lider(), so it counts as a leader only when the test says so.
    protected function ficha(string $campaignId, string $geo, array $extra = []): string
    {
        return $this->lider($campaignId, 'Ficha '.Str::random(6), null, $extra + ['geographic_unit_id' => $geo, 'is_active' => true, 'cargo' => null]);
    }
}
