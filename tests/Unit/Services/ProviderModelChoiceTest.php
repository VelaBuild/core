<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Services\GeminiTextService;
use VelaBuild\Core\Services\OpenAiTextService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Which model each provider talks to.
 *
 * Two of the three used to have it written into their code — OpenAI's in
 * seven places, Gemini's inside the endpoint URL — so a site could not move
 * off them and a retirement would have been a code edit. And the one thing
 * every site's design build depends on was invisible: whichever model the
 * first provider that answered happened to be pinned to.
 */
class ProviderModelChoiceTest extends PackageTestCase
{
    public function test_each_provider_reads_its_model_from_config(): void
    {
        config([
            'vela.ai.chat.anthropic_model' => 'claude-opus-5',
            'vela.ai.chat.openai_model' => 'gpt-4.1',
            'vela.ai.chat.gemini_model' => 'gemini-2.5-pro',
        ]);

        $this->assertSame('claude-opus-5', (new ClaudeTextService)->model());
        $this->assertSame('gpt-4.1', (new OpenAiTextService)->model());
        $this->assertSame('gemini-2.5-pro', (new GeminiTextService)->model());
    }

    public function test_a_caller_can_put_one_job_on_a_better_model(): void
    {
        config(['vela.ai.chat.anthropic_model' => 'claude-sonnet-5']);

        $provider = new ClaudeTextService;
        $provider->useModel('claude-opus-5');

        $this->assertSame('claude-opus-5', $provider->model());

        // An empty value means "leave it where it is", which is what an
        // unset design model for this provider sends.
        $provider->useModel('  ');
        $this->assertSame('claude-opus-5', $provider->model());
    }

    public function test_geminis_endpoint_follows_the_model_it_is_set_to(): void
    {
        config(['vela.ai.chat.gemini_model' => 'gemini-2.5-pro']);

        $provider = new GeminiTextService;
        $method = new \ReflectionMethod($provider, 'endpoint');
        $method->setAccessible(true);

        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent',
            $method->invoke($provider)
        );
    }

    /**
     * OpenAI renamed the output cap for its reasoning models, and refuses the
     * old name outright — so a site that moved to a current model got no
     * answer at all, on the very first call.
     */
    public function test_the_output_cap_is_called_what_each_model_family_calls_it(): void
    {
        $provider = new OpenAiTextService;
        $key = new \ReflectionMethod($provider, 'tokenLimitKey');
        $key->setAccessible(true);

        foreach (['gpt-4o', 'gpt-4.1', 'gpt-4-turbo', 'gpt-3.5-turbo'] as $model) {
            $provider->useModel($model);
            $this->assertSame('max_tokens', $key->invoke($provider), $model);
        }

        foreach (['gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-5-pro', 'o1', 'o3-mini', 'gpt-10'] as $model) {
            $provider->useModel($model);
            $this->assertSame('max_completion_tokens', $key->invoke($provider), $model);
        }
    }

    public function test_the_context_window_is_not_capped_at_a_fifth_of_the_real_one(): void
    {
        // Too low does not fail — it quietly shrinks max_tokens as the
        // conversation grows, so a long build's last answers are truncated.
        $this->assertGreaterThanOrEqual(1000000, (int) config('vela.ai.chat.context_limit'));
    }
}
