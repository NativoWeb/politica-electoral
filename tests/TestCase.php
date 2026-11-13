<?php

namespace Tests;

use App\Support\GeoTerritory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GeoTerritory::flush();
    }

    // The whole suite runs in one process close to its memory limit: free each test's reference cycles (apps,
    // closures, mocks) now instead of whenever PHP's collector happens to run.
    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }
}
