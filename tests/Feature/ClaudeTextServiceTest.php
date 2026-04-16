<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeTextServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('vela.ai.anthropic.api_key', 'test-anthropic-key');
    }

    public function test_sends_correct_anthropic_headers(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello world']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $service = new ClaudeTextService();
        $service->generateText('Test prompt');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key')[0] === 'test-anthropic-key'
                && $request->header('anthropic-version')[0] === '2023-06-01';
        });
    }

    public function test_generate_text_returns_extracted_string(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello from Claude']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $service = new ClaudeTextService();
        $result = $service->generateText('Test prompt');

        $this->assertIsString($result);
        $this->assertEquals('Hello from Claude', $result);
    }

    public function test_generate_text_returns_null_on_failure(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $service = new ClaudeTextService();
        $result = $service->generateText('Test prompt');

        $this->assertNull($result);
    }

    public function test_generate_text_returns_null_with_no_api_key(): void
    {
        config()->set('vela.ai.anthropic.api_key', '');

        Log::shouldReceive('warning')
            ->once()
            ->with('Vela: Anthropic API key not configured');

        $service = new ClaudeTextService();
        $result = $service->generateText('Test prompt');

        $this->assertNull($result);
    }

    public function test_chat_returns_normalized_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'I will call the tool.'],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_123',
                        'name' => 'get_site_config',
                        'input' => ['key' => 'site_name'],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 15],
            ], 200),
        ]);

        $service = new ClaudeTextService();
        $result = $service->chat([
            ['role' => 'user', 'content' => 'What is the site name?'],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('tool_calls', $result);
        $this->assertArrayHasKey('usage', $result);

        $this->assertEquals('I will call the tool.', $result['content']);
        $this->assertNotNull($result['tool_calls']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertEquals('get_site_config', $result['tool_calls'][0]['name']);
        $this->assertEquals('toolu_123', $result['tool_calls'][0]['id']);
        $this->assertArrayHasKey('input', $result['usage']);
        $this->assertArrayHasKey('output', $result['usage']);
    }
}
