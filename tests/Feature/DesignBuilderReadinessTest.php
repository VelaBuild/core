<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Services\AiSettingsService;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What the build page says it will build with.
 *
 * It used to say "A key is configured", which is equally true of a site whose
 * builds come out well and one whose builds come out thin — and the model is
 * the largest single difference between them. Reported without calling
 * anything: the page renders on every visit, and the build settles this by
 * asking each provider in turn, which costs a request.
 */
class DesignBuilderReadinessTest extends PackageTestCase
{
    private function setKey(string $provider, string $value): void
    {
        app(AiSettingsService::class)->set($provider . '_api_key', $value);
    }

    public function test_the_provider_and_model_a_build_would_use_are_named(): void
    {
        $this->setKey('anthropic', 'sk-ant-test');
        config([
            'vela.ai.chat.design_models' => ['anthropic' => 'claude-opus-5'],
            'vela.ai.chat.anthropic_model' => 'claude-sonnet-5',
        ]);

        $planned = app(DesignBuilderService::class)->plannedProvider();

        $this->assertSame('anthropic', $planned['provider']);
        // The design model, not the site's everyday one.
        $this->assertSame('claude-opus-5', $planned['model']);
        $this->assertSame([], $planned['fallbacks']);
    }

    public function test_with_no_design_model_named_the_sites_own_model_is_reported(): void
    {
        $this->setKey('anthropic', 'sk-ant-test');
        config([
            'vela.ai.chat.design_models' => ['anthropic' => ''],
            'vela.ai.chat.anthropic_model' => 'claude-sonnet-5',
        ]);

        $this->assertSame('claude-sonnet-5', app(DesignBuilderService::class)->plannedProvider()['model']);
    }

    public function test_the_providers_a_build_would_fall_back_to_are_named_too(): void
    {
        $this->setKey('anthropic', 'sk-ant-test');
        $this->setKey('gemini', 'gem-test');
        config(['vela.ai.settings.chat_provider' => 'anthropic']);
        app(AiSettingsService::class)->set('chat_provider', 'anthropic');

        $planned = app(DesignBuilderService::class)->plannedProvider();

        $this->assertSame('anthropic', $planned['provider']);
        $this->assertContains('gemini', $planned['fallbacks']);
    }

    public function test_a_model_measured_as_not_up_to_this_is_flagged(): void
    {
        $service = app(DesignBuilderService::class);

        $this->assertNotNull($service->modelConcern('gpt-4o'));
        // A dated snapshot is the same model.
        $this->assertNotNull($service->modelConcern('gpt-4o-2024-08-06'));
        $this->assertNotNull($service->modelConcern('gpt-4'));
        $this->assertNotNull($service->modelConcern('gpt-4-turbo'));
        $this->assertNotNull($service->modelConcern('gpt-3.5-turbo'));
        $this->assertNotNull($service->modelConcern('claude-3-opus'));
    }

    /**
     * The list is of models measured as too weak, never of models believed to
     * be good — so anything released after it was written passes. A version
     * number that merely looks similar to a listed one is not the listed one.
     */
    public function test_nothing_newer_is_caught_by_the_list(): void
    {
        $service = app(DesignBuilderService::class);

        foreach ([
            'gpt-5.2', 'gpt-5', 'gpt-4.1', 'gpt-4.1-mini', 'o3',
            'claude-opus-5', 'claude-sonnet-5', 'claude-sonnet-4-6',
            'gemini-2.5-flash', 'some-model-nobody-has-heard-of', '',
        ] as $model) {
            $this->assertNull($service->modelConcern($model), $model);
        }
    }

    public function test_the_page_says_so_and_does_not_show_a_tick(): void
    {
        $this->setKey('openai', 'sk-openai-test');
        config(['vela.ai.chat.openai_model' => 'gpt-4o', 'vela.ai.chat.design_models' => []]);
        app(AiSettingsService::class)->set('chat_provider', 'openai');
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);

        $response = $this->get(route('vela.admin.settings.design-builder.index'));

        $response->assertOk();
        $response->assertSee('gpt-4o');
        $response->assertSee('an old model, and builds come out thin', false);
        $response->assertSee('Change it under Settings → AI.', false);
        // The warning triangle, not the tick.
        $response->assertSee('fa-exclamation-circle', false);
    }

    public function test_with_no_key_at_all_nothing_is_claimed(): void
    {
        $this->assertNull(app(DesignBuilderService::class)->plannedProvider());
    }

    public function test_the_page_says_which_model_it_will_build_with(): void
    {
        $this->setKey('anthropic', 'sk-ant-test');
        config(['vela.ai.chat.design_models' => ['anthropic' => 'claude-opus-5']]);
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);

        $response = $this->get(route('vela.admin.settings.design-builder.index'));

        $response->assertOk();
        $response->assertSee('claude-opus-5');
        $response->assertDontSee('A key is configured.');
    }
}
