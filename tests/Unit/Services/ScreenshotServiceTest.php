<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Tests\TestCase;

class ScreenshotServiceTest extends TestCase
{
    protected ScreenshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScreenshotService();
    }

    public function test_is_available_returns_bool(): void
    {
        $result = $this->service->isAvailable();
        $this->assertIsBool($result);
    }

    public function test_find_chrome_binary_returns_string_or_null(): void
    {
        $result = $this->service->findChromeBinary();
        $this->assertTrue(is_string($result) || is_null($result));
    }
}
