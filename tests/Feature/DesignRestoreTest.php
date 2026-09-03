<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Undoing a design that was kept.
 *
 * Keeping one moves three things at once — the homepage, the theme and the
 * navigation — and only two of them could be found again afterwards: the old
 * homepage under a timestamped slug and the menus under superseded_ ones. The
 * theme was simply overwritten, so somebody switching back in Settings →
 * Appearance got their old theme around the design's content and reasonably
 * concluded the theme system had eaten their pages.
 */
class DesignRestoreTest extends PackageTestCase
{
    private function signInAsAdmin(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    private function makePage(string $slug, string $title, string $status = 'published'): Page
    {
        return Page::create([
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'locale' => config('vela.primary_language', 'en'),
        ]);
    }

    /** A site that has just kept a design, as useAsHomepage leaves it. */
    private function keepADesign(): void
    {
        $this->makePage('home', 'The site they had');
        $preview = $this->makePage(\VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG, 'Zercurity', 'unlisted');

        VelaConfig::updateOrCreate(['key' => 'site_name'], ['value' => 'The site they had']);
        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'modern']);
        config(['vela.template.active' => 'modern']);

        $frame = app(DesignPreviewFrame::class);
        $frame->setTheme('zercurity');

        $staged = Menu::firstOrCreate(['slot' => 'design_preview_primary'], ['name' => 'Staged']);
        $staged->items()->create(['label' => 'Docs', 'type' => 'url', 'url' => '/docs', 'order_column' => 0]);

        $live = Menu::firstOrCreate(['slot' => 'primary'], ['name' => 'Primary']);
        $live->items()->create(['label' => 'Articles', 'type' => 'url', 'url' => '/articles', 'order_column' => 0]);

        $this->post(route('vela.admin.settings.design-builder.use'))->assertRedirect();
        $preview->refresh();
    }

    public function test_keeping_a_design_notes_what_it_replaced(): void
    {
        $this->signInAsAdmin();
        $this->keepADesign();

        $this->assertSame('zercurity', VelaConfig::where('key', 'active_template')->value('value'));
        $this->assertSame('modern', VelaConfig::where('key', DesignPreviewFrame::SUPERSEDED_THEME_KEY)->value('value'));
        $this->assertTrue(app(DesignPreviewFrame::class)->canDemote());
    }

    public function test_all_three_go_back_together(): void
    {
        $this->signInAsAdmin();
        $this->keepADesign();

        $this->post(route('vela.admin.settings.design-builder.restore'))->assertRedirect();

        // The homepage.
        $this->assertSame('The site they had', Page::where('slug', 'home')->value('title'));
        // The theme.
        $this->assertSame('modern', VelaConfig::where('key', 'active_template')->value('value'));
        // The navigation.
        $this->assertSame(
            ['Articles'],
            Menu::where('slot', 'primary')->first()->items()->pluck('label')->all()
        );
        // And the site's name came back with its homepage.
        $this->assertSame('The site they had', VelaConfig::where('key', 'site_name')->value('value'));
    }

    public function test_the_design_is_kept_rather_than_thrown_away(): void
    {
        $this->signInAsAdmin();
        $this->keepADesign();

        $this->post(route('vela.admin.settings.design-builder.restore'))->assertRedirect();

        $design = Page::where('title', 'Zercurity')->first();
        $this->assertNotNull($design, 'the design page was deleted');
        $this->assertSame('unlisted', $design->status);
        $this->assertStringStartsWith('home-', $design->slug);
    }

    public function test_with_nothing_to_go_back_to_it_says_so(): void
    {
        $this->signInAsAdmin();

        $this->post(route('vela.admin.settings.design-builder.restore'))
            ->assertSessionHasErrors('build');
    }

    /**
     * Keeping the same design twice must not record the design's own theme as
     * the thing to go back to — that would make "put it back" a no-op wearing
     * the name of an undo.
     */
    public function test_keeping_a_design_twice_still_points_at_the_theme_before_it(): void
    {
        $this->signInAsAdmin();
        $this->keepADesign();

        $this->makePage(\VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG, 'Zercurity again', 'unlisted');
        $this->post(route('vela.admin.settings.design-builder.use'))->assertRedirect();

        $this->assertSame('modern', VelaConfig::where('key', DesignPreviewFrame::SUPERSEDED_THEME_KEY)->value('value'));
    }
}
