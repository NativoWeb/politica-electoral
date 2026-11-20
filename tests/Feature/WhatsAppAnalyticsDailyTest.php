<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// n8n posts the daily metrics of the single WhatsApp Business account with a shared token.
class WhatsAppAnalyticsDailyTest extends TestCase
{
    private const TOKEN = 'analytics-test-token';

    private const WABA = '1774484810096337';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.n8n.whatsapp_analytics_token' => self::TOKEN, 'services.n8n.whatsapp_waba_id' => self::WABA]);
        (require database_path('migrations/2026_11_19_182454_create_whatsapp_daily_analytics_table.php'))->up();
    }

    private function payload(array $data = []): array
    {
        return [
            'waba_id' => self::WABA,
            'data' => $data ?: [
                ['date' => '2026-11-16', 'sent' => 100, 'delivered' => 90, 'volume' => 100, 'cost' => 1.5],
                ['date' => '2026-11-17', 'sent' => 50, 'delivered' => 45, 'volume' => 50, 'cost' => 0.75],
            ],
        ];
    }

    private function send(array $payload, array $headers = ['X-WhatsApp-Analytics-Token' => self::TOKEN])
    {
        return $this->postJson('/api/whatsapp/analytics/daily', $payload, $headers);
    }

    public function test_an_empty_configured_token_refuses_everything(): void
    {
        config(['services.n8n.whatsapp_analytics_token' => '']);

        $this->send($this->payload(), ['X-WhatsApp-Analytics-Token' => ''])->assertUnauthorized();
        $this->send($this->payload(), [])->assertUnauthorized();
        $this->assertSame(0, DB::table('whatsapp_daily_analytics')->count());
    }

    public function test_a_wrong_or_missing_token_gets_401(): void
    {
        $this->send($this->payload(), ['X-WhatsApp-Analytics-Token' => 'otro'])->assertUnauthorized();
        $this->send($this->payload(), ['Authorization' => 'Bearer otro'])->assertUnauthorized();
        $this->send($this->payload(), [])->assertUnauthorized();
        $this->assertSame(0, DB::table('whatsapp_daily_analytics')->count());
    }

    public function test_the_right_token_in_the_header_stores_the_days(): void
    {
        $this->send($this->payload())->assertOk()->assertJson(['waba_id' => self::WABA, 'records_received' => 2]);

        $row = DB::table('whatsapp_daily_analytics')->where('date', '2026-11-16')->first();
        $this->assertSame(self::WABA, $row->waba_id);
        $this->assertSame([100, 90, 100], [(int) $row->sent, (int) $row->delivered, (int) $row->volume]);
        $this->assertEqualsWithDelta(1.5, (float) $row->cost, 0.000001);
        $this->assertSame(2, DB::table('whatsapp_daily_analytics')->count());
    }

    public function test_the_right_token_as_bearer_stores_the_days(): void
    {
        $this->send($this->payload(), ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        $this->assertSame(2, DB::table('whatsapp_daily_analytics')->count());
    }

    public function test_repeating_a_day_updates_it_without_a_duplicate(): void
    {
        $this->send($this->payload())->assertOk();
        $this->send($this->payload([
            ['date' => '2026-11-16', 'sent' => 120, 'delivered' => 110, 'volume' => 120, 'cost' => 2],
        ]))->assertOk();

        $this->assertSame(2, DB::table('whatsapp_daily_analytics')->count());
        $this->assertSame(110, (int) DB::table('whatsapp_daily_analytics')->where('date', '2026-11-16')->value('delivered'));
        $this->assertSame(45, (int) DB::table('whatsapp_daily_analytics')->where('date', '2026-11-17')->value('delivered'));
    }

    public function test_another_waba_id_gets_422(): void
    {
        $this->send(['waba_id' => '999'] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors('waba_id');

        $this->assertSame(0, DB::table('whatsapp_daily_analytics')->count());
    }

    public function test_invalid_data_gets_422_and_stores_nothing(): void
    {
        $day = ['date' => '2026-11-16', 'sent' => 1, 'delivered' => 1, 'volume' => 1, 'cost' => 1];

        foreach ([
            ['data' => []],
            ['data' => [['date' => '16/11/2026'] + $day]],
            ['data' => [['sent' => -1] + $day]],
            ['data' => [['delivered' => 'muchos'] + $day]],
            ['data' => [['volume' => 2147483648] + $day]],
            ['data' => [['cost' => -0.5] + $day]],
            ['data' => [array_diff_key($day, ['cost' => 1])]],
            // The same date twice in one batch: upsert would keep only one of them silently.
            ['data' => [$day, ['sent' => 5] + $day]],
        ] as $override) {
            $this->send(array_merge($this->payload(), $override))->assertUnprocessable();
        }

        $this->assertSame(0, DB::table('whatsapp_daily_analytics')->count());
    }

    public function test_the_largest_integer_is_accepted(): void
    {
        $this->send($this->payload([
            ['date' => '2026-11-16', 'sent' => 2147483647, 'delivered' => 0, 'volume' => 0, 'cost' => 0],
        ]))->assertOk();

        $this->assertSame(2147483647, (int) DB::table('whatsapp_daily_analytics')->value('sent'));
    }
}
