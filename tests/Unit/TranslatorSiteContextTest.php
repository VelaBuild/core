<?php

namespace VelaBuild\Core\Tests\Unit;

use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\Translator;
use VelaBuild\Core\Tests\TestCase;

class TranslatorSiteContextTest extends TestCase
{
    public function test_the_prompt_says_what_the_site_is(): void
    {
        config(['app.name' => 'Northbeam Coffee', 'vela.site.description' => 'Small-batch coffee roasted in Bristol']);

        $provider = new class implements \VelaBuild\Core\Contracts\AiTextProvider {
            public string $prompt = '';

            public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
            {
                $this->prompt = $prompt;

                return 'Guides de préparation';
            }

            public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array
            {
                return null;
            }

            public function supportsVision(): bool
            {
                return false;
            }
        };
        $ai = $this->createStub(AiProviderManager::class);
        $ai->method('resolveTextProvider')->willReturn($provider);
        $this->app->instance(AiProviderManager::class, $ai);

        $out = $this->app->make(Translator::class)->translateText('Brewing Guides', 'fr', 'category name');

        $this->assertSame('Guides de préparation', $out);
        $this->assertStringContainsString('Northbeam Coffee', $provider->prompt);
        $this->assertStringContainsString('Small-batch coffee roasted in Bristol', $provider->prompt);
    }
}
