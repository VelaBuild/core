<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiChat\Tools\SetMenuTool;
use VelaBuild\Core\Services\AiChat\Tools\UseThemeForPreviewTool;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Services\MenuRenderer;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A build goes onto a page of its own so that nobody trying a design out has
 * their site changed underneath them — and the frame did not keep that promise.
 * The build switched the whole site's theme at its third step, and once menus
 * could be set at all, the words in the header of every page changed with it.
 * Someone who pressed Build to see what a design might look like found their
 * live site already wearing it.
 */
class DesignPreviewFrameTest extends PackageTestCase
{
    use RefreshDatabase;

    private function siteMenu(): void
    {
        (new SetMenuTool())->execute([
            'slot' => 'primary',
            'items' => [['label' => 'Our shop', 'type' => 'url', 'url' => '/shop']],
        ]);
    }

    private function buildMenu(): void
    {
        (new SetMenuTool())->execute([
            'slot' => 'primary',
            'scope' => 'design_preview',
            'items' => [
                ['label' => 'About', 'type' => 'url', 'url' => '/about'],
                ['label' => 'Docs', 'type' => 'url', 'url' => '/docs'],
            ],
        ]);
    }

    public function test_a_staged_menu_leaves_the_sites_own_alone(): void
    {
        $this->siteMenu();
        $this->buildMenu();

        $renderer = app(MenuRenderer::class);

        // Everywhere on the site: the navigation its owner has.
        $this->assertSame(['Our shop'], $renderer->items('primary')->pluck('label')->all());

        // On the page the design is being looked at: the design's.
        app(DesignPreviewFrame::class)->activate();
        $this->assertSame(['About', 'Docs'], $renderer->items('primary')->pluck('label')->all());
    }

    public function test_a_staged_theme_is_not_the_sites_theme(): void
    {
        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'modern']);

        (new UseThemeForPreviewTool())->execute(['theme' => 'default']);

        $this->assertSame('modern', VelaConfig::where('key', 'active_template')->value('value'));
        $this->assertSame('default', app(DesignPreviewFrame::class)->theme());
    }

    public function test_a_theme_that_does_not_exist_is_refused(): void
    {
        $result = (new UseThemeForPreviewTool())->execute(['theme' => 'nothing-by-that-name']);

        $this->assertArrayHasKey('error', $result);
        $this->assertNull(app(DesignPreviewFrame::class)->theme());
    }

    public function test_keeping_the_design_moves_the_frame_onto_the_site(): void
    {
        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'modern']);
        $this->siteMenu();
        $this->buildMenu();
        (new UseThemeForPreviewTool())->execute(['theme' => 'default']);

        app(DesignPreviewFrame::class)->promote();

        $this->assertSame('default', VelaConfig::where('key', 'active_template')->value('value'));
        $this->assertSame(
            ['About', 'Docs'],
            Menu::where('slot', 'primary')->first()->items()->orderBy('order_column')->pluck('label')->all()
        );
    }

    public function test_what_the_design_replaced_is_kept(): void
    {
        $this->siteMenu();
        $this->buildMenu();

        app(DesignPreviewFrame::class)->promote();

        // Changing your mind about a design should not cost you the navigation
        // you wrote before it.
        $this->assertSame(
            ['Our shop'],
            Menu::where('slot', 'superseded_primary')->first()->items()->pluck('label')->all()
        );
    }

    public function test_the_build_is_not_given_the_tool_that_dresses_the_whole_site(): void
    {
        $all = app(\VelaBuild\Core\Services\AiChat\ChatToolRegistry::class)->all();
        $building = array_column(app(DesignBuilderService::class)->toolsForBuilding($all), 'name');

        $this->assertNotContains('switch_template', $building);
        // And it still has the way through.
        $this->assertContains('use_theme_for_preview', $building);
        $this->assertContains('set_menu', $building);
    }
}
