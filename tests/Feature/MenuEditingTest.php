<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\MenuRenderer;
use VelaBuild\Core\Services\ThemeMenus;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Opening the menu editor must not change the site.
 *
 * "Reset to defaults" then "Edit" left the menu empty with "add new pages
 * automatically" switched on by itself: the screen used to firstOrCreate the
 * menu, so a GET stored an empty one — and an empty stored menu is a
 * deliberate customisation as far as the renderer is concerned, so the header
 * emptied on the public site without anybody pressing Save.
 */
class MenuEditingTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    public function test_opening_the_editor_stores_nothing(): void
    {
        $this->assertNull(Menu::where('slot', 'primary')->first());

        $this->get(route('vela.admin.settings.menus.edit', 'primary'))->assertOk();

        $this->assertNull(Menu::where('slot', 'primary')->first(), 'looking at the menu created one');
    }

    public function test_a_slot_with_nothing_stored_opens_on_what_visitors_see(): void
    {
        Page::create([
            'title' => 'Our story', 'slug' => 'our-story', 'status' => 'published',
            'locale' => config('vela.primary_language', 'en'),
        ]);

        $shown = app(MenuRenderer::class)->items('primary')->pluck('label')->filter()->values();

        $response = $this->get(route('vela.admin.settings.menus.edit', 'primary'))->assertOk();

        foreach ($shown as $label) {
            $response->assertSee($label, false);
        }
    }

    public function test_the_site_keeps_its_defaults_until_the_menu_is_saved(): void
    {
        Page::create([
            'title' => 'Our story', 'slug' => 'our-story', 'status' => 'published',
            'locale' => config('vela.primary_language', 'en'),
        ]);

        $before = app(MenuRenderer::class)->items('primary')->pluck('label')->all();

        $this->get(route('vela.admin.settings.menus.edit', 'primary'))->assertOk();

        $this->assertSame($before, app(MenuRenderer::class)->items('primary')->pluck('label')->all());
    }

    public function test_saving_is_what_makes_the_menu_the_sites_own(): void
    {
        $this->put(route('vela.admin.settings.menus.update', 'primary'), [
            'label' => 'Primary',
            'items' => [
                ['type' => 'url', 'label' => 'Shop', 'url' => '/shop', 'order_column' => 0],
            ],
        ])->assertRedirect();

        $this->assertSame(['Shop'], app(MenuRenderer::class)->items('primary')->pluck('label')->all());
    }

    /**
     * The route only accepts a plain slot, so a theme's own menu has to be
     * reachable through the slot's own name — the screen edits whatever the
     * current theme shows.
     */
    public function test_a_themes_own_menu_is_edited_through_the_plain_slot(): void
    {
        $theme = config('vela.template.active');
        $scoped = Menu::create(['slot' => ThemeMenus::slot($theme, 'primary'), 'label' => 'Primary']);
        $scoped->items()->create(['label' => 'About', 'type' => 'url', 'url' => '/about', 'order_column' => 0]);

        $this->get(route('vela.admin.settings.menus.edit', 'primary'))
            ->assertOk()
            ->assertSee('About', false);

        $this->put(route('vela.admin.settings.menus.update', 'primary'), [
            'label' => 'Primary',
            'items' => [['type' => 'url', 'label' => 'Docs', 'url' => '/docs', 'order_column' => 0]],
        ])->assertRedirect();

        // Written to the theme's menu, not to a new shared one.
        $this->assertSame(['Docs'], $scoped->items()->pluck('label')->all());
        $this->assertNull(Menu::where('slot', 'primary')->first());
    }
}
