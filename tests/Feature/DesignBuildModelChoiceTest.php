<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Services\AiSettingsService;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Choosing which AI runs a build, beside the button that starts one.
 *
 * A build needs a model that can read a picture AND call a tool, and those are
 * not the same set as the models a site chats with: OpenAI's gpt-5.6 family
 * reads a design better than anything on the list and cannot call a tool at
 * all, so a build on it dies at its first step. Hence a closed menu here,
 * where Settings → AI can be typed into.
 */
class DesignBuildModelChoiceTest extends PackageTestCase
{
    private function signInAsAdmin(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    public function test_only_providers_with_a_key_are_offered(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('gemini_api_key', 'gem-test');

        $response = $this->get(route('vela.admin.settings.design-builder.index'));

        $response->assertOk();
        $response->assertSee('gemini-3.8-flash');
        $response->assertDontSee('claude-opus-5');
    }

    public function test_the_choice_is_kept_when_the_build_is_started(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('gemini_api_key', 'gem-test');

        $this->post(route('vela.admin.settings.design-builder.start'), [
            'design_provider' => 'gemini',
            'design_model' => 'gemini-3.8-flash',
            'max_loops' => 1,
        ]);

        $settings = app(AiSettingsService::class);
        $this->assertSame('gemini', $settings->get('design_provider'));
        $this->assertSame('gemini-3.8-flash', $settings->get('design_model'));
    }

    public function test_a_model_that_cannot_run_a_build_is_refused(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('openai_api_key', 'sk-test');

        // Reads a design beautifully; cannot call a tool on the endpoint Vela
        // uses, so a build on it would die at its first step.
        $this->post(route('vela.admin.settings.design-builder.start'), [
            'design_provider' => 'openai',
            'design_model' => 'gpt-5.6-sol',
            'max_loops' => 1,
        ])->assertSessionHasErrors('build');

        $this->assertSame('', (string) app(AiSettingsService::class)->get('design_model', ''));
    }

    public function test_the_build_uses_the_model_chosen_for_its_provider_only(): void
    {
        $settings = app(AiSettingsService::class);
        $settings->set('design_provider', 'gemini');
        $settings->set('design_model', 'gemini-3.8-flash');

        $service = app(DesignBuilderService::class);
        $method = new \ReflectionMethod($service, 'designModelFor');
        $method->setAccessible(true);

        $this->assertSame('gemini-3.8-flash', $method->invoke($service, 'gemini'));
        // Never carried across: a model id belongs to one provider. Asked
        // about another one, the answer is what Vela ships for THAT provider
        // (or nothing, where it ships nothing) — never the id chosen here.
        $openai = $method->invoke($service, 'openai');
        $this->assertNotSame('gemini-3.8-flash', $openai);
        $this->assertSame(trim((string) config('vela.ai.chat.design_models.openai')), $openai);
    }

    public function test_clearing_the_choice_puts_the_build_back_on_the_sites_own_ai(): void
    {
        $this->signInAsAdmin();
        $settings = app(AiSettingsService::class);
        $settings->set('design_provider', 'gemini');
        $settings->set('design_model', 'gemini-3.8-flash');

        $this->post(route('vela.admin.settings.design-builder.start'), [
            'design_provider' => '',
            'max_loops' => 1,
        ]);

        $this->assertSame('', (string) $settings->get('design_provider', ''));
        $this->assertSame('', app(DesignBuilderService::class)->chosenProvider());
    }
}
