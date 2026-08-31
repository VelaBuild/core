<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A design build wrote its six sections, then a round of corrections replaced
 * the theme's 223-line layout with thirteen lines — a header, a nav,
 * @yield('content'), a footer, and no <head> at all. Every guard passed. The
 * site answered 200 on every page and came back in the browser's default serif
 * with blue underlined links, the whole design gone, and nothing reported it.
 *
 * A layout that renders is not a layout that works, and the checks that matter
 * are the ones nothing downstream can make.
 */
class ThemeLayoutGuardTest extends PackageTestCase
{
    use RefreshDatabase;

    private function skeleton(): string
    {
        return app(ThemeSkeleton::class)->layout();
    }

    private function guard(string $contents, string $existing = ''): ?string
    {
        try {
            app(ThemeAuthor::class)->guardView('layout.blade.php', $contents, $existing);
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function test_a_layout_with_no_head_is_refused(): void
    {
        $bare = "<header class='site-header'><div class='logo'>Zercurity</div></header>\n"
            . "<main>@yield('content')</main>\n"
            . '<footer>@yield("footer")</footer>';

        $error = $this->guard($bare, $this->skeleton());

        $this->assertNotNull($error, 'A layout with no <head> was accepted.');
        $this->assertStringContainsString('<head>', $error);
    }

    public function test_a_rewrite_that_drops_the_block_stylesheet_is_refused(): void
    {
        // A whole document, correct in every other way, that has quietly left
        // out the one directive carrying the block styling.
        $without = str_replace("@velaAssets('public')", '', $this->skeleton());

        $error = $this->guard($without, $this->skeleton());

        $this->assertNotNull($error, 'A rewrite dropping @velaAssets was accepted.');
        $this->assertStringContainsString('velaAssets', $error);
    }

    public function test_a_rewrite_that_drops_the_custom_css_partial_is_refused(): void
    {
        // Without it every update_custom_css call and every written section's
        // stylesheet is a silent no-op — bug #30, and the reason the sections
        // of a built page came out unstyled.
        $without = str_replace("@include('vela::templates._partials.custom-css')", '', $this->skeleton());

        $error = $this->guard($without, $this->skeleton());

        $this->assertNotNull($error, 'A rewrite dropping the custom-css partial was accepted.');
        $this->assertStringContainsString('custom-css', $error);
    }

    public function test_the_skeleton_itself_passes(): void
    {
        // The guard has to let through the layout the builder starts from,
        // both as a first write and as a rewrite of itself.
        $this->assertNull($this->guard($this->skeleton()));
        $this->assertNull($this->guard($this->skeleton(), $this->skeleton()));
    }

    /**
     * A page with no rows at all — the About, Privacy, Terms and Contact pages
     * an install ships are all empty — used to take a written theme's page view
     * down with "Call to a member function sortBy() on null". The homepage,
     * which has rows, went on looking finished, so a build could hand over a
     * site whose four other pages were 500s and say nothing.
     */
    public function test_the_page_view_survives_a_page_with_no_rows(): void
    {
        $view = app(ThemeSkeleton::class)->page();

        $page = new class {
            public $rows;
            public $slug = 'about';
            public $id = 1;
            public $title = 'About';
            public $meta_title = null;
            public $meta_description = null;
            public $custom_css = null;
            public $custom_js = null;

            public function __construct()
            {
                $this->rows = collect();
            }
        };

        // Run just the @php block the failure was in, which is where a page
        // with no rows first touches anything.
        preg_match('/@php(.*?)@endphp/s', $view, $php);
        $this->assertNotEmpty($php, 'The page view no longer has a @php block.');

        $run = function () use ($php, $page) {
            extract(['page' => $page]);
            eval($php[1]);
            return get_defined_vars();
        };

        // It has to REACH the end without throwing, and decide there is no
        // opening hero — `?? ` would hide the difference between null and
        // never assigned, so the variable is looked for by name.
        $vars = $run();
        $this->assertArrayHasKey('__lead', $vars);
        $this->assertNull($vars['__lead']);
        $this->assertFalse($vars['__opensWithHero']);
    }

    public function test_a_real_change_to_the_layout_is_still_allowed(): void
    {
        $changed = str_replace('--accent: #1a1a1a;', '--accent: #C1440E;', $this->skeleton());
        $changed = str_replace('gap: 28px', 'gap: 40px', $changed);

        $this->assertNull($this->guard($changed, $this->skeleton()));
    }
}
