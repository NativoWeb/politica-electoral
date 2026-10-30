<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Agent\AgentIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: renaming a votante through the agent keeps the mapa politico link to its electoral person (real data, rolled back).
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=VotanteRenamePostgresTest
class VotanteRenamePostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-agent-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        config(['agent.hmac_secret' => self::SECRET]);
    }

    public function test_agent_rename_keeps_the_mapa_politico_link(): void
    {
        $candidate = DB::selectOne("
            SELECT MIN(p.id::text) AS person_id, p.full_name, con.geographic_unit_id AS geo_id
            FROM persons p
            JOIN candidacies c ON c.person_id = p.id
            JOIN contests con ON con.id = c.contest_id
            JOIN geographic_units g ON g.id = con.geographic_unit_id AND g.type = 'municipality'
            WHERE p.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM lideres l WHERE LOWER(l.nombre) = LOWER(p.full_name))
            GROUP BY p.full_name, con.geographic_unit_id
            HAVING COUNT(DISTINCT p.id) = 1
              AND (SELECT COUNT(DISTINCT c2.person_id) FROM candidacies c2 JOIN persons p2 ON p2.id = c2.person_id
                   WHERE LOWER(p2.full_name) = LOWER(p.full_name)) = 1
            LIMIT 1
        ") ?? $this->markTestSkipped('No electoral person with a unique name in the local database.');

        $liderId = (string) Str::uuid();
        $cedula = '99'.random_int(10000000, 99999999);
        DB::table('lideres')->insert([
            'campaign_id' => Campaign::DEFAULT_ID,
            'id' => $liderId, 'nombre' => $candidate->full_name, 'cedula' => $cedula, 'cargo' => 'Lider',
            'municipio' => DB::table('geographic_units')->where('id', $candidate->geo_id)->value('canonical_name'),
            'geographic_unit_id' => $candidate->geo_id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin PG', 'email' => 'admin-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'territory_scope' => [$candidate->geo_id], 'permissions' => ['view.votantes', 'view.mapa', 'write.data'],
        ]);
        $user->forceFill(['whatsapp_phone' => '+5731'.random_int(10000000, 99999999), 'whatsapp_verified_at' => now()])->save();

        $this->actingAs($user)->getJson("/mapa-politico/persona/{$candidate->person_id}")
            ->assertOk()
            ->assertJsonPath('cedula', $cedula);

        $nuevo = 'Zzq Renombrado '.Str::upper(Str::random(6));
        $token = $user->createToken(AgentIdentityService::TOKEN_NAME, ['agent'], now()->addMinutes(15))->plainTextToken;
        $uri = "/api/agent/v1/votantes/{$liderId}";
        $raw = json_encode(['nombre' => $nuevo]);
        $ts = (string) now()->getTimestamp();
        $nonce = Str::random(32);
        $this->app['auth']->forgetGuards();
        $this->call('PATCH', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_AGENT_TIMESTAMP' => $ts, 'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => hash_hmac('sha256', "{$ts}\n{$nonce}\nPATCH\n{$uri}\n{$raw}", self::SECRET),
        ], $raw)->assertOk()->assertJsonMissingPath('warning');

        $this->assertSame($nuevo, DB::table('persons')->where('id', $candidate->person_id)->value('full_name'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->getJson("/mapa-politico/persona/{$candidate->person_id}")
            ->assertOk()
            ->assertJsonPath('nombre', $nuevo)
            ->assertJsonPath('cedula', $cedula);
    }
}
