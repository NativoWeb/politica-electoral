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
