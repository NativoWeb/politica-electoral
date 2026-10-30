<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in: the Meta status webhook matches recipients with regexp_replace (PostgreSQL only).
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=WhatsAppStatusByPhonePostgresTest
// Every change is rolled back by DatabaseTransactions.
class WhatsAppStatusByPhonePostgresTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'status-test-token';

    private const PHONE = '3990001122';

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }

        config(['services.n8n.birthday_token' => self::TOKEN]);
    }

    private function recipient(string $campaignId, string $createdAt): array
    {
        $template = DB::table('whatsapp_templates')->insertGetId([
            'name' => 'Estado PG', 'meta_name' => 'estado_pg_'.Str::random(8), 'body_text' => 'x', 'language' => 'es',
            'campaign_id' => $campaignId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $send = DB::table('whatsapp_sends')->insertGetId(['template_id' => $template, 'campaign_id' => $campaignId, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $recipient = DB::table('whatsapp_send_recipients')->insertGetId([
            'whatsapp_send_id' => $send, 'person_id' => (string) Str::uuid(), 'name' => 'Estado PG', 'phone' => '+57 '.self::PHONE,
            'status' => 'pendiente', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return [$send, $recipient];
    }

    private function postStatus(array $payload)
    {
        return $this->postJson('/api/whatsapp/recipients/status-by-phone', $payload, ['X-Birthday-Token' => self::TOKEN]);
    }

    public function test_status_by_phone_ignores_sends_of_campaigns_without_whatsapp_and_honours_send_id(): void
    {
        $sinWhatsapp = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $sinWhatsapp, 'name' => 'Sin WhatsApp PG', 'slug' => 'sin-whatsapp-pg-'.Str::random(6), 'parent_id' => Campaign::DEFAULT_ID, 'whatsapp_enabled' => false]);

        [$oldSend, $oldCd] = $this->recipient(Campaign::DEFAULT_ID, '2100-01-01 10:00:00');
        [, $newCd] = $this->recipient(Campaign::DEFAULT_ID, '2100-01-02 10:00:00');
        [, $disabled] = $this->recipient($sinWhatsapp, '2100-01-03 10:00:00');

        // The most recent match is in a campaign without WhatsApp: it is skipped.
        $this->postStatus(['phone' => '57'.self::PHONE, 'status' => 'delivered'])->assertOk()->assertJsonPath('recipient_id', $newCd);
        $this->assertSame('pendiente', DB::table('whatsapp_send_recipients')->where('id', $disabled)->value('status'));
        $this->assertSame('enviado', DB::table('whatsapp_send_recipients')->where('id', $newCd)->value('status'));

        // With send_id only that send is considered.
        $this->postStatus(['phone' => self::PHONE, 'status' => 'read', 'send_id' => $oldSend])->assertOk()->assertJsonPath('recipient_id', $oldCd);
        $this->assertSame('leido', DB::table('whatsapp_send_recipients')->where('id', $oldCd)->value('status'));
    }
}
