<?php

namespace VelaBuild\Core\Tests\Unit;

use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\AiSettingsService;
use VelaBuild\Core\Services\OpenAiTextService;
use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Services\GeminiTextService;
use VelaBuild\Core\Services\OpenAiImageService;
use VelaBuild\Core\Services\GeminiImageService;
use VelaBuild\Core\Tests\TestCase;

class AiProviderManagerTest extends TestCase
{
    public function test_resolves_openai_text_provider_when_configured(): void
    {
        $this->setAiKeys(['openai' => 'test-key-123', 'anthropic' => null, 'gemini' => null]);
        app(AiSettingsService::class)->set('chat_provider', 'openai');

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(OpenAiTextService::class, $provider);
    }

    public function test_resolves_claude_text_provider_when_configured(): void
    {
        $this->setAiKeys(['openai' => null, 'anthropic' => 'test-anthropic-key', 'gemini' => null]);
        app(AiSettingsService::class)->set('chat_provider', 'anthropic');

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(ClaudeTextService::class, $provider);
    }

    public function test_resolves_gemini_text_provider_when_configured(): void
    {
        $this->setAiKeys(['openai' => null, 'anthropic' => null, 'gemini' => 'test-gemini-key']);
        app(AiSettingsService::class)->set('chat_provider', 'gemini');

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(GeminiTextService::class, $provider);
    }

    public function test_falls_back_when_preferred_provider_has_no_key(): void
    {
        $this->setAiKeys(['openai' => 'test-openai-key', 'anthropic' => null, 'gemini' => null]);
        app(AiSettingsService::class)->set('chat_provider', 'anthropic');

        $manager = new AiProviderManager();
        $provider = $manager->resolveTextProvider();

        $this->assertInstanceOf(OpenAiTextService::class, $provider);
    }

    public function test_throws_when_no_text_provider_available(): void
    {
        $this->setAiKeys(['openai' => null, 'anthropic' => null, 'gemini' => null]);

        $manager = new AiProviderManager();

        $this->expectException(\RuntimeException::class);
        $manager->resolveTextProvider();
    }

    public function test_resolves_gemini_image_provider(): void
    {
        $this->setAiKeys(['gemini' => 'test-gemini-key', 'openai' => null]);
        app(AiSettingsService::class)->set('image_provider', 'gemini');

        $manager = new AiProviderManager();
        $provider = $manager->resolveImageProvider();

        $this->assertInstanceOf(GeminiImageService::class, $provider);
    }

    public function test_resolves_openai_image_provider(): void
    {
        $this->setAiKeys(['openai' => 'test-openai-key', 'gemini' => null]);

        $manager = new AiProviderManager();
        $provider = $manager->resolveImageProvider('openai');

        $this->assertInstanceOf(OpenAiImageService::class, $provider);
    }

    public function test_has_text_provider_returns_false_with_no_keys(): void
    {
        $this->setAiKeys(['openai' => null, 'anthropic' => null, 'gemini' => null]);

        $manager = new AiProviderManager();

        $this->assertFalse($manager->hasTextProvider());
    }

    public function test_available_providers_lists_configured_ones(): void
    {
        $this->setAiKeys(['openai' => 'test-openai-key', 'anthropic' => 'test-anthropic-key', 'gemini' => null]);

        $manager = new AiProviderManager();
        $providers = $manager->availableProviders('text');

        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
        $this->assertNotContains('gemini', $providers);
    }
}
