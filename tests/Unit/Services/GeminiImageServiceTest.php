<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\GeminiImageService;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiImageServiceTest extends TestCase
{
    public function test_returns_null_when_no_api_key(): void
    {
        config(['vela.ai.gemini.api_key' => null]);

        Log::shouldReceive('warning')
            ->once()
            ->with('Vela: Gemini API key not configured');

        $service = new GeminiImageService();
        $result = $service->generateImage('Test prompt');

        $this->assertNull($result);
    }

    public function test_returns_null_on_api_failure(): void
    {
        config(['vela.ai.gemini.api_key' => 'test-gemini-key-123']);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500),
        ]);

        Log::shouldReceive('error')->once();

        $service = new GeminiImageService();
        $result = $service->generateImage('Test prompt');

        $this->assertNull($result);
    }
}
