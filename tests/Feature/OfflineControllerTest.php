<?php

namespace Tests\Feature;

use Tests\TestCase;

class OfflineControllerTest extends TestCase
{
    public function test_offline_download_requires_authentication(): void
    {
        $response = $this->getJson('/api/offline/download/fake-uuid');

        $response->assertStatus(401);
    }

    public function test_offline_download_route_exists(): void
    {
        $response = $this->getJson('/api/offline/download/some-id');

        // Should be 401 (unauthenticated) NOT 404 (route not found)
        $this->assertNotEquals(404, $response->getStatusCode());
    }
}
