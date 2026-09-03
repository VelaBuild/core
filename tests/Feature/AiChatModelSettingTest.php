<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\AiSettingsService;
use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Services\GeminiTextService;
use VelaBuild\Core\Services\OpenAiTextService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Choosing the model each provider thinks with.
 *
 * Settings → AI let you pick a provider and, for pictures, a model — never the
 * model that does the work, which is the larger of the two decisions. It was
 * config-only, so the only way to change it was to edit .env, which the person
 * the admin is built for cannot do.
 */
class AiChatModelSettingTest extends PackageTestCase
{
    private function signInAsAdmin(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    public function test_a_provider_uses_the_model_the_site_chose(): void
    {
        config(['vela.ai.chat.openai_model' => 'gpt-4o']);
        $this->assertSame('gpt-4o', (new OpenAiTextService)->model());

        app(AiSettingsService::class)->set('openai_model', 'gpt-5.2');

        $this->assertSame('gpt-5.2', (new OpenAiTextService)->model());
    }

    public function test_clearing_the_choice_puts_it_back_on_what_vela_ships_with(): void
    {
        config(['vela.ai.chat.anthropic_model' => 'claude-sonnet-5']);
        $settings = app(AiSettingsService::class);

        $settings->set('anthropic_model', 'claude-opus-5');
        $this->assertSame('claude-opus-5', (new ClaudeTextService)->model());

        $settings->set('anthropic_model', null);
        $this->assertSame('claude-sonnet-5', (new ClaudeTextService)->model());
    }

    /**
     * `AI_CHAT_OPENAI_MODEL=` with nothing after it is a line people leave
     * behind. env()'s own default is unreachable past it, so the provider was
     * being handed an empty model and sent `"model": ""`, failing on its first
     * call with an error about the model rather than about the empty line.
     */
    public function test_an_empty_setting_falls_through_to_something_real(): void
    {
        config(['vela.ai.chat.openai_model' => '']);

        $this->assertSame(
            AiSettingsService::FALLBACK_MODELS['openai'],
            (new OpenAiTextService)->model()
        );

        $status = app(AiSettingsService::class)->getStatus();
        $this->assertSame(
            AiSettingsService::FALLBACK_MODELS['openai'],
            $status['providers']['openai']['model_default']
        );
    }

    public function test_geminis_endpoint_follows_the_chosen_model_too(): void
    {
        app(AiSettingsService::class)->set('gemini_model', 'gemini-9-pro');
        $provider = new GeminiTextService;

        $this->assertSame('gemini-9-pro', $provider->model());

        $endpoint = new \ReflectionMethod($provider, 'endpoint');
        $endpoint->setAccessible(true);
        $this->assertStringContainsString('gemini-9-pro:generateContent', $endpoint->invoke($provider));
    }

    public function test_the_settings_page_shows_the_model_and_saves_a_new_one(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('openai_model', 'gpt-4o');

        $this->get(route('vela.admin.ai-settings.index'))
            ->assertOk()
            ->assertSee('openai_model', false)
            ->assertSee('gpt-4o')
            // The deny-list verdict, from the same place the build page reads it.
            ->assertSee('an old model, and builds come out thin', false);

        $this->post(route('vela.admin.ai-settings.update'), ['openai_model' => 'gpt-5.2'])
            ->assertRedirect();

        $this->assertSame('gpt-5.2', app(AiSettingsService::class)->get('openai_model'));
    }

    /**
     * A model set before this version — or one the menu has never heard of —
     * has to appear in the menu as the current choice, or opening the page and
     * pressing Save would quietly move the site onto something else.
     */
    public function test_a_model_the_menu_does_not_know_is_added_to_it(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('openai_model', 'gpt-7-turbo-tuesday');

        $this->get(route('vela.admin.ai-settings.index'))
            ->assertOk()
            ->assertSee('value="gpt-7-turbo-tuesday" selected', false);
    }

    public function test_other_lets_a_model_the_menu_lacks_be_typed(): void
    {
        $this->signInAsAdmin();

        $this->post(route('vela.admin.ai-settings.update'), [
            'openai_model' => '__other',
            'openai_model_other' => 'gpt-9-mini',
        ])->assertRedirect();

        $this->assertSame('gpt-9-mini', app(AiSettingsService::class)->get('openai_model'));
    }

    public function test_other_with_nothing_typed_clears_the_choice(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('openai_model', 'gpt-4o');

        $this->post(route('vela.admin.ai-settings.update'), [
            'openai_model' => '__other',
            'openai_model_other' => '   ',
        ])->assertRedirect();

        // Never the marker itself, which would be sent to OpenAI as a model id.
        $this->assertSame('', (string) app(AiSettingsService::class)->get('openai_model', ''));
    }

    /**
     * Anthropic refuses EVERY call from an identity-linked key that arrives
     * without a workspace — including the one that would report the key as
     * working — so the provider reads as dead rather than as needing one more
     * field. A plain key must not be sent one.
     */
    public function test_the_anthropic_workspace_is_sent_only_when_the_site_has_one(): void
    {
        app(AiSettingsService::class)->set('anthropic_api_key', 'sk-ant-test');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'OK']]])]);

        (new ClaudeTextService)->generateText('hi', 8);
        Http::assertSent(fn ($request) => !$request->hasHeader('anthropic-workspace-id'));

        app(AiSettingsService::class)->set('anthropic_workspace_id', 'wrkspc_014RnywLJADqkSck1Pknvu9r');

        (new ClaudeTextService)->generateText('hi', 8);
        Http::assertSent(fn ($request) => $request->header('anthropic-workspace-id')
            === ['wrkspc_014RnywLJADqkSck1Pknvu9r']);
    }

    public function test_a_workspace_id_that_is_not_one_is_refused(): void
    {
        $this->signInAsAdmin();

        $this->post(route('vela.admin.ai-settings.update'), ['anthropic_workspace_id' => 'not a workspace id'])
            ->assertSessionHasErrors('anthropic_workspace_id');

        $this->assertSame('', (string) app(AiSettingsService::class)->get('anthropic_workspace_id', ''));
    }

    /**
     * A model id reaches the provider inside a URL (Gemini) or a request body,
     * so it is not a field to pass through unexamined.
     */
    public function test_something_that_is_not_a_model_id_is_refused(): void
    {
        $this->signInAsAdmin();
        app(AiSettingsService::class)->set('openai_model', 'gpt-4o');

        $this->post(route('vela.admin.ai-settings.update'), [
            'openai_model' => '__other',
            'openai_model_other' => 'gpt-5 <script>x</script>',
        ])
            ->assertSessionHasErrors('openai_model');

        $this->assertSame('gpt-4o', app(AiSettingsService::class)->get('openai_model'));
    }
}
