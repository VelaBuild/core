<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use VelaBuild\Core\Services\DesignTokens;
use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Tests\PackageTestCase;
use VelaBuild\Core\Vela;

/**
 * A theme's colours have to be changeable by the person whose site it is.
 *
 * Two ways that failed, both silent, and together they produced one symptom:
 * "my colour settings have disappeared."
 *
 * A theme written by the design builder declared `"options": {}`, and a theme
 * with no options gets no Theme Options panel on Settings → Appearance — not a
 * smaller one, none at all. So the builder would author a site's whole design
 * and leave its owner unable to change a single colour on it.
 *
 * And a theme can be deleted while the site is still set to it. Nothing said
 * so: no theme showed as selected, the options panel was gone for the same
 * reason as above, and the public site looked fine because vela_template_view()
 * quietly falls through to `default`.
 */
class ThemeOptionsReachTheOwnerTest extends PackageTestCase
{
    private string $theme = 'test-generated-theme';

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/templates/' . $this->theme));
        File::deleteDirectory(storage_path('app/vela-theme-replaced/' . $this->theme));

        parent::tearDown();
    }

    private function author(): ThemeAuthor
    {
        return app(ThemeAuthor::class);
    }

    private function manifest(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('views/templates/' . $this->theme . '/template.json')),
            true
        );
    }

    public function test_a_generated_theme_offers_its_palette_as_settings(): void
    {
        $this->theme = $this->author()->scaffold($this->theme, 'Test Generated', 'A theme made in a test.');

        $options = $this->manifest()['options'];

        $this->assertNotEmpty(
            $options,
            'A generated theme declared no options, which takes the whole Theme Options panel '
            . 'off Settings → Appearance and leaves its owner unable to change any colour.'
        );

        foreach (['primary_color', 'background_color', 'text_color', 'surface_color'] as $key) {
            $this->assertArrayHasKey($key, $options);
            $this->assertSame('color', $options[$key]['type']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{3,8}$/i', $options[$key]['default']);
        }
    }

    public function test_the_pickers_open_on_the_colours_the_design_chose(): void
    {
        $this->theme = $this->author()->scaffold($this->theme, 'Test Generated', 'A theme made in a test.');
        $this->author()->setTokens($this->theme, ['accent' => '#c81e4a', 'bg' => '#101014']);

        $options = $this->manifest()['options'];

        // Read back off the theme's own :root, not a generic default — a
        // picker opening on someone else's blue is a picker that lies about
        // what the site currently is.
        $this->assertSame('#c81e4a', $options['primary_color']['default']);
        $this->assertSame('#101014', $options['background_color']['default']);
    }

    public function test_a_token_pointing_at_another_token_gets_no_picker(): void
    {
        $this->theme = $this->author()->scaffold($this->theme, 'Test Generated', 'A theme made in a test.');

        // `header-bg` ships as `var(--bg)`. A colour input cannot show that,
        // and one that resets it to the literal string would be worse than
        // not offering the option.
        foreach ($this->manifest()['options'] as $key => $option) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{3,8}$/i',
                $option['default'],
                "The {$key} option opens a colour picker on '{$option['default']}', which is not a colour."
            );
        }
    }

    public function test_a_colour_set_in_settings_reaches_the_stylesheet(): void
    {
        // Every option the themes offer has to actually do something. Only
        // three of them were rendered before; a theme offering a picker for
        // its surface colour had that picker change nothing.
        config()->set('vela.theme.surface_color', '#101014');
        config()->set('vela.theme.text_color', '#eeeeee');

        $css = view('vela::templates._partials.theme-colors')->render();

        $this->assertStringContainsString('--vela-surface: #101014', $css);
        $this->assertStringContainsString('--vela-ink: #eeeeee', $css);
    }

    public function test_every_option_a_shipped_theme_offers_is_rendered(): void
    {
        foreach (app(Vela::class)->templates()->all() as $name => $template) {
            foreach (array_keys($template['options'] ?? []) as $key) {
                // Non-colour options (hero image, logo, copyright) are read
                // directly by the layouts, not through this partial.
                if (!str_ends_with($key, '_color')) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $key,
                    DesignTokens::SITE_OPTIONS,
                    "The {$name} theme offers a '{$key}' picker that theme-colors never renders, "
                    . 'so changing it does nothing.'
                );
            }
        }
    }

    /** Sign in and open Settings → Appearance. */
    private function appearance(): \Illuminate\Testing\TestResponse
    {
        $this->signIn();
        \Illuminate\Support\Facades\Gate::define('config_access', fn () => true);
        \Illuminate\Support\Facades\Gate::define('config_edit', fn () => true);

        return $this->get(route('vela.admin.settings.group', 'appearance'));
    }

    public function test_the_colour_fields_are_named_after_the_options_they_set(): void
    {
        \VelaBuild\Core\Models\VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'default']);

        $response = $this->appearance()->assertOk();

        // The view groups the options for display, and Laravel's groupBy
        // reindexes unless asked not to. Every field on this form was posted
        // as `theme_0`, `theme_1`, `theme_2` — stored under those names and
        // read by nothing. Setting Primary Colour appeared to save and
        // changed nothing at all, on every theme Vela has ever shipped.
        $response->assertSee('name="theme_primary_color"', false);
        $response->assertSee('name="theme_background_color"', false);
        $response->assertDontSee('name="theme_0"', false);
    }

    public function test_a_colour_the_theme_never_offered_is_not_stored(): void
    {
        \VelaBuild\Core\Models\VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'default']);

        $this->signIn();
        \Illuminate\Support\Facades\Gate::define('config_access', fn () => true);
        \Illuminate\Support\Facades\Gate::define('config_edit', fn () => true);

        $this->post(route('vela.admin.settings.updateGroup', 'appearance'), [
            'active_template'     => 'default',
            '_theme_options'      => '1',
            'theme_primary_color' => '#c81e4a',
            // What the broken form used to send.
            'theme_0'             => '#000000',
        ]);

        $this->assertSame(
            '#c81e4a',
            \VelaBuild\Core\Models\VelaConfig::where('key', 'theme_primary_color')->value('value')
        );
        $this->assertNull(
            \VelaBuild\Core\Models\VelaConfig::where('key', 'theme_0')->value('value'),
            'A key the theme never declared was stored anyway, under a name nothing reads.'
        );
    }

    public function test_the_settings_screen_says_when_the_chosen_theme_is_gone(): void
    {
        \VelaBuild\Core\Models\VelaConfig::updateOrCreate(
            ['key' => 'active_template'],
            ['value' => 'a-theme-that-was-deleted']
        );

        $this->signIn();
        \Illuminate\Support\Facades\Gate::define('config_access', fn () => true);
        \Illuminate\Support\Facades\Gate::define('config_edit', fn () => true);

        $this->get(route('vela.admin.settings.group', 'appearance'))
            ->assertOk()
            // The name alone would pass on a page that merely echoed it
            // somewhere; the point is that the owner is told what happened.
            ->assertSee('no longer installed', false)
            ->assertSee('a-theme-that-was-deleted');
    }
}
