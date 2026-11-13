<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Support\CurrentCampaign;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// data:campaign-parent moves a grupo político under another one with the rules of CampaignService::parentError,
// audits the change and clears the per-user caches of the users whose visible groups change.
class CampaignParentCommandTest extends TestCase
{
    private string $alejandro;

    private string $hijoAlejandro;

    private User $userCd;

    private User $userAlejandro;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->uuid('parent_id')->nullable();
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
            $t->json('permissions')->nullable();
            $t->rememberToken();
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
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
            $t->boolean('is_active')->default(true);
        });
        Schema::create('nexos_familiares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id');
        });

        DB::table('campaigns')->insert([
            ['id' => Campaign::DEFAULT_ID, 'name' => 'Centro Democrático', 'slug' => 'centro-democratico', 'parent_id' => null],
            ['id' => $this->alejandro = (string) Str::uuid(), 'name' => 'Alejandro Cardenas', 'slug' => 'alejandro-cardenas', 'parent_id' => null],
        ]);
        DB::table('campaigns')->insert(['id' => $this->hijoAlejandro = (string) Str::uuid(), 'name' => 'Equipo Alejandro', 'slug' => 'equipo-alejandro', 'parent_id' => $this->alejandro]);
        DB::table('lideres')->insert([
            ['id' => (string) Str::uuid(), 'campaign_id' => $this->alejandro, 'is_active' => true],
            ['id' => (string) Str::uuid(), 'campaign_id' => $this->hijoAlejandro, 'is_active' => true],
            ['id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'is_active' => true],
        ]);

        $this->userCd = $this->user('cd', Campaign::DEFAULT_ID);
        $this->userAlejandro = $this->user('ale', $this->alejandro);
    }

    private function user(string $key, string $campaignId): User
    {
        $user = User::create(['name' => "Usuario {$key}", 'email' => "{$key}@test.local", 'password' => 'secret123']);
        $user->forceFill(['campaign_id' => $campaignId])->save();

        return $user;
    }

    private function parentOf(string $id): ?string
    {
        return DB::table('campaigns')->where('id', $id)->value('parent_id');
    }

    public function test_dry_run_reports_the_impact_and_changes_nothing(): void
    {
        $this->artisan('data:campaign-parent', ['hijo' => 'alejandro-cardenas', 'padre' => 'centro-democratico', '--dry-run' => true])
            ->expectsOutputToContain('Alejandro Cardenas pasará a ser subgrupo de Centro Democrático.')
            ->expectsOutputToContain('--dry-run: no se cambió nada.')
            ->assertSuccessful();

        $this->assertNull($this->parentOf($this->alejandro));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_it_moves_the_group_audits_and_clears_the_caches(): void
    {
        Cache::put('compromisos-alertas:'.$this->userCd->id, ['stale' => true], 300);
        Cache::put('compromisos-alertas:'.$this->userAlejandro->id, ['stale' => true], 300);

        $this->artisan('data:campaign-parent', ['hijo' => $this->alejandro, 'padre' => 'centro-democratico'])
            ->expectsOutputToContain('Listo: Alejandro Cardenas es subgrupo de Centro Democrático.')
            ->assertSuccessful();

        $this->assertSame(Campaign::DEFAULT_ID, $this->parentOf($this->alejandro));
        $this->assertSame($this->alejandro, $this->parentOf($this->hijoAlejandro), 'Its own subgrupos move with it');
        $this->assertFalse(Cache::has('compromisos-alertas:'.$this->userCd->id));
        $this->assertFalse(Cache::has('compromisos-alertas:'.$this->userAlejandro->id));

        $log = DB::table('audit_logs')->sole();
        $this->assertSame('CAMPANA_PADRE_CAMBIADO', $log->action);
        $this->assertSame('campana', $log->entity_type);
        $this->assertSame($this->alejandro, $log->entity_id);
        $this->assertSame(['parent_id' => null], json_decode($log->before_state, true));
        $this->assertSame(['parent_id' => Campaign::DEFAULT_ID], json_decode($log->after_state, true));
        $this->assertSame([Campaign::DEFAULT_ID], json_decode($log->metadata, true)['ganan_lectura']);
    }

    public function test_after_the_move_the_cd_sees_the_subgrupo_and_alejandro_still_only_his_own(): void
    {
        $this->artisan('data:campaign-parent', ['hijo' => 'alejandro-cardenas', 'padre' => 'centro-democratico'])->assertSuccessful();

        $visible = fn (User $u) => (new CurrentCampaign)->visibleIdsFor($u->fresh());
        $this->assertEqualsCanonicalizing([Campaign::DEFAULT_ID, $this->alejandro, $this->hijoAlejandro], $visible($this->userCd));
        $this->assertEqualsCanonicalizing([$this->alejandro, $this->hijoAlejandro], $visible($this->userAlejandro));
    }

    public function test_the_root_group_never_gets_a_parent(): void
    {
        $this->artisan('data:campaign-parent', ['hijo' => 'centro-democratico', 'padre' => 'alejandro-cardenas'])
            ->expectsOutputToContain('El grupo político principal no puede tener grupo principal.')
            ->assertFailed();

        $this->assertNull($this->parentOf(Campaign::DEFAULT_ID));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_cycle_is_rejected(): void
    {
        $this->artisan('data:campaign-parent', ['hijo' => 'alejandro-cardenas', 'padre' => 'equipo-alejandro'])
            ->expectsOutputToContain('Un grupo político no puede ser grupo principal de sí mismo')
            ->assertFailed();

        $this->assertNull($this->parentOf($this->alejandro));
    }

    public function test_an_unknown_group_fails(): void
    {
        $this->artisan('data:campaign-parent', ['hijo' => 'no-existe', 'padre' => 'centro-democratico'])
            ->expectsOutputToContain('Grupo político no encontrado: no-existe')
            ->assertFailed();
    }

    public function test_already_under_that_parent_is_a_no_op(): void
    {
        $this->artisan('data:campaign-parent', ['hijo' => 'equipo-alejandro', 'padre' => 'alejandro-cardenas'])
            ->expectsOutputToContain('ya es subgrupo de Alejandro Cardenas')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
