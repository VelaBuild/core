<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A theme built on the skeleton's tokens gets the `--vela-*` contract the
 * blocks paint from, even when it was written without one.
 *
 * Four themes on a real site were written before the skeleton carried the
 * contract. The themes looked right; every block on them that follows the
 * theme — a pricing button, a row's token: colour, a form field — came out in
 * the fallback blue and near-black.
 */
class ThemeContractRepairTest extends PackageTestCase
{
    private function withoutContract(): string
    {
        return preg_replace('/^\s*--vela-[a-z-]+: var\(--[a-z-]+\);\n/m', '', app(ThemeSkeleton::class)->layout());
    }

    public function test_the_missing_declarations_are_added_pointing_at_the_themes_tokens(): void
    {
        $bare = $this->withoutContract();
        $this->assertStringNotContainsString('--vela-primary:', $bare);

        $fixed = app(ThemeAuthor::class)->withContract($bare);

        $this->assertStringContainsString('--vela-primary: var(--accent);', $fixed);
        $this->assertStringContainsString('--vela-band: var(--band);', $fixed);
        $this->assertStringContainsString('--vela-page-width: var(--page-width);', $fixed);
        // Inside the stylesheet, not somewhere a browser would print it.
        $this->assertLessThan(strpos($fixed, '--vela-primary:'), strpos($fixed, '<style'));
        $this->assertLessThan(strpos($fixed, '</style>'), strpos($fixed, '--vela-primary:'));
    }

    public function test_a_layout_that_already_has_them_is_left_as_it_is(): void
    {
        $skeleton = app(ThemeSkeleton::class)->layout();

        $this->assertSame($skeleton, app(ThemeAuthor::class)->withContract($skeleton));
    }

    public function test_one_declared_by_hand_is_kept_and_only_the_rest_are_added(): void
    {
        $bare = str_replace('<style>', "<style>\n:root { --vela-primary: #ff0000; }", $this->withoutContract());

        $fixed = app(ThemeAuthor::class)->withContract($bare);

        $this->assertSame(1, substr_count($fixed, '--vela-primary:'));
        $this->assertStringContainsString('--vela-primary: #ff0000;', $fixed);
        $this->assertStringContainsString('--vela-ink: var(--ink);', $fixed);
    }

    public function test_a_hand_written_layout_with_no_tokens_is_not_given_pointers_to_nothing(): void
    {
        $plain = "<!doctype html><html><head><style>body { color: #333; }</style></head><body>@yield('content')</body></html>";

        $this->assertSame($plain, app(ThemeAuthor::class)->withContract($plain));
    }

    public function test_a_file_tool_rewrite_that_drops_the_contract_is_refused(): void
    {
        $skeleton = app(ThemeSkeleton::class)->layout();

        try {
            app(ThemeAuthor::class)->guardView('layout.blade.php', $this->withoutContract(), $skeleton);
            $this->fail('A rewrite dropping the --vela-* declarations was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--vela-*', $e->getMessage());
        }
    }
}
