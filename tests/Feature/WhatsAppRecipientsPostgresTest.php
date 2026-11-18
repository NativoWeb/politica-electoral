<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: the WhatsApp screen with tens of thousands of lideres: filters and pages in SQL, and "select all that match"
// counted before reading, with a cap per send.
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=WhatsAppRecipientsPostgresTest
class WhatsAppRecipientsPostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const MUNICIPIO = 'Municipio WhatsApp Paginado PG';

    private string $mun;

    private int $templateId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        $this->withoutVite();
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['whatsapp_enabled' => true]);
        $this->mun = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $this->mun, 'type' => 'municipality', 'canonical_name' => self::MUNICIPIO]);
        $this->templateId = (int) DB::table('whatsapp_templates')->insertGetId([
            'name' => 'Plantilla paginada', 'meta_name' => 'pag_pg_'.Str::random(6), 'body_text' => 'Hola {{1}}',
            'language' => 'es', 'is_active' => true, 'filters' => json_encode(['municipio']),
            'campaign_id' => Campaign::DEFAULT_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::create([
            'name' => 'WhatsApp PG', 'email' => 'wa-pg-'.Str::random(8).'@test.local', 'password' => 'secret123',
            'role_id' => Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id'),
            'permissions' => ['view.admin', 'view.mapa', 'view.votantes', 'write.data'],
        ]);
        $this->user->forceFill(['campaign_id' => Campaign::DEFAULT_ID])->save();

        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $this->mun,
                'nombre' => sprintf('Zzwa Persona %03d', $i), 'municipio' => self::MUNICIPIO, 'tipo' => 'votante',
                'telefono' => '3009'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('lideres')->insert($rows);
    }

    private function screen(array $query = []): array
    {
        return $this->actingAs($this->user)->get('/admin/whatsapp?'.http_build_query([
            'source' => 'lideres', 'template_id' => $this->templateId, 'filter_municipio' => 'whatsapp paginado', ...$query,
        ]))->assertOk()->viewData('page')['props'];
    }

    private function prepare()
    {
        return $this->actingAs($this->user)->postJson('/admin/whatsapp/prepare', [
            'template_id' => $this->templateId, 'select_all_matching' => true, 'source' => 'lideres',
            'filter_municipio' => 'whatsapp paginado',
        ]);
    }

    public function test_the_screen_filters_and_pages_the_lideres_in_sql(): void
    {
        $first = $this->screen();

        $this->assertSame(120, $first['contacts']['total']);
        $this->assertCount(100, $first['contacts']['data']);
        $this->assertSame('Zzwa Persona 000', $first['contacts']['data'][0]['name']);
        $this->assertSame('3009000000', $first['contacts']['data'][0]['phone']);
        $this->assertSame(self::MUNICIPIO, $first['contacts']['data'][0]['municipio']);
        $this->assertContains(self::MUNICIPIO, $first['filter_options']['municipio']);

        $second = $this->screen(['page' => 2]);
        $this->assertCount(20, $second['contacts']['data']);
        $this->assertSame('Zzwa Persona 119', $second['contacts']['data'][19]['name']);
    }

    public function test_select_all_over_the_cap_is_refused_before_reading_the_fichas(): void
    {
        config(['services.n8n.whatsapp_max_recipients' => 50, 'services.n8n.whatsapp_webhook' => 'https://n8n.test/webhook']);
        Http::fake();

        $this->prepare()->assertStatus(422)->assertJsonPath('message', 'Son 120 destinatarios y el máximo por envío es 50. Usa los filtros para dividir el envío.');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('whatsapp_sends')->where('template_id', $this->templateId)->count());
    }

    public function test_the_ones_unchecked_by_hand_do_not_count_against_the_cap(): void
    {
        config(['services.n8n.whatsapp_max_recipients' => 119, 'services.n8n.whatsapp_webhook' => 'https://n8n.test/webhook']);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $excluded = DB::table('lideres')->where('nombre', 'Zzwa Persona 000')->value('id');

        $this->actingAs($this->user)->postJson('/admin/whatsapp/prepare', [
            'template_id' => $this->templateId, 'select_all_matching' => true, 'source' => 'lideres',
            'filter_municipio' => 'whatsapp paginado', 'excluded_contact_ids' => [$excluded],
        ])->assertOk()->assertJsonPath('recipients_count', 119);
    }

    public function test_select_all_under_the_cap_creates_every_recipient(): void
    {
        config(['services.n8n.whatsapp_max_recipients' => 500, 'services.n8n.whatsapp_webhook' => 'https://n8n.test/webhook']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->prepare()->assertOk()->assertJsonPath('recipients_count', 120);

        $sendId = DB::table('whatsapp_sends')->where('template_id', $this->templateId)->value('id');
        $this->assertSame(120, DB::table('whatsapp_send_recipients')->where('whatsapp_send_id', $sendId)->count());
        Http::assertSent(fn ($request) => count($request['recipients']) === 120);
    }
}
