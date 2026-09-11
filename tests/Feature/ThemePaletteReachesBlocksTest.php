<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A theme and the blocks on its pages were painted from two separate sets of
 * variables that never met.
 *
 * Theme CSS read `var(--vela-primary, ...)`, which is what the Primary Colour
 * field in Settings writes. Block CSS read `--block-accent`, which defaulted to
 * #2563eb in page-blocks.css, and each theme re-declared it as a literal hex —
 * so choosing a brand colour turned the header that colour and left every CTA,
 * pricing tier and icon box on the site the colour the theme was shipped with.
 * `default` and `minimal` never declared it at all, so both shipped stock blue
 * blocks: over navy, and over a black-and-white design.
 *
 * The fix is that `--block-*` are aliases of `--vela-*` and nothing else. These
 * tests hold that shape, because the failure is invisible until someone looks
 * at a page — nothing errors, nothing logs, the site just isn't its own colour.
 */
class ThemePaletteReachesBlocksTest extends PackageTestCase
{
    /** The themes that ship with the package. */
    private const THEMES = ['default', 'modern', 'minimal', 'dark', 'corporate', 'editorial'];

    private function blockStylesheet(): string
    {
        return file_get_contents(__DIR__ . '/../../public/css/page-blocks.css');
    }

    private function layout(string $theme): string
    {
        return file_get_contents(__DIR__ . '/../../resources/views/templates/' . $theme . '/layout.blade.php');
    }

    public function test_every_block_variable_reads_from_the_vela_contract(): void
    {
        preg_match('/^:root \{(.*?)^\}/ms', $this->blockStylesheet(), $m);
        $this->assertNotEmpty($m, 'page-blocks.css no longer opens with a :root block.');

        preg_match_all('/(--block-[a-z-]+)\s*:\s*([^;]+);/', $m[1], $declarations, PREG_SET_ORDER);
        $this->assertGreaterThan(15, count($declarations), 'Far fewer block variables than expected.');

        foreach ($declarations as [, $name, $value]) {
            $this->assertStringContainsString(
                'var(--vela-',
                $value,
                "{$name} is a literal. Every block variable must read a --vela-* token so a theme "
                . 'and a brand colour chosen in Settings both reach it.'
            );
        }
    }

    public function test_every_shipped_theme_names_its_own_accent(): void
    {
        foreach (self::THEMES as $theme) {
            $this->assertMatchesRegularExpression(
                '/--vela-primary\s*:/',
                $this->layout($theme),
                "The {$theme} theme declares no --vela-primary, so its blocks fall back to the "
                . 'stock blue in page-blocks.css instead of the theme palette.'
            );
        }
    }

    public function test_every_shipped_theme_names_its_faces_and_its_measure(): void
    {
        // Typography was the half of the contract no theme used. Five of the
        // six wrote their fonts out as literals — thirty-one of them, spread
        // between each layout's inline CSS and its stylesheet — so a heading
        // inside a text block inherited the body face while the theme's own
        // headings did not, and nothing could restyle a site's type without
        // editing six themes by hand.
        foreach (self::THEMES as $theme) {
            $layout = $this->layout($theme);

            foreach (['--vela-font-body', '--vela-font-display', '--vela-page-width'] as $token) {
                $this->assertMatchesRegularExpression(
                    '/' . preg_quote($token, '/') . '\s*:/',
                    $layout,
                    "The {$theme} theme declares no {$token}."
                );
            }
        }
    }

    public function test_no_shipped_theme_writes_a_font_out_as_a_literal(): void
    {
        foreach (self::THEMES as $theme) {
            $files = [__DIR__ . '/../../resources/views/templates/' . $theme . '/layout.blade.php'];

            foreach ([
                __DIR__ . '/../../public/css/' . $theme . '/style.css',
                __DIR__ . '/../../public/css/premium.css',
            ] as $stylesheet) {
                // premium.css belongs to `default`; the others to the theme
                // whose folder they sit in.
                if (is_file($stylesheet) && ($theme === 'default') === str_ends_with($stylesheet, 'premium.css')) {
                    $files[] = $stylesheet;
                }
            }

            foreach ($files as $file) {
                preg_match_all('/font-family:\s*([^;\n]+)/', (string) file_get_contents($file), $found);

                foreach ($found[1] as $value) {
                    $this->assertStringContainsString(
                        'var(--',
                        $value,
                        basename($file) . " in the {$theme} theme sets a font as a literal: {$value}. "
                        . 'Read it from --vela-font-body / --vela-font-display so the theme states its '
                        . 'faces once and the blocks follow.'
                    );
                }
            }
        }
    }

