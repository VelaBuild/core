<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\ThemeHomeTemplate;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A theme written by a design build had no example homepage of its own.
 *
 * Every shipped theme carries a home-template.json, and that one file is what
 * Settings → Appearance offers to install, what the standing "this theme has a
 * homepage of its own" panel points at, and what the screenshot command
 * photographs for the theme's card. A generated theme carried none, so none of
 * the three worked for it — reported as "the themes it makes have no
 * install-homepage".
 */
class ThemeHomeTemplateTest extends PackageTestCase
{
    private function theme(string $name = 'lantern'): string
    {
        $dir = resource_path('views/templates/' . $name);
        File::ensureDirectoryExists($dir);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

        return $dir;
    }

    private function pageWithASection(string $slug = 'design-preview'): Page
    {
        $page = Page::create([
            'title' => 'Design preview',
            'slug' => $slug,
            'locale' => 'en',
            'status' => 'published',
            'custom_css' => '.vela-design-abc .hero{background:#101010;padding:64px}',
        ]);

        $row = PageRow::create([
            'page_id' => $page->id,
            'name' => 'Hero',
            'width' => 'full',
            'padding' => '0',
            'order_column' => 0,
        ]);

        $row->blocks()->create([
            'type' => 'html',
            'content' => ['html' => '<section class="hero"><h1>Be prepared</h1></section>'],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        return $page;
    }

    public function test_a_kept_design_becomes_the_theme_s_example_homepage(): void
    {
        $dir = $this->theme();
        $page = $this->pageWithASection();

        $this->assertFalse(app(ThemeHomeTemplate::class)->has('lantern'));

        $this->assertTrue(app(ThemeHomeTemplate::class)->writeFrom($page, 'lantern'));
        $this->assertTrue(app(ThemeHomeTemplate::class)->has('lantern'));

        $rows = json_decode(file_get_contents($dir . '/home-template.json'), true);

        $this->assertCount(1, $rows);
        $this->assertSame('Hero', $rows[0]['name']);
        $this->assertSame('full', $rows[0]['width']);
        $this->assertSame('html', $rows[0]['blocks'][0]['type']);
        $this->assertStringContainsString('Be prepared', $rows[0]['blocks'][0]['content']['html']);
    }

    /**
     * The layout on its own would come back wearing nothing.
     *
     * A shipped theme's example homepage is built from blocks the theme
     * already styles; a built one is markup with a stylesheet that lives on
     * the PAGE, so it has to travel with the layout.
     */
    public function test_the_stylesheet_travels_with_the_layout(): void
    {
        $dir = $this->theme();
        app(ThemeHomeTemplate::class)->writeFrom($this->pageWithASection(), 'lantern');

        $this->assertStringContainsString(
            '.vela-design-abc .hero',
            (string) file_get_contents($dir . '/home-template.css')
        );
        $this->assertStringContainsString('.vela-design-abc', (string) app(ThemeHomeTemplate::class)->stylesheetFor('lantern'));
    }

    /** And a page that has none leaves none behind from last time. */
    public function test_a_page_with_no_stylesheet_clears_the_one_that_was_there(): void
    {
        $dir = $this->theme();
        app(ThemeHomeTemplate::class)->writeFrom($this->pageWithASection(), 'lantern');
        $this->assertFileExists($dir . '/home-template.css');

        $plain = $this->pageWithASection('plain');
        $plain->update(['custom_css' => null]);

        app(ThemeHomeTemplate::class)->writeFrom($plain, 'lantern');

        $this->assertFileDoesNotExist($dir . '/home-template.css');
        $this->assertNull(app(ThemeHomeTemplate::class)->stylesheetFor('lantern'));
    }

    /**
     * Never into the package. A theme that ships with Vela lives in the vendor
     * directory, and the next update would take anything written there away.
     */
    public function test_a_theme_this_site_does_not_own_is_never_written_to(): void
    {
        $service = app(ThemeHomeTemplate::class);

        $this->assertNull($service->pathFor('corporate'), 'a packaged theme is not the site\'s to write');
        $this->assertNull($service->pathFor('../../../etc'));
        $this->assertNull($service->pathFor(''));
        $this->assertFalse($service->writeFrom($this->pageWithASection(), 'corporate'));
    }

    /**
     * And a theme already on the site can be given one at any time.
     *
     * Writing it when a design is kept only helps from then on: the themes
     * already on a site had none, so switching to one still offered nothing to
     * install — which is how this was reported the second time.
     */
    public function test_the_homepage_can_be_kept_as_the_active_theme_s_example(): void
    {
        $dir = $this->theme();
        config(['vela.template.active' => 'lantern']);

        $home = $this->pageWithASection('home');

        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);

        $this->post(route('vela.admin.settings.appearance.saveHomeTemplate'), ['template' => 'lantern'])
            ->assertSessionHas('success');

        $this->assertTrue(app(ThemeHomeTemplate::class)->has('lantern'));
        $this->assertStringContainsString(
            'Be prepared',
            (string) file_get_contents($dir . '/home-template.json')
        );
        $this->assertNotNull(app(ThemeHomeTemplate::class)->stylesheetFor('lantern'), 'and its stylesheet with it');
        $this->assertSame($home->id, Page::where('slug', 'home')->first()->id);
    }

    /** Only the theme in use: the homepage is wearing that one and no other. */
    public function test_a_theme_that_is_not_in_use_cannot_keep_the_homepage(): void
    {
        $this->theme();
        $this->theme('other');
        config(['vela.template.active' => 'lantern']);
        $this->pageWithASection('home');

        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);

        $this->post(route('vela.admin.settings.appearance.saveHomeTemplate'), ['template' => 'other'])
            ->assertSessionHas('error');

        $this->assertFalse(app(ThemeHomeTemplate::class)->has('other'));
    }

    /** An empty page is not an example of anything. */
    public function test_a_page_with_nothing_on_it_writes_nothing(): void
    {
        $dir = $this->theme();

        $empty = Page::create([
            'title' => 'Nothing',
            'slug' => 'nothing',
            'locale' => 'en',
            'status' => 'published',
        ]);

        $this->assertFalse(app(ThemeHomeTemplate::class)->writeFrom($empty, 'lantern'));
        $this->assertFileDoesNotExist($dir . '/home-template.json');
    }
}
