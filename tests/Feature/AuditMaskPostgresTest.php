<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in checks of the audit masking on a LOCAL PostgreSQL database (jsonb metadata, real grants):
// RUN_PGSQL_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=politica_electoral php artisan test --filter=AuditMaskPostgresTest
// Everything runs inside a transaction that is rolled back, including the real (non dry-run) command.
class AuditMaskPostgresTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_PGSQL_TESTS') || DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Opt-in: set RUN_PGSQL_TESTS=1 and DB_CONNECTION=pgsql against a local database.');
        }
    }

    public function test_history_command_masks_jsonb_rows_and_search_still_works(): void
    {
        $marker = 'pgmask-'.Str::random(10);
        $id = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id' => $id, 'action' => 'PUT '.$marker, 'entity_type' => 'votante',
            'metadata' => json_encode(['nombre' => $marker, 'cedula' => '1098123456', 'email' => 'rosa@correo.com',
                'contactos' => [['tipo' => 'celular', 'valor' => '3101112233']]]),
        ]);

        $this->artisan('audit:mask-history', ['--dry-run' => true])->assertSuccessful();
        $this->assertStringContainsString('1098123456', DB::table('audit_logs')->where('id', $id)->value('metadata'));

        $this->artisan('audit:mask-history')->assertSuccessful();

        $meta = json_decode(DB::table('audit_logs')->where('id', $id)->value('metadata'), true);
        $this->assertEquals(['nombre' => $marker, 'email' => 'r***@correo.com', 'contactos' => [['tipo' => 'celular', 'valor' => '[omitido]']],
            'cedula_last4' => '3456'], $meta);
        $this->assertSame(0, DB::table('audit_logs')->whereRaw("metadata::text LIKE '%1098123456%'")->count());

        $this->assertSame(1, DB::table('audit_logs')->whereRaw('metadata::text ILIKE ?', ["%{$marker}%"])->count());
    }

    public function test_new_rows_are_masked_on_jsonb(): void
    {
        $log = AuditLog::create(['action' => 'LOGIN', 'entity_type' => 'session', 'metadata' => ['email' => 'operador@campana.co', 'telefono' => '3001234567']]);

        $this->assertEquals(['email' => 'o***@campana.co', 'telefono_last4' => '4567'],
            json_decode(DB::table('audit_logs')->where('id', $log->id)->value('metadata'), true));
    }
}
