<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Audit\AuditDataMasker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditMaskHistoryTest extends TestCase
{
    private const LEGACY = [
        'nombre' => 'Rosa Histórica', 'cedula' => '1098123456', 'phone' => '+573001234567',
        'email' => 'rosa@correo.com', 'direccion' => 'Calle 9', 'contactos' => [['tipo' => 'celular', 'valor' => '3101112233']],
        'verificado' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

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

    private function legacyRow(?array $metadata, ?array $after = null): string
    {
        $id = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id' => $id, 'action' => 'PUT votantes', 'entity_type' => 'votante',
            'metadata' => $metadata === null ? null : json_encode($metadata),
            'after_state' => $after === null ? null : json_encode($after),
        ]);

        return $id;
    }

    private function metadataOf(string $id, string $column = 'metadata'): ?array
    {
        return json_decode((string) DB::table('audit_logs')->where('id', $id)->value($column), true);
    }

    public function test_masking_is_idempotent_and_keeps_the_field_names(): void
    {
        $masker = new AuditDataMasker;
        $once = $masker->mask(self::LEGACY + ['whatsapp_phone_last4' => '4567', 'tipo_documento' => 'CC', 'nota' => null]);

        $this->assertSame($once, $masker->mask($once));
        $this->assertSame([
            'nombre' => 'Rosa Histórica', 'cedula_last4' => '3456', 'phone_last4' => '4567', 'email' => 'r***@correo.com',
            'direccion' => '[omitido]', 'contactos' => [['tipo' => 'celular', 'valor' => '[omitido]']], 'verificado' => true,
            'whatsapp_phone_last4' => '4567', 'tipo_documento' => 'CC', 'nota' => null,
        ], $once);
        $this->assertSame(['email' => '[omitido]', 'telefono_last4' => null], $masker->mask(['email' => 'sin-arroba', 'telefono' => 'n/a']));
    }

    public function test_contact_values_are_masked_by_their_sibling_type(): void
    {
        $masker = new AuditDataMasker;
        $masked = $masker->mask([
            'contacts' => [
                ['type' => 'phone', 'value' => '+57 300 123 4567', 'label' => 'Casa'],
                ['type' => 'WhatsApp', 'value' => '3109876543'],
                ['tipo' => 'fijo', 'value' => '6076543210'],
                ['type' => 'email', 'value' => 'luz@correo.com'],
                ['type' => 'other', 'value' => '@luz_twitter'],
                ['value' => 'sin tipo'],
            ],
            'birth_date' => '1970-03-04', 'fecha_expedicion_doc' => '1990-01-01',
            'whatsapp_verified_at' => '2026-10-19 10:00:00', 'whatsapp_phone' => '+573001112222',
        ]);

        $this->assertSame([
            'contacts' => [
                ['type' => 'phone', 'value_last4' => '4567', 'label' => 'Casa'],
                ['type' => 'WhatsApp', 'value_last4' => '6543'],
                ['tipo' => 'fijo', 'value_last4' => '3210'],
                ['type' => 'email', 'value' => 'l***@correo.com'],
                ['type' => 'other', 'value' => '[omitido]'],
                ['value' => 'sin tipo'],
            ],
            'birth_date' => '[omitido]', 'fecha_expedicion_doc' => '[omitido]',
            'whatsapp_verified_at' => '2026-10-19 10:00:00', 'whatsapp_phone_last4' => '2222',
        ], $masked);
        $this->assertSame($masked, $masker->mask($masked));
    }

    public function test_political_affiliation_is_omitted_but_untyped_values_are_kept(): void
    {
        $masker = new AuditDataMasker;

        $this->assertSame([
            'nombre' => 'Rosa', 'partido' => '[omitido]', 'militante' => '[omitido]', 'partido_id' => '[omitido]',
            'afiliacion' => '[omitido]', 'militancia' => null, 'gran_elector' => true,
        ], $masker->mask([
            'nombre' => 'Rosa', 'partido' => 'Partido X', 'militante' => false, 'partido_id' => 'abc',
            'afiliacion' => 'X', 'militancia' => null, 'gran_elector' => true,
        ]));

        $this->assertSame(['candidacy_id' => 'c1', 'metric_type' => 'votes', 'value' => 1234],
            $masker->mask(['candidacy_id' => 'c1', 'metric_type' => 'votes', 'value' => 1234]));
    }

    public function test_history_leaves_result_values_untouched(): void
    {
        $id = $this->legacyRow(['candidacy_id' => 'c1', 'metric_type' => 'votes', 'value' => 1234]);

        $this->artisan('audit:mask-history')
            ->expectsOutputToContain('Scanned 1 audit_logs rows; 0 masked.')
            ->assertSuccessful();
        $this->assertSame(['candidacy_id' => 'c1', 'metric_type' => 'votes', 'value' => 1234], $this->metadataOf($id));
    }

    public function test_history_keeps_empty_objects_and_reports_rows_it_cannot_encode(): void
    {
        $id = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id' => $id, 'action' => 'PUT votantes',
            'metadata' => '{"filtros":{},"cedula":"1098123456","lista":[]}',
        ]);

        $this->artisan('audit:mask-history')->assertSuccessful();

        $this->assertSame('{"filtros":{},"cedula_last4":"3456","lista":[]}',
            str_replace(' ', '', (string) DB::table('audit_logs')->where('id', $id)->value('metadata')));

        $this->app->instance(AuditDataMasker::class, new class extends AuditDataMasker
        {
            public function mask(?array $data): ?array
            {
                return ['valor' => NAN];
            }
        });

        $this->artisan('audit:mask-history')
            ->expectsOutputToContain("Row {$id} skipped: metadata could not be re-encoded")
            ->expectsOutputToContain('Scanned 1 audit_logs rows; 0 masked.')
            ->assertFailed();
        $this->assertStringContainsString('cedula_last4', DB::table('audit_logs')->where('id', $id)->value('metadata'));
    }

    public function test_every_audit_insert_is_masked_even_outside_the_middleware(): void
    {
        $log = AuditLog::create(['action' => 'LOGIN', 'entity_type' => 'session', 'metadata' => ['email' => 'operador@campana.co']]);

        $this->assertSame(['email' => 'o***@campana.co'], $this->metadataOf($log->id));
    }

    public function test_dry_run_counts_without_writing_and_real_run_masks_once(): void
    {
        $legacy = $this->legacyRow(self::LEGACY, ['telefono' => '3009876543']);
        $clean = $this->legacyRow(['verificado' => true, 'cedula_last4' => '1234']);
        $empty = $this->legacyRow(null);

        $this->artisan('audit:mask-history', ['--dry-run' => true])
            ->expectsOutputToContain('Scanned 3 audit_logs rows; 1 would be masked (dry run, nothing written).')
            ->assertSuccessful();
        $this->assertSame(self::LEGACY, $this->metadataOf($legacy));

        $this->artisan('audit:mask-history')
            ->expectsOutputToContain('Scanned 3 audit_logs rows; 1 masked.')
            ->assertSuccessful();

        $masked = $this->metadataOf($legacy);
        $this->assertSame('3456', $masked['cedula_last4']);
        $this->assertSame('r***@correo.com', $masked['email']);
        $this->assertSame('[omitido]', $masked['contactos'][0]['valor']);
        $this->assertArrayNotHasKey('cedula', $masked);
        $this->assertSame(['telefono_last4' => '6543'], $this->metadataOf($legacy, 'after_state'));
        $this->assertSame(['verificado' => true, 'cedula_last4' => '1234'], $this->metadataOf($clean));
        $this->assertNull($this->metadataOf($empty));

        $this->artisan('audit:mask-history')
            ->expectsOutputToContain('Scanned 3 audit_logs rows; 0 masked.')
            ->assertSuccessful();
    }
}
