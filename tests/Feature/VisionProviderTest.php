<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\OpenAiTextService;
use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Services\GeminiTextService;

class VisionProviderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure all three providers have API keys available for testing
        putenv('ANTHROPIC_API_KEY=test-anthropic-key');
        putenv('GEMINI_API_KEY=test-gemini-key');
    }

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('GEMINI_API_KEY');
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // OpenAI
    // -------------------------------------------------------------------------

    public function test_openai_supports_vision_returns_true(): void
    {
        $service = new OpenAiTextService();
        $this->assertTrue($service->supportsVision());
    }

    public function test_openai_chat_with_image_content_sends_correct_format(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'test response']],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $service = new OpenAiTextService();

        $fakeBase64 = base64_encode('fake-png');
        $messages = [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Describe this image'],
                ['type' => 'image', 'source' => $fakeBase64, 'media_type' => 'image/png'],
            ]],
        ];

        $result = $service->chat($messages);

        Http::assertSent(function ($request) use ($fakeBase64) {
            $body = $request->data();
            $content = $body['messages'][0]['content'] ?? [];

            // Should have 2 content blocks
            if (count($content) !== 2) {
                return false;
            }

            // First block: text
            if ($content[0]['type'] !== 'text') {
                return false;
            }

            // Second block: image_url format (OpenAI)
            $imageBlock = $content[1];
            return $imageBlock['type'] === 'image_url'
                && isset($imageBlock['image_url']['url'])
                && str_starts_with($imageBlock['image_url']['url'], 'data:image/png;base64,');
        });

        $this->assertNotNull($result);
        $this->assertEquals('test response', $result['content']);
    }

    public function test_openai_chat_with_string_content_still_works(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Hello from OpenAI']],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $service = new OpenAiTextService();

        $result = $service->chat([
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('Hello from OpenAI', $result['content']);
        $this->assertNull($result['tool_calls']);
        $this->assertEquals(10, $result['usage']['input']);
        $this->assertEquals(5, $result['usage']['output']);
    }

    // -------------------------------------------------------------------------
    // Claude (Anthropic)
    // -------------------------------------------------------------------------

    public function test_claude_supports_vision_returns_true(): void
    {
        $service = new ClaudeTextService();
        $this->assertTrue($service->supportsVision());
    }

    public function test_claude_chat_with_image_content_sends_correct_format(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'test response']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $service = new ClaudeTextService();

        $fakeBase64 = base64_encode('fake-png');
        $messages = [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Describe this image'],
                ['type' => 'image', 'source' => $fakeBase64, 'media_type' => 'image/png'],
            ]],
        ];

        $result = $service->chat($messages);

        Http::assertSent(function ($request) use ($fakeBase64) {
            $body = $request->data();
            $content = $body['messages'][0]['content'] ?? [];

            if (count($content) !== 2) {
                return false;
            }

            // First block: text
            if ($content[0]['type'] !== 'text') {
                return false;
            }

            // Second block: Anthropic image format
            $imageBlock = $content[1];
            return $imageBlock['type'] === 'image'
                && isset($imageBlock['source']['type'])
                && $imageBlock['source']['type'] === 'base64'
                && $imageBlock['source']['media_type'] === 'image/png'
                && $imageBlock['source']['data'] === $fakeBase64;
        });

        $this->assertNotNull($result);
        $this->assertEquals('test response', $result['content']);
    }

    public function test_claude_chat_with_string_content_still_works(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello from Claude']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $service = new ClaudeTextService();

        $result = $service->chat([
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('Hello from Claude', $result['content']);
        $this->assertNull($result['tool_calls']);
        $this->assertEquals(10, $result['usage']['input']);
        $this->assertEquals(5, $result['usage']['output']);
    }

    // -------------------------------------------------------------------------
    // Gemini
    // -------------------------------------------------------------------------

    public function test_gemini_supports_vision_returns_true(): void
    {
        $service = new GeminiTextService();
        $this->assertTrue($service->supportsVision());
    }

    public function test_gemini_chat_with_image_content_sends_correct_format(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'test response']]]],
                ],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ], 200),
        ]);

        $service = new GeminiTextService();

        $fakeBase64 = base64_encode('fake-png');
        $messages = [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Describe this image'],
                ['type' => 'image', 'source' => $fakeBase64, 'media_type' => 'image/png'],
            ]],
        ];

        $result = $service->chat($messages);

        Http::assertSent(function ($request) use ($fakeBase64) {
            $body = $request->data();
            $parts = $body['contents'][0]['parts'] ?? [];

            if (count($parts) !== 2) {
                return false;
            }

            // First part: text
            if (!isset($parts[0]['text'])) {
                return false;
            }

            // Second part: inlineData format (Gemini)
            $imagePart = $parts[1];
            return isset($imagePart['inlineData'])
                && $imagePart['inlineData']['mimeType'] === 'image/png'
                && $imagePart['inlineData']['data'] === $fakeBase64;
        });

        $this->assertNotNull($result);
        $this->assertEquals('test response', $result['content']);
    }

    public function test_gemini_chat_with_string_content_still_works(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Hello from Gemini']]]],
                ],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ], 200),
        ]);

        $service = new GeminiTextService();

        $result = $service->chat([
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('Hello from Gemini', $result['content']);
        $this->assertNull($result['tool_calls']);
    }
}
