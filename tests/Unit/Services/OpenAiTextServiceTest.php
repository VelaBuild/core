<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\OpenAiTextService;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiTextServiceTest extends TestCase
{
    public function test_returns_null_when_no_api_key(): void
    {
        config(['vela.ai.openai.api_key' => null]);

        Log::shouldReceive('warning')
            ->once()
            ->with('Vela: OpenAI API key not configured');

        $service = new OpenAiTextService();
        $result = $service->generateText('Test prompt');

        $this->assertNull($result);
    }

    public function test_generate_text_calls_api(): void
    {
        config(['vela.ai.openai.api_key' => 'test-api-key-123']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated text response'
                        ]
                    ]
                ]
            ], 200),
        ]);

        Log::shouldReceive('info')->once();

        $service = new OpenAiTextService();
        $result = $service->generateText('Test prompt');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('choices', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-api-key-123')
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_returns_null_on_api_failure(): void
    {
        config(['vela.ai.openai.api_key' => 'test-api-key-123']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([], 500),
        ]);

        Log::shouldReceive('error')->once();

        $service = new OpenAiTextService();
        $result = $service->generateText('Test prompt');

        $this->assertNull($result);
    }
}
