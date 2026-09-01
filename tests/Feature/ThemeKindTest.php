<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Services\AiChat\Tools\CreateThemeTool;
use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Every build began from the same page — a hero, a row of cards, a band of
 * colour, a three-column footer — because there was one skeleton and it was
 * shaped like a landing page. The clearest sign of it was the hero: three hero
 * tokens and a rule for every part of one were laid out before anyone had
 * looked at the design, so a magazine front page, which has no hero at all,
 * got one anyway.
 */
class ThemeKindTest extends PackageTestCase
{
    use RefreshDatabase;

    private function cleanUp(string $theme): void
    {
        $dir = resource_path('views/templates/' . $theme);

        if (is_dir($dir)) {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }

    public function test_each_kind_starts_from_different_proportions(): void
    {
        $skeleton = app(ThemeSkeleton::class);

        $heroSize = function (string $kind) use ($skeleton): string {
            preg_match('/--hero-size: ([^;]*);/', $skeleton->layout($kind), $m);
            return $m[1];
        };

        // A publication leads with a story, not a banner.
        $this->assertNotSame($heroSize('landing'), $heroSize('editorial'));
        $this->assertNotSame($heroSize('landing'), $heroSize('documentation'));
    }

    /**
     * The kind decides what a theme LOOKS like, never what it may hold. A
     * stylesheet missing the rules for a block would leave that block unstyled
     * the day someone adds one, and the layout guard would have nothing to
     * measure a rewrite against.
     */
    public function test_no_kind_styles_fewer_blocks_than_the_others(): void
    {
        $skeleton = app(ThemeSkeleton::class);
        $author = app(ThemeAuthor::class);

        $classes = [];
        foreach (array_keys(app(\VelaBuild\Core\Vela::class)->blocks()->all()) as $type) {
            foreach ($author->blockClasses($type) as $class) {
                $classes[$class] = $type;
            }
        }

        $covered = function (string $kind) use ($skeleton, $classes): array {
            $layout = $skeleton->layout($kind);

            return array_values(array_filter(
                array_keys($classes),
                fn ($class) => (bool) preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $layout)
            ));
        };

        $baseline = $covered('landing');
        $this->assertNotEmpty($baseline);

        foreach (array_keys(ThemeSkeleton::KINDS) as $kind) {
            $missing = array_diff($baseline, $covered($kind));

            $this->assertSame(
                [],
                array_values($missing),
                "A \"{$kind}\" theme styles fewer block classes than a landing one, so those blocks would render "
                    . 'unstyled on it. The kind decides what a theme looks like, never what it may hold.'
            );
        }
    }

    public function test_every_kind_declares_every_token(): void
    {
        $skeleton = app(ThemeSkeleton::class);

        foreach (array_keys(ThemeSkeleton::KINDS) as $kind) {
            $layout = $skeleton->layout($kind);

            foreach (array_keys(ThemeSkeleton::TOKENS) as $token) {
                $this->assertSame(
                    1,
                    preg_match_all('/--' . preg_quote($token, '/') . ':/', $layout),
                    "A \"{$kind}\" theme does not declare --{$token} exactly once, so set_theme_tokens could not set it."
                );
            }
        }
    }

    /**
     * A value overridden for a kind has to be a token that exists, or it is a
     * line of dead configuration that reads as though it does something.
     */
    public function test_every_kind_override_names_a_real_token(): void
    {
        foreach (ThemeSkeleton::KIND_TOKENS as $kind => $overrides) {
            $this->assertArrayHasKey($kind, ThemeSkeleton::KINDS);

            foreach (array_keys($overrides) as $token) {
                $this->assertArrayHasKey($token, ThemeSkeleton::TOKENS, "--{$token} is not a token.");
            }
        }
    }

    public function test_the_tool_builds_the_kind_it_is_asked_for(): void
    {
        $result = (new CreateThemeTool())->execute(['name' => 'Kindcheck', 'kind' => 'editorial']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('editorial', $result['kind']);

        $theme = $result['theme'];
        $layout = file_get_contents(resource_path('views/templates/' . $theme . '/layout.blade.php'));
        $manifest = json_decode(file_get_contents(resource_path('views/templates/' . $theme . '/template.json')), true);

        $this->assertStringContainsString('site-header--editorial', $layout);
        $this->assertSame('editorial', $manifest['kind']);

        $this->cleanUp($theme);
    }

    public function test_a_build_will_not_write_over_a_theme_that_is_not_its_own(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        file_put_contents(resource_path('views/templates/lighthouse/layout.blade.php'), 'HAND WRITTEN');

        try {
            // A design's name gives the same theme name every run, so the
            // second build of any design arrives at a folder that already
            // exists. It used to write the skeleton straight into it.
            $result = (new CreateThemeTool())->execute(['name' => 'Lighthouse', 'kind' => 'landing']);

            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('already exists', $result['error']);
            $this->assertSame('HAND WRITTEN', file_get_contents(resource_path('views/templates/lighthouse/layout.blade.php')));
        } finally {
            $this->cleanUp('lighthouse');
        }
    }

    public function test_a_build_may_write_over_the_theme_it_staged_last_time(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        file_put_contents(resource_path('views/templates/lighthouse/layout.blade.php'), 'FROM THE LAST RUN');

        app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->setTheme('lighthouse');

        try {
            $result = (new CreateThemeTool())->execute(['name' => 'Lighthouse', 'kind' => 'landing']);

            $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
            $this->assertStringContainsString('<!doctype html>', file_get_contents(resource_path('views/templates/lighthouse/layout.blade.php')));

            // And what it replaced is kept, the way a deleted theme is.
            $this->assertSame(
                'FROM THE LAST RUN',
                file_get_contents(storage_path('app/vela-theme-replaced/lighthouse/layout.blade.php'))
            );
        } finally {
            $this->cleanUp('lighthouse');
            \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/vela-theme-replaced/lighthouse'));
        }
    }

    public function test_the_theme_the_site_is_wearing_is_refused_even_to_its_own_build(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        file_put_contents(resource_path('views/templates/lighthouse/layout.blade.php'), 'WHAT VISITORS SEE');

        // The build's own theme, and since promoted to the live site.
        app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->setTheme('lighthouse');
        config(['vela.template.active' => 'lighthouse']);

        try {
            $result = (new CreateThemeTool())->execute(['name' => 'Lighthouse', 'kind' => 'landing']);

            // Rewriting it would change the site under whoever is reading it,
            // which is the one thing a build on a preview page must not do.
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('reading it', $result['error']);
            $this->assertSame('WHAT VISITORS SEE', file_get_contents(resource_path('views/templates/lighthouse/layout.blade.php')));
        } finally {
            $this->cleanUp('lighthouse');
        }
    }

    public function test_a_kind_that_is_not_one_is_refused_rather_than_guessed(): void
    {
        $result = (new CreateThemeTool())->execute(['name' => 'Kindcheck', 'kind' => 'magazine']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('editorial', $result['error']);
        $this->assertFalse(is_dir(resource_path('views/templates/kindcheck')));
    }
}
