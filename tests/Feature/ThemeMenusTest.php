<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Services\MenuRenderer;
use VelaBuild\Core\Services\ThemeMenus;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Navigation that belongs to a theme rather than to the whole site.
 *
 * A design arrives with its own words across the header. Written into the
 * site's only menu — which is what a slot was — they stayed there under every
 * other theme, and the site's own menu was gone, because a slot holds one
 * menu and the design had taken it. WordPress puts posts and pages in common
 * and `nav_menu_locations` in the theme; this is that line in Vela's terms.
 */
class ThemeMenusTest extends PackageTestCase
{
    private function menu(string $slot, array $labels): Menu
    {
        $menu = Menu::firstOrCreate(['slot' => $slot], ['label' => $slot]);
        $menu->items()->delete();

        foreach (array_values($labels) as $order => $label) {
            $menu->items()->create(['label' => $label, 'type' => 'url', 'url' => '/x', 'order_column' => $order]);
        }

        return $menu;
    }

    private function labelsOn(string $theme, string $slot = 'primary'): array
    {
        config(['vela.template.active' => $theme]);

        return app(MenuRenderer::class)->items($slot)->pluck('label')->all();
    }

    public function test_a_theme_with_its_own_menu_shows_it_and_the_others_keep_the_shared_one(): void
    {
        $this->menu('primary', ['Home', 'Articles']);
        $this->menu(ThemeMenus::slot('zercurity', 'primary'), ['About', 'Docs']);

        $this->assertSame(['About', 'Docs'], $this->labelsOn('zercurity'));
        $this->assertSame(['Home', 'Articles'], $this->labelsOn('modern'));
    }

    /**
     * The whole point: keeping a design is a change of theme, and switching
     * away from it has to bring the site's own header back.
     */
    public function test_switching_away_from_a_design_brings_the_sites_menu_back(): void
    {
        $this->menu('primary', ['Home', 'Articles']);
        $this->menu(ThemeMenus::slot('zercurity', 'primary'), ['About', 'Osquery', 'Docs']);

        $this->assertSame(['About', 'Osquery', 'Docs'], $this->labelsOn('zercurity'));
        $this->assertSame(['Home', 'Articles'], $this->labelsOn('modern'));
        $this->assertSame(['About', 'Osquery', 'Docs'], $this->labelsOn('zercurity'));
    }

    /** A site that has never used this keeps rendering exactly what it did. */
    public function test_a_site_with_only_a_shared_menu_is_unaffected(): void
    {
        $this->menu('primary', ['Home', 'Articles', 'Contact']);

        $this->assertSame(['Home', 'Articles', 'Contact'], $this->labelsOn('modern'));
        $this->assertSame(['Home', 'Articles', 'Contact'], $this->labelsOn('zercurity'));
    }

    public function test_claiming_copies_what_the_theme_already_showed_rather_than_emptying_the_header(): void
    {
        $this->menu('primary', ['Home', 'Articles']);

        ThemeMenus::claim('modern', 'primary');

        $this->assertSame(['Home', 'Articles'], $this->labelsOn('modern'));
        // Copied, not moved: every other theme is still using the shared one.
        $this->assertSame(['Home', 'Articles'], Menu::where('slot', 'primary')->first()->items()->pluck('label')->all());
    }

    public function test_releasing_puts_the_theme_back_on_the_shared_menu(): void
    {
        $this->menu('primary', ['Home', 'Articles']);
        $this->menu(ThemeMenus::slot('modern', 'primary'), ['Something else']);

        ThemeMenus::release('modern', 'primary');

        $this->assertFalse(ThemeMenus::has('modern', 'primary'));
        $this->assertSame(['Home', 'Articles'], $this->labelsOn('modern'));
    }

    public function test_the_admin_shows_which_menus_belong_to_this_theme_only(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);

        $this->menu('primary', ['Home']);
        $this->menu(ThemeMenus::slot(config('vela.template.active'), 'primary'), ['About', 'Docs']);

        $this->get(route('vela.admin.settings.menus.index'))
            ->assertOk()
            ->assertSee('This theme only')
            // Another theme's menu is not an orphan to be cleaned up here.
            ->assertDontSee(ThemeMenus::SEPARATOR, false);
    }
}
