<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\Tools\SetMenuTool;
use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A design whose header read "About  Osquery  Docs  Login  Create Account" had
 * nowhere to put any of it: the menu slots could not be set from here, and a
 * written theme spelled Home / Articles / Topics into its own markup. Three
 * rounds of QA in a row spent themselves rewriting that layout by hand, which
 * was both the wrong tool and, since the words would then belong to the theme,
 * the wrong place.
 */
class SetMenuToolTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_it_puts_the_designs_navigation_in_the_header(): void
    {
        $result = (new SetMenuTool())->execute([
            'slot' => 'primary',
            'items' => [
                ['label' => 'About', 'type' => 'url', 'url' => '/about'],
                ['label' => 'Osquery', 'type' => 'url', 'url' => '/osquery'],
                ['label' => 'Docs', 'type' => 'url', 'url' => '/docs'],
            ],
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $items = Menu::where('slot', 'primary')->first()->items()->orderBy('order_column')->get();

        $this->assertSame(['About', 'Osquery', 'Docs'], $items->pluck('label')->all());
    }

    public function test_setting_a_slot_again_replaces_it_rather_than_appending(): void
    {
        $tool = new SetMenuTool();

        $tool->execute(['slot' => 'primary', 'items' => [
            ['label' => 'About', 'type' => 'url', 'url' => '/about'],
        ]]);
        $tool->execute(['slot' => 'primary', 'items' => [
            ['label' => 'Docs', 'type' => 'url', 'url' => '/docs'],
        ]]);

        $items = Menu::where('slot', 'primary')->first()->items;

        $this->assertCount(1, $items);
        $this->assertSame('Docs', $items->first()->label);
    }

    public function test_a_link_to_a_page_that_does_not_exist_is_refused(): void
    {
        $result = (new SetMenuTool())->execute([
            'slot' => 'primary',
            'items' => [['label' => 'Docs', 'type' => 'page', 'page_slug' => 'docs']],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('create_page', $result['error']);
        $this->assertNull(Menu::where('slot', 'primary')->first());
    }

    public function test_a_link_with_nowhere_to_go_is_refused(): void
    {
        $result = (new SetMenuTool())->execute([
            'slot' => 'primary',
            'items' => [['label' => 'Login', 'type' => 'url', 'url' => '#']],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('no address', $result['error']);
    }

    public function test_a_link_to_a_place_on_the_page_is_refused(): void
    {
        // What a build actually put in a live header: a design is one long
        // page, so its nav links to itself. On a site these scroll nowhere,
        // and the real About and Services pages sit there unlinked. The bare
        // "#" was already refused; these walked past that check.
        foreach (['#about', '#services', '#blog', '#contact'] as $url) {
            $result = (new SetMenuTool())->execute([
                'slot' => 'primary',
                'items' => [['label' => 'About Us', 'type' => 'url', 'url' => $url]],
            ]);

            $this->assertArrayHasKey('error', $result, $url . ' should be refused');
            $this->assertStringContainsString('scrolls', $result['error']);
        }

        $this->assertSame(0, Menu::count(), 'and nothing is written to the site\'s navigation');
    }

    public function test_a_link_to_the_address_a_design_uses_for_nothing_is_refused(): void
    {
        $result = (new SetMenuTool())->execute([
            'slot' => 'footer_quick_links',
            'items' => [['label' => 'Privacy Policy', 'type' => 'url', 'url' => 'https://example.com/privacy-policy']],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('something goes here', $result['error']);
    }

    public function test_a_real_path_and_a_real_address_are_still_accepted(): void
    {
        $result = (new SetMenuTool())->execute([
            'slot' => 'primary',
            'items' => [
                ['label' => 'Pricing', 'type' => 'url', 'url' => '/pricing'],
                ['label' => 'Docs', 'type' => 'url', 'url' => 'https://docs.zercurity.com'],
                // A fragment hanging off a real path still says which page.
                ['label' => 'Team', 'type' => 'url', 'url' => '/about#team'],
            ],
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    public function test_a_link_to_a_real_page_resolves_to_that_page(): void
    {
        Page::create([
            'title' => 'Docs',
            'slug' => 'docs',
            'status' => 'published',
            'locale' => config('vela.primary_language', 'en'),
        ]);

        $result = (new SetMenuTool())->execute([
            'slot' => 'primary',
            'items' => [['label' => 'Docs', 'type' => 'page', 'page_slug' => 'docs']],
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('page', Menu::where('slot', 'primary')->first()->items->first()->type);
    }

    public function test_an_unknown_slot_says_which_ones_a_theme_renders(): void
    {
        $result = (new SetMenuTool())->execute(['slot' => 'sidebar', 'items' => []]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('header_actions', $result['error']);
    }

    /**
     * The tool is only half of it: a theme that spells its navigation out by
     * hand cannot show what the tool sets.
     */
    public function test_a_written_theme_renders_the_sites_own_menus(): void
    {
        $layout = app(ThemeSkeleton::class)->layout();

        $this->assertStringContainsString("@velaMenu('primary')", $layout);
        $this->assertStringContainsString("@velaMenu('header_actions')", $layout);
        $this->assertStringContainsString("@velaMenu('footer_quick_links')", $layout);
        // The three it used to hard-code, which no menu could replace.
        $this->assertStringNotContainsString("vela.public.posts.index') }}\">{{ __('vela::public.articles", $layout);
    }
}
