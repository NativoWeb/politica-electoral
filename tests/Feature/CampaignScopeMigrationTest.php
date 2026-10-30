<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Step 1 of multi-campaign: existing code that never sets campaign_id lands in "Centro Democrático",
// and a cédula is unique per campaign instead of globally.
// The full migration chain does not run on SQLite (PostgreSQL extensions), so the tables it alters are
// created here as they were before it, and the real migration is run on top of them.
class CampaignScopeMigrationTest extends TestCase
{
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-only: rebuilds the pre-migration tables.');
        }

        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
        });
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('municipio');
            $t->string('cargo')->nullable();
            $t->string('cedula')->nullable();
            $t->uuid('geographic_unit_id')->nullable();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX lideres_cedula_unique ON lideres(cedula) WHERE cedula IS NOT NULL AND cedula != ''");
        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->string('nombre');
        });
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('whatsapp_sends', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('template_id');
        });

        DB::table('users')->insert(['id' => (string) Str::uuid(), 'name' => 'Existente']);
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'nombre' => 'Existente', 'municipio' => 'Bucaramanga', 'cedula' => '111']);

        $this->migration = require database_path('migrations/2026_10_30_000001_create_campaigns_and_add_campaign_id.php');
        $this->migration->up();
    }

    private function lider(array $attrs = []): Lider
    {
        // campaign_id is never mass-assignable: the server sets it.
        return Lider::forceCreate(array_merge(['nombre' => 'Lider '.Str::random(6), 'municipio' => 'Bucaramanga', 'cargo' => 'Líder'], $attrs));
    }

    private function otraCampana(): string
    {
        return Campaign::create(['name' => 'Candidato X', 'slug' => 'candidato-x', 'parent_id' => Campaign::DEFAULT_ID])->id;
    }

    public function test_default_campaign_is_seeded_and_existing_rows_get_it(): void
    {
        $cd = Campaign::find(Campaign::DEFAULT_ID);

        $this->assertNotNull($cd);
        $this->assertSame('centro-democratico', $cd->slug);
        $this->assertTrue($cd->whatsapp_enabled);
        $this->assertTrue($cd->is_active);
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('lideres')->value('campaign_id'));
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('users')->value('campaign_id'));
    }

    public function test_lider_without_campaign_gets_the_default_campaign(): void
    {
        $lider = $this->lider();

        $this->assertSame(Campaign::DEFAULT_ID, $lider->refresh()->campaign_id);
        $this->assertSame('centro-democratico', $lider->campaign->slug);
    }

    public function test_same_cedula_is_allowed_in_different_campaigns(): void
    {
        $otra = $this->otraCampana();

        $this->lider(['cedula' => '63525141']);
        $this->lider(['cedula' => '63525141', 'campaign_id' => $otra]);

        $this->assertSame(2, DB::table('lideres')->where('cedula', '63525141')->count());
        $this->assertSame(1, Campaign::find(Campaign::DEFAULT_ID)->children()->count());
    }

    public function test_same_cedula_in_the_same_campaign_is_rejected(): void
    {
        $this->lider(['cedula' => '63525141']);

        $this->expectException(QueryException::class);
        $this->lider(['cedula' => '63525141']);
    }

    public function test_empty_cedulas_do_not_collide(): void
    {
        $this->lider(['cedula' => '']);
        $this->lider(['cedula' => '']);
        $this->lider();

        $this->assertSame(4, DB::table('lideres')->count());
    }

    public function test_down_restores_the_global_unique_cedula(): void
    {
        $this->migration->down();

        $this->assertFalse(Schema::hasTable('campaigns'));
        foreach (['users', 'lideres', 'nexos_familiares', 'whatsapp_templates', 'whatsapp_sends'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'campaign_id'), $table);
        }

        $this->expectException(QueryException::class);
        DB::table('lideres')->insert(['id' => (string) Str::uuid(), 'nombre' => 'Otro', 'municipio' => 'Bucaramanga', 'cedula' => '111']);
    }
}
