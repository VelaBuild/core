<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A token the skeleton offers but never reads is a set_theme_tokens call that
 * reports success and changes nothing — the same silent no-op that made every
 * update_custom_css call disappear before the custom-css partial was added.
 * The stylesheet and the token table are written in different places and it
 * costs nothing to keep asking whether they still agree.
 */
class ThemeSkeletonTokensTest extends PackageTestCase
{
    public function test_every_token_offered_is_read_somewhere_in_the_stylesheet(): void
    {
        $layout = app(ThemeSkeleton::class)->layout();

        preg_match_all('/var\(--([a-z-]+)\)/', $layout, $used);
        $used = array_unique($used[1]);

        foreach (array_keys(ThemeSkeleton::TOKENS) as $token) {
            $this->assertContains(
                $token,
                $used,
                "The theme offers --{$token} but nothing in the stylesheet reads it, so setting it would change nothing."
            );
        }
    }

    public function test_nothing_in_the_stylesheet_reads_a_token_that_is_never_declared(): void
    {
        $layout = app(ThemeSkeleton::class)->layout();

        preg_match_all('/--([a-z-]+):/', $layout, $declared);
        preg_match_all('/var\(--([a-z-]+)\)/', $layout, $used);

        $this->assertSame(
            [],
            array_values(array_unique(array_diff($used[1], $declared[1]))),
            'A rule reads a custom property the layout never declares, so it falls back to nothing.'
        );
    }

    /**
     * setTokens rewrites the first `--name:` it finds and stops. Declared
     * twice, the value a design asked for would land on whichever came first
     * and the other would quietly overrule it.
     */
    public function test_each_token_is_declared_exactly_once(): void
    {
        $layout = app(ThemeSkeleton::class)->layout();

        foreach (array_keys(ThemeSkeleton::TOKENS) as $token) {
            $this->assertSame(
                1,
                preg_match_all('/--' . preg_quote($token, '/') . ':/', $layout),
                "--{$token} is declared more than once in the layout."
            );
        }
    }
}
