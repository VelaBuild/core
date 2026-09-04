<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Tests\PackageTestCase;
use VelaBuild\Core\Vela;

/**
 * The third menu slot, and the six that are not menus at all.
 *
 * A design showing "Account" at the right-hand end of its header produced a
 * menu that rendered on the site and appeared nowhere in Settings → Menus.
 * ThemeSkeleton draws `@velaMenu('header_actions')`, DesignPreviewFrame stages
 * it and set_menu writes into it — but nothing ever declared the slot, and the
 * registry is what that screen lists. Meanwhile the builder's own bookkeeping
 * slots were listed there, as orphans to tidy away.
 */
class HeaderActionsSlotTest extends PackageTestCase
{
    private function signInAsAdmin(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    public function test_header_actions_is_a_slot_the_admin_knows_about(): void
    {
        $slots = app(Vela::class)->frontMenus()->forActiveTemplate();

        // All three of them, because all three are rendered by every theme
        // this CMS writes.
        $this->assertArrayHasKey('primary', $slots);
        $this->assertArrayHasKey('footer_quick_links', $slots);
        $this->assertArrayHasKey('header_actions', $slots);
    }

    public function test_the_menus_screen_lists_it_and_hides_the_builder_s_bookkeeping(): void
    {
        $this->signInAsAdmin();

        // What a site looks like after a design has been kept: one real menu
        // and four rows of the frame's own record-keeping.
        foreach ([
            'header_actions',
            'design_preview_primary',
            'design_preview_header_actions',
            'superseded_primary',
            'superseded_header_actions',
        ] as $slot) {
            Menu::create(['slot' => $slot, 'name' => $slot, 'label' => '']);
        }

        $response = $this->get(route('vela.admin.settings.menus.index'));

        $response->assertOk();
        $response->assertSee(route('vela.admin.settings.menus.edit', 'header_actions'), false);
        $response->assertDontSee(route('vela.admin.settings.menus.edit', 'design_preview_primary'), false);
        $response->assertDontSee(route('vela.admin.settings.menus.edit', 'superseded_primary'), false);
    }

    public function test_a_menu_the_build_wrote_is_still_called_something(): void
    {
        $this->signInAsAdmin();

        // set_menu has no reason to invent a label, so the heading read
        //: Edit menu items for the “” slot.
        Menu::create(['slot' => 'header_actions', 'name' => 'header_actions', 'label' => '']);

        $this->get(route('vela.admin.settings.menus.edit', 'header_actions'))
            ->assertOk()
            ->assertSee('Header actions');
    }

    public function test_what_counts_as_the_builders_own_slot(): void
    {
        $this->assertTrue(DesignPreviewFrame::isPrivateSlot('design_preview_primary'));
        $this->assertTrue(DesignPreviewFrame::isPrivateSlot('superseded_footer_quick_links'));

        // And the ordinary ones are not, or the screen would list nothing.
        $this->assertFalse(DesignPreviewFrame::isPrivateSlot('primary'));
        $this->assertFalse(DesignPreviewFrame::isPrivateSlot('header_actions'));
    }
}
