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
}
