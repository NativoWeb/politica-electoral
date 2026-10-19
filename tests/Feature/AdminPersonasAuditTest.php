<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Electoral persons edited from /admin/personas: birth and document dates and contact values never reach audit_logs in full.
class AdminPersonasAuditTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code');
            $t->string('name');
            $t->string('description')->nullable();
            $t->integer('level')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->uuid('role_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->json('permissions')->nullable();
            $t->json('territory_scope')->nullable();
            $t->string('preferred_density')->nullable();
            $t->boolean('executive_mode')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['first_name', 'last_name', 'full_name', 'normalized_name', 'photo_path', 'gender', 'document_type',
                'doc_hash', 'doc_last4', 'identity_status', 'data_classification'] as $col) {
                $t->string($col)->nullable();
            }
            $t->date('birth_date')->nullable();
            $t->uuid('merged_into_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('person_contact_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('person_id');
            foreach (['type', 'value_raw', 'value_normalized', 'label', 'data_classification'] as $col) {
                $t->string($col)->nullable();
            }
            $t->boolean('is_current')->default(true);
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
        (require database_path('migrations/2026_10_19_000001_add_whatsapp_link_to_users.php'))->up();

        $role = Role::create(['code' => 'R02_ADMIN_FUNCIONAL', 'name' => 'Admin', 'level' => 5]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => ['view.admin'],
        ]);
    }

    private function metadataFor(string $action): array
    {
        return json_decode(DB::table('audit_logs')->where('action', $action)->value('metadata'), true);
    }

    public function test_person_crud_and_contacts_are_audited_without_personal_data(): void
    {
        $this->actingAs($this->admin)->post('/admin/personas', [
            'first_name' => 'Luz', 'last_name' => 'Marina Prueba', 'birth_date' => '1970-03-04', 'gender' => 'female',
        ])->assertRedirect();
        $personId = DB::table('persons')->value('id');
        $this->assertSame('1970-03-04', substr((string) DB::table('persons')->value('birth_date'), 0, 10));

        $this->put("/admin/personas/{$personId}", [
            'first_name' => 'Luz', 'last_name' => 'Marina Prueba', 'birth_date' => '1971-05-06',
            'fecha_expedicion_doc' => '1989-12-01',
        ])->assertRedirect();

        foreach ([['phone', '+57 300 765 4321'], ['whatsapp', '3104445566'], ['email', 'luz.marina@correo.com'], ['other', '@luzmarina']] as [$type, $value]) {
            $this->post("/admin/personas/{$personId}/contacts", ['type' => $type, 'value' => $value, 'label' => 'Principal'])
                ->assertRedirect();
        }
        $this->assertSame(4, DB::table('person_contact_points')->where('person_id', $personId)->count());

        $json = DB::table('audit_logs')->pluck('metadata')->implode("\n");
        foreach (['1970-03-04', '1971-05-06', '1989-12-01', '765 4321', '7654321', '3104445566', 'luz.marina', '@luzmarina'] as $value) {
            $this->assertStringNotContainsString($value, $json);
        }

        $store = $this->metadataFor('POST admin.personas.store');
        $this->assertSame('Luz', $store['first_name']);
        $this->assertSame('[omitido]', $store['birth_date']);
        $update = $this->metadataFor('PUT admin.personas.update');
        $this->assertSame('[omitido]', $update['birth_date']);
        $this->assertSame('[omitido]', $update['fecha_expedicion_doc']);

        $contacts = DB::table('audit_logs')->where('action', 'POST admin.personas.contact.store')->orderBy('created_at')->pluck('metadata')
            ->map(fn ($m) => json_decode($m, true))->keyBy('type')->all();
        $this->assertSame('4321', $contacts['phone']['value_last4']);
        $this->assertSame('5566', $contacts['whatsapp']['value_last4']);
        $this->assertSame('l***@correo.com', $contacts['email']['value']);
        $this->assertSame('[omitido]', $contacts['other']['value']);
        $this->assertSame('Principal', $contacts['other']['label']);
        $this->assertSame($personId, DB::table('audit_logs')->where('action', 'POST admin.personas.contact.store')->value('entity_id'));
    }
}