    public function test_the_example_homepages_name_roles_rather_than_hexes(): void
    {
        // Every theme ships an example homepage, and every one of them wrote
        // its section colours out as hexes — a navy band, a grey middle. Install
        // one, switch theme, and the band stayed navy across a site that had
        // stopped being navy, with nothing on screen explaining why.
        $palette = array_keys(\VelaBuild\Core\Services\DesignTokens::PALETTE);

        foreach (self::THEMES as $theme) {
            $path = __DIR__ . '/../../resources/views/templates/' . $theme . '/home-template.json';
            $rows = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($rows, "The {$theme} theme's home-template.json does not parse.");

            foreach ($rows as $i => $row) {
                foreach (['background_color', 'text_color'] as $field) {
                    $value = $row[$field] ?? '';

                    if ($value === '' || !str_starts_with($value, 'token:')) {
                        continue;
                    }

                    $this->assertContains(
                        substr($value, strlen('token:')),
                        $palette,
                        "Row {$i} of the {$theme} example homepage names '{$value}', which is not in "
                        . 'the palette — it resolves to nothing and the section draws no colour at all.'
                    );
                }
            }
        }
    }

    public function test_no_shipped_theme_sets_a_block_variable_directly(): void
    {
        foreach (self::THEMES as $theme) {
            $this->assertDoesNotMatchRegularExpression(
                '/--block-[a-z-]+\s*:/',
                $this->layout($theme),
                "The {$theme} theme sets a --block-* variable directly. That bypasses the alias, "
                . 'so the value cannot be overridden from Settings.'
            );
        }
    }

    public function test_a_brand_colour_from_settings_carries_its_fill_and_hover(): void
    {
        config()->set('vela.theme.primary_color', '#c81e4a');

        $css = view('vela::templates._partials.theme-colors')->render();

        // Setting the brand alone would leave the theme's own declared hover
        // standing, so a red brand would darken to the theme's purple.
        $this->assertStringContainsString('--vela-primary: #c81e4a', $css);
        $this->assertStringContainsString('--vela-primary-fill: #c81e4a', $css);
        $this->assertStringContainsString('--vela-primary-hover: color-mix(', $css);
    }

    public function test_a_colour_that_is_not_a_colour_never_reaches_the_stylesheet(): void
    {
        // These values are interpolated straight into a <style> on every
        // public page. Blade escapes `<`, so the tag cannot be closed, but
        // nothing stopped a value from closing the rule and opening its own.
        config()->set('vela.theme.primary_color', 'red; } body { display: none; } a {');

        $css = view('vela::templates._partials.theme-colors')->render();

        $this->assertStringNotContainsString('display: none', $css);
        $this->assertStringNotContainsString('--vela-primary:', $css);
    }

    public function test_a_generated_theme_hands_its_tokens_to_the_contract(): void
    {
        $layout = app(ThemeSkeleton::class)->layout();

        // The skeleton's own tokens are unprefixed (--ink, --accent, --line).
        // It used to bridge ten of them onto --block-* by hand and leave the
        // rest at their light-page defaults, which is how a dark generated
        // theme ended up with #d1d5db form borders and a white card ground.
        foreach (['--vela-primary', '--vela-ink', '--vela-line', '--vela-card-bg', '--vela-surface'] as $token) {
            $this->assertStringContainsString(
                $token . ': var(--',
                $layout,
                "ThemeSkeleton does not map {$token}, so generated themes lose that part of the palette."
            );
        }

        $this->assertStringNotContainsString(
            '--block-accent:',
            $layout,
            'ThemeSkeleton still writes --block-* directly instead of the --vela-* contract.'
        );
    }
}
