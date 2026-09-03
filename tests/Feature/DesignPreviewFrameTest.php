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
        // A design's navigation lives in the theme the build wrote, so there
        // has to be one before it can be set.
        if (!app(DesignPreviewFrame::class)->theme()) {
            app(DesignPreviewFrame::class)->setTheme('zercurity');
        }

        (new SetMenuTool())->execute([
            'slot' => 'primary',
            'scope' => 'design_preview',
            'items' => [
                ['label' => 'About', 'type' => 'url', 'url' => '/about'],
                ['label' => 'Docs', 'type' => 'url', 'url' => '/docs'],
            ],
        ]);
    }

    public function test_a_designs_menu_leaves_the_sites_own_alone(): void
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

    public function test_a_menu_with_no_theme_to_live_in_is_refused_rather_than_written_over_the_sites(): void
    {
        $this->siteMenu();

        $result = (new SetMenuTool())->execute([
            'slot' => 'primary',
            'scope' => 'design_preview',
            'items' => [['label' => 'About', 'type' => 'url', 'url' => '/about']],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(['Our shop'], app(MenuRenderer::class)->items('primary')->pluck('label')->all());
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
        // The order a build works in: a theme first, then the navigation that
        // belongs to it.
        (new UseThemeForPreviewTool())->execute(['theme' => 'default']);
        $this->buildMenu();

        app(DesignPreviewFrame::class)->promote();

        $this->assertSame('default', VelaConfig::where('key', 'active_template')->value('value'));

        // The site shows the design's navigation because it is now wearing the
        // theme that owns it — not because anything was written over the
        // site's, which is still there and comes back with a change of theme.
        $this->assertSame(['About', 'Docs'], app(MenuRenderer::class)->items('primary')->pluck('label')->all());
        $this->assertSame(
            ['Our shop'],
            Menu::where('slot', 'primary')->first()->items()->orderBy('order_column')->pluck('label')->all()
        );
    }

    /**
     * Nothing is replaced any more, so nothing has to be kept. Keeping a
     * design moves the THEME over, and the design's navigation comes with it
     * because it belongs to that theme; the site's own menu is never touched,
     * and switching theme shows it again.
     */
    public function test_keeping_a_design_does_not_touch_the_sites_menu(): void
    {
        $this->siteMenu();
        $this->buildMenu();

        app(DesignPreviewFrame::class)->promote();

        $this->assertSame(
            ['Our shop'],
            Menu::where('slot', 'primary')->first()->items()->pluck('label')->all()
        );

        // And the design's is what the site now shows, because the site is now
        // wearing the theme that owns it.
        $this->assertSame(
            ['About', 'Docs'],
            app(MenuRenderer::class)->items('primary')->pluck('label')->all()
        );
    }

    /**
     * The four views a build writes for the article and topic pages are
     * rendered nowhere else, so a link has to be able to ask for any public
     * page in the design's theme — and the build has to follow that link
     * itself, over HTTP, with no session to be gated by. A secret in the
     * address is what allows both without putting an undecided design on show
     * to whoever guesses a query string.
     */
    public function test_asking_for_the_designs_theme_needs_the_token(): void
    {
        $frame = app(DesignPreviewFrame::class);

        (new UseThemeForPreviewTool())->execute(['theme' => 'default']);

        $token = $frame->token();

        $this->assertNotNull($token);
        $this->assertTrue($frame->matches($token));
        $this->assertFalse($frame->matches('1'));
        $this->assertFalse($frame->matches(null));
        $this->assertStringContainsString('design_preview=' . $token, $frame->previewUrl('https://site.test/posts'));
        $this->assertStringContainsString('&design_preview=', $frame->previewUrl('https://site.test/posts?page=2'));
    }

    public function test_staging_another_theme_retires_the_last_link(): void
    {
        $frame = app(DesignPreviewFrame::class);

        (new UseThemeForPreviewTool())->execute(['theme' => 'default']);
        $first = $frame->token();

        (new UseThemeForPreviewTool())->execute(['theme' => 'modern']);

        $this->assertNotSame($first, $frame->token());
        $this->assertFalse($frame->matches($first));
    }

    public function test_a_theme_staged_for_another_design_is_not_inherited(): void
    {
        $frame = app(DesignPreviewFrame::class);

        // Yesterday's build, for a different picture.
        $frame->setTheme('editorial', 'aaaaaaaaaaaaaaaa');

        $this->assertSame('editorial', $frame->theme());
        $this->assertSame('aaaaaaaaaaaaaaaa', $frame->designKey());

        // Today's, for this one. A corporate design handed to a rig holding an
        // editorial theme adopted it, never called create_theme, and spent
        // every round bending a magazine into a corporate site.
        if ($frame->designKey() !== 'bbbbbbbbbbbbbbbb') {
            $frame->forgetTheme();
        }

        $this->assertNull($frame->theme(), 'a theme written for another design is not this build\'s to use');
        $this->assertNull($frame->designKey());
    }

    public function test_a_theme_staged_for_the_same_design_is_kept(): void
    {
        $frame = app(DesignPreviewFrame::class);
        $frame->setTheme('editorial', 'bbbbbbbbbbbbbbbb');

        // Rebuilding the same picture reuses its theme rather than leaving a
        // near-duplicate behind on every run.
        if ($frame->designKey() !== 'bbbbbbbbbbbbbbbb') {
            $frame->forgetTheme();
        }

        $this->assertSame('editorial', $frame->theme());
    }

    public function test_staging_a_theme_does_not_wipe_which_design_it_is_for(): void
    {
        $frame = app(DesignPreviewFrame::class);
        VelaConfig::updateOrCreate(['key' => DesignPreviewFrame::DESIGN_KEY], ['value' => 'cccccccccccccccc']);

        // The command stamps the key once per run; the tool the model calls
        // knows nothing about which design is being built and must not clear it.
        (new UseThemeForPreviewTool())->execute(['theme' => 'default']);

        $this->assertSame('default', $frame->theme());
        $this->assertSame('cccccccccccccccc', $frame->designKey());
    }

    public function test_the_page_cannot_be_written_before_the_frame_exists(): void
    {
        $gate = new \ReflectionMethod(DesignBuilderService::class, 'refuseUntilThereIsAFrame');
        $gate->setAccessible(true);
        $builder = app(DesignBuilderService::class);

        // Nothing staged: the page would be dressed in whatever theme the site
        // happens to be wearing.
        foreach (['add_designed_section', 'add_block', 'add_row', 'update_custom_css'] as $tool) {
            $refusal = $gate->invoke($builder, $tool);
            $this->assertIsArray($refusal, $tool . ' must wait for the frame');
            $this->assertStringContainsString('create_theme', $refusal['error']);
        }

        // The frame's own steps are never held back, or the build could not
        // get started at all.
        foreach (['create_theme', 'use_theme_for_preview', 'set_menu', 'get_theme_contract'] as $tool) {
            $this->assertNull($gate->invoke($builder, $tool), $tool . ' is how the frame gets made');
        }

        app(DesignPreviewFrame::class)->setTheme('default');

        $this->assertNull($gate->invoke($builder, 'add_designed_section'), 'and once it exists, sections may go on');
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
