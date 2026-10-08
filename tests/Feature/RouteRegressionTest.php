<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\ExportController;

class RouteRegressionTest extends TestCase
{
    public function test_login_page_is_accessible(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_root_redirects_to_login_or_mapa(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
    }

    public function test_forgot_password_page_is_accessible(): void
    {
        $response = $this->get('/olvide-password');

        $response->assertStatus(200);
    }

    public function test_export_routes_require_authentication(): void
    {
        $response = $this->get('/exportar/excel');
        $this->assertContains($response->getStatusCode(), [302, 401]);

        $response = $this->get('/exportar/pdf');
        $this->assertContains($response->getStatusCode(), [302, 401]);
    }

    public function test_mapa_politico_requires_authentication(): void
    {
        $response = $this->get('/mapa-politico');

        // Should redirect to login (302) or return 401
        $this->assertContains($response->getStatusCode(), [302, 401]);
    }

    public function test_offline_download_requires_authentication(): void
    {
        $response = $this->getJson('/api/offline/download/test-uuid');

        $response->assertStatus(401);
    }

    public function test_crear_lider_requires_authentication(): void
    {
        $response = $this->postJson('/mapa-politico/crear-lider', [
            'nombre' => 'Test',
            'municipio_id' => 'test-uuid',
        ]);

        $response->assertStatus(401);
    }

    public function test_password_reset_routes_exist(): void
    {
        // POST olvide-password should not 404 (it may fail validation, but route exists)
        $response = $this->post('/olvide-password', ['email' => 'test@test.com']);
        $this->assertNotEquals(404, $response->getStatusCode());

        // GET resetear-password/{token} should not 404
        $response = $this->get('/resetear-password/test-token?email=test@test.com');
        $this->assertNotEquals(404, $response->getStatusCode());

        // POST resetear-password should not 404
        $response = $this->post('/resetear-password', [
            'token' => 'test',
            'email' => 'test@test.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ]);
        $this->assertNotEquals(404, $response->getStatusCode());
    }

    public function test_pdf_max_rows_constant_is_defined(): void
    {
        $reflection = new \ReflectionClass(ExportController::class);
        $constant = $reflection->getReflectionConstant('PDF_MAX_ROWS');
        $this->assertNotFalse($constant);
        $this->assertEquals(500, $constant->getValue());
    }
}
