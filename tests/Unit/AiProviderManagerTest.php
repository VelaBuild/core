<?php

namespace VelaBuild\Core\Tests\Unit;

use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\OpenAiTextService;
use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Services\GeminiTextService;
use VelaBuild\Core\Services\OpenAiImageService;
use VelaBuild\Core\Services\GeminiImageService;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AiProviderManagerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_resolves_openai_text_provider_when_configured(): void
    {
        config([
            'vela.ai.openai.api_key' => 'test-key-123',
            'vela.ai.anthropic.api_key' => null,
            'vela.ai.gemini.api_key' => null,
            'vela.ai.default_text_provider' => 'openai',
        ]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(OpenAiTextService::class, $provider);
    }

    public function test_resolves_claude_text_provider_when_configured(): void
    {
        config([
            'vela.ai.openai.api_key' => null,
            'vela.ai.anthropic.api_key' => 'test-anthropic-key',
            'vela.ai.gemini.api_key' => null,
            'vela.ai.default_text_provider' => 'anthropic',
        ]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(ClaudeTextService::class, $provider);
    }

    public function test_resolves_gemini_text_provider_when_configured(): void
    {
        config([
            'vela.ai.openai.api_key' => null,
            'vela.ai.anthropic.api_key' => null,
            'vela.ai.gemini.api_key' => 'test-gemini-key',
            'vela.ai.default_text_provider' => 'gemini',
        ]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(GeminiTextService::class, $provider);
    }

    public function test_falls_back_when_preferred_provider_has_no_key(): void
    {
        config([
            'vela.ai.openai.api_key' => 'test-openai-key',
            'vela.ai.anthropic.api_key' => null,
            'vela.ai.gemini.api_key' => null,
            'vela.ai.default_text_provider' => 'anthropic',
        ]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(OpenAiTextService::class, $provider);
    }

    public function test_throws_when_no_text_provider_available(): void
    {
        config([
            'vela.ai.openai.api_key' => null,
            'vela.ai.anthropic.api_key' => null,
            'vela.ai.gemini.api_key' => null,
        ]);

        $manager = new AiProviderManager();

        $this->expectException(\RuntimeException::class);
        $manager->resolveTextProvider();
    }

    public function test_resolves_gemini_image_provider(): void
    {
        config([
            'vela.ai.gemini.api_key' => 'test-gemini-key',
            'vela.ai.openai.api_key' => null,
            'vela.ai.default_image_provider' => 'gemini',
        ]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveImageProvider();

        $this->assertInstanceOf(GeminiImageService::class, $provider);
    }

    public function test_resolves_openai_image_provider(): void
    {
        config([
            'vela.ai.openai.api_key' => 'test-openai-key',
            'vela.ai.gemini.api_key' => null,
        ]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveImageProvider('openai');

        $this->assertInstanceOf(OpenAiImageService::class, $provider);
    }

    public function test_has_text_provider_returns_false_with_no_keys(): void
    {
        config([
            'vela.ai.openai.api_key' => null,
            'vela.ai.anthropic.api_key' => null,
            'vela.ai.gemini.api_key' => null,
        ]);

        $manager = new AiProviderManager();

        $this->assertFalse($manager->hasTextProvider());
    }

    public function test_available_providers_lists_configured_ones(): void
    {
        config([
            'vela.ai.openai.api_key' => 'test-openai-key',
            'vela.ai.anthropic.api_key' => 'test-anthropic-key',
            'vela.ai.gemini.api_key' => null,
        ]);

        $manager = new AiProviderManager();
        $providers = $manager->availableProviders('text');

        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
        $this->assertNotContains('gemini', $providers);
    }
}
