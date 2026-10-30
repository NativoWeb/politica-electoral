<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\WithCampaignColumns;
use Tests\TestCase;

// n8n asks for today's birthdays without a user, so the Lider campaign scope does not apply: only lideres of
// campaigns with WhatsApp enabled may receive the message.
class CampaignWhatsAppBirthdayTest extends TestCase
{
    use WithCampaignColumns;

    private const TOKEN = 'birthday-test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.n8n.birthday_token' => self::TOKEN]);

        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('full_name');
            $t->uuid('merged_into_id')->nullable();
            $t->date('birth_date')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('person_contact_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            $t->string('type');
            $t->string('value_raw')->nullable();
            $t->string('value_normalized')->nullable();
            $t->boolean('is_current')->default(true);
        });
        Schema::create('lideres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('telefono')->nullable();
            $t->date('fecha_nacimiento')->nullable();
            $t->timestamps();
        });
        Schema::create('lider_contactos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('lider_id');
            $t->string('valor')->nullable();
            $t->string('estado')->nullable();
            $t->boolean('principal')->default(false);
            $t->timestamps();
        });
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'meta_name', 'body_text', 'language'] as $col) {
                $t->string($col)->nullable();
            }
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('whatsapp_sends', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('template_id');
            $t->string('campaign_type')->nullable();
            $t->date('campaign_date')->nullable();
            $t->timestamps();
        });
        Schema::create('whatsapp_send_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('whatsapp_send_id');
            $t->uuid('person_id')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->addCampaignColumns();
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['whatsapp_enabled' => true]);

        DB::table('whatsapp_templates')->insert(['name' => 'Cumpleaños', 'meta_name' => 'oscarfc', 'is_active' => true, 'language' => 'es']);
    }

    private function cumpleanero(string $campaignId, string $nombre): void
    {
        $today = Carbon::now('America/Bogota');
        DB::table('lideres')->insert([
            'id' => (string) Str::uuid(), 'campaign_id' => $campaignId, 'nombre' => $nombre,
            'telefono' => '3001112233', 'fecha_nacimiento' => $today->copy()->subYears(40)->toDateString(),
        ]);
    }

    public function test_birthdays_only_include_campaigns_with_whatsapp_enabled(): void
    {
        $sinWhatsapp = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $sinWhatsapp, 'name' => 'Sin WhatsApp', 'slug' => 'sin-whatsapp', 'parent_id' => Campaign::DEFAULT_ID, 'whatsapp_enabled' => false]);
        $this->cumpleanero(Campaign::DEFAULT_ID, 'Cumple CD');
        $this->cumpleanero($sinWhatsapp, 'Cumple Sin WhatsApp');

        $names = collect($this->getJson('/api/whatsapp/birthdays/today', ['X-Birthday-Token' => self::TOKEN])
            ->assertOk()->json('recipients'))->pluck('name')->all();

        $this->assertSame(['Cumple CD'], $names);
    }

    public function test_template_campaign_without_whatsapp_gets_no_recipients_at_all(): void
    {
        $this->cumpleanero(Campaign::DEFAULT_ID, 'Cumple CD');
        $person = (string) Str::uuid();
        DB::table('persons')->insert(['id' => $person, 'full_name' => 'Candidato Cumpleañero', 'birth_date' => Carbon::now('America/Bogota')->subYears(50)->toDateString()]);
        DB::table('person_contact_points')->insert(['id' => (string) Str::uuid(), 'person_id' => $person, 'type' => 'mobile', 'value_raw' => '3009998877']);
        DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['whatsapp_enabled' => false]);

        $this->getJson('/api/whatsapp/birthdays/today', ['X-Birthday-Token' => self::TOKEN])
            ->assertOk()->assertJsonPath('recipients_count', 0)->assertJsonPath('recipients', []);
        $this->assertSame(0, DB::table('whatsapp_send_recipients')->count());
    }

    // The birthday template belongs to the CD: a subcampaign's lideres are not its recipients, even with WhatsApp.
    public function test_birthdays_only_reach_lideres_of_the_templates_campaign(): void
    {
        $campX = (string) Str::uuid();
        DB::table('campaigns')->insert(['id' => $campX, 'name' => 'Alejandro', 'slug' => 'alejandro', 'parent_id' => Campaign::DEFAULT_ID, 'whatsapp_enabled' => true]);
        $this->cumpleanero(Campaign::DEFAULT_ID, 'Cumple CD');
        $this->cumpleanero($campX, 'Cumple X');

        $response = $this->getJson('/api/whatsapp/birthdays/today', ['X-Birthday-Token' => self::TOKEN])->assertOk();

        $this->assertSame(['Cumple CD'], collect($response->json('recipients'))->pluck('name')->all());
        $this->assertSame(Campaign::DEFAULT_ID, DB::table('whatsapp_sends')->where('id', $response->json('send_id'))->value('campaign_id'));
        $this->assertSame(['Cumple CD'], DB::table('whatsapp_send_recipients')->pluck('name')->all());
    }
}
