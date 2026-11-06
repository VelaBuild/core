<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\AiChat\Tools\WebSearchTool;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\BrowserRenderingService;
use VelaBuild\Core\Services\GeminiImageService;
use VelaBuild\Core\Services\VelaGatewayClient;
use VelaBuild\Core\Services\VelaGatewayImageService;
use VelaBuild\Core\Services\VelaGatewayTextService;
use VelaBuild\Core\Tests\TestCase;

/**
 * Hosted mode: VELA_GATEWAY_* in env → every AI path (text, images, search,
 * rendering) goes through the gateway, signed, and nothing falls back to a
 * provider key.
 */
class VelaGatewayServicesTest extends TestCase
{
    private const ENV = [
        'VELA_GATEWAY_URL'    => 'https://ai.vela.test',
        'VELA_GATEWAY_SITE'   => 'site-demo',
        'VELA_GATEWAY_SECRET' => 'vsk_test_secret',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        config(['vela.browser_rendering.url' => null]);
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    private function assertValidSignature(HttpRequest $req, string $path): bool
    {
        $expected = hash_hmac('sha256', implode("\n", [
            'POST', $path, $req->header('X-Vela-Timestamp')[0], $req->header('X-Vela-Nonce')[0], hash('sha256', $req->body()),
        ]), self::ENV['VELA_GATEWAY_SECRET']);

        return $req->header('X-Vela-Site')[0] === 'site-demo'
            && hash_equals($expected, $req->header('X-Vela-Signature')[0]);
    }

    public function test_client_signs_path_and_body(): void
    {
        Http::fake(['ai.vela.test/*' => Http::response(['ok' => true], 200)]);

        $this->assertSame(['ok' => true], app(VelaGatewayClient::class)->post('/v1/search', ['query' => 'x']));

        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://ai.vela.test/v1/search'
            && $this->assertValidSignature($req, '/v1/search'));
    }

    public function test_client_reports_gateway_error_code(): void
    {
        Http::fake(['ai.vela.test/*' => Http::response(['error' => ['code' => 'insufficient_credits']], 402)]);

        $client = app(VelaGatewayClient::class);
        $this->assertNull($client->post('/v1/search', ['query' => 'x']));
        $this->assertSame(402, $client->lastStatus);
        $this->assertSame('insufficient_credits', $client->lastError);
    }

    public function test_text_still_goes_through_the_shared_client(): void
    {
        Http::fake(['ai.vela.test/*' => Http::response(['choices' => [['message' => ['content' => 'hello']]]], 200)]);

        $this->assertSame('hello', app(VelaGatewayTextService::class)->generateText('hi'));
        Http::assertSent(fn (HttpRequest $req) => $this->assertValidSignature($req, '/v1/chat/completions'));
    }

    public function test_text_uses_the_plans_default_model_unless_the_owner_picked_one(): void
    {
        Http::fake(['ai.vela.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        config(['vela.ai.gateway.default_model' => 'gpt-4o']);
        app(VelaGatewayTextService::class)->generateText('hi');
        Http::assertSent(fn (HttpRequest $req) => $req['model'] === 'gpt-4o');

        // Owner's choice (VELA_GATEWAY_MODEL / AI Settings) wins over the plan default.
        putenv('VELA_GATEWAY_MODEL=gemini-2.5-flash');
        try {
            app(VelaGatewayTextService::class)->generateText('hi');
            Http::assertSent(fn (HttpRequest $req) => $req['model'] === 'gemini-2.5-flash');
        } finally {
            putenv('VELA_GATEWAY_MODEL');
        }
    }

    public function test_image_provider_is_locked_to_the_gateway(): void
    {
        $this->setAiKeys(['gemini' => 'own-gemini-key', 'openai' => 'own-openai-key']);

        $manager = new AiProviderManager();

        $this->assertInstanceOf(VelaGatewayImageService::class, $manager->resolveImageProvider());
        $this->assertInstanceOf(VelaGatewayImageService::class, $manager->resolveImageProvider('gemini'));
    }

    public function test_image_generation_returns_base64(): void
    {
        Http::fake(['ai.vela.test/*' => Http::response(['created' => 1, 'data' => [['b64_json' => 'QUJD']]], 200)]);

        $out = app(VelaGatewayImageService::class)->generateImage('a boat', ['aspect_ratio' => '16:9']);

        $this->assertSame('QUJD', $out['data'][0]['b64_json']);
        Http::assertSent(fn (HttpRequest $req) => $req['model'] === VelaGatewayImageService::DEFAULT_MODEL
            && $req['aspect_ratio'] === '16:9' && $this->assertValidSignature($req, '/v1/images/generations'));
    }

    public function test_web_search_uses_gateway_and_never_falls_back_when_locked(): void
    {
        $this->setAiKeys(['gemini' => 'own-gemini-key']);
        Http::fake([
            'ai.vela.test/*' => Http::response(['error' => ['code' => 'insufficient_credits']], 402),
            '*' => Http::response(['should' => 'not be called'], 200),
        ]);

        $result = app(WebSearchTool::class)->execute(['query' => 'vela cms']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('credits', $result['error']);
        Http::assertSentCount(1);
    }

    public function test_web_search_results_are_mapped(): void
    {
        Http::fake(['ai.vela.test/*' => Http::response(['results' => [
            ['title' => 'Vela', 'url' => 'https://vela.build', 'snippet' => 'CMS'],
        ]], 200)]);

        $result = app(WebSearchTool::class)->execute(['query' => 'vela cms', 'count' => 3]);

        $this->assertTrue($result['success']);
        $this->assertSame('vela_gateway', $result['provider']);
        $this->assertSame('CMS', $result['results'][0]['description']);
        Http::assertSent(fn (HttpRequest $req) => $req['max_results'] === 3);
    }

    public function test_browser_rendering_is_configured_by_the_gateway(): void
    {
        Http::fake(['ai.vela.test/*' => Http::sequence()
            ->push(['action' => 'screenshot', 'data_b64' => 'UE5H', 'content_type' => 'image/png'], 200)
            ->push(['action' => 'evaluate', 'result' => ['result' => ['title' => 'Hi']]], 200)
            ->push(['action' => 'content', 'html' => '<p>x</p>'], 200)]);

        $renderer = app(BrowserRenderingService::class);
        $this->assertTrue($renderer->isConfigured());
        $this->assertSame('UE5H', $renderer->screenshot('https://example.com', ['full_page' => true]));
        $this->assertSame(['result' => ['title' => 'Hi']], $renderer->evaluate('https://example.com', 'document.title'));
        $this->assertSame('<p>x</p>', $renderer->html('https://example.com'));

        Http::assertSent(fn (HttpRequest $req) => $req['action'] === 'screenshot' && $req['options']['full_page'] === true
            && $this->assertValidSignature($req, '/v1/render'));
    }

    public function test_without_gateway_rendering_needs_its_own_worker(): void
    {
        foreach (array_keys(self::ENV) as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }

        $this->assertFalse(app(BrowserRenderingService::class)->isConfigured());
    }
}
