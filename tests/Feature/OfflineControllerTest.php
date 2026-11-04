<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineControllerTest extends TestCase
{
    public function test_offline_download_requires_authentication(): void
    {
        $response = $this->getJson('/api/offline/download/'.Str::uuid());

        $response->assertStatus(401);
    }

    public function test_offline_download_route_exists(): void
    {
        $response = $this->getJson('/api/offline/download/'.Str::uuid());

        // Should be 401 (unauthenticated) NOT 404 (route not found)
        $this->assertNotEquals(404, $response->getStatusCode());
    }
}
