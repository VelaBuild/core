<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Installing a theme's example homepage used to delete the homepage outright.
 *
 * It emptied the page of every row and block and overwrote its stylesheet, and
 * nothing anywhere kept a copy — no archive, no revision, no undo. A site
 * built from a design and kept as the homepage was gone the moment somebody
 * pressed a theme's "install as homepage" to see what that theme looked like,
 * and the only way back was reading the markup out of the AI conversation that
 * had written it.
 *
 * Every other place in Vela that displaces a homepage parks it unlisted first.
 */
class InstallHomepageKeepsWhatItReplacesTest extends PackageTestCase
{
    private function themeWithAnExampleHomepage(string $name = 'lantern'): string
    {
        $dir = resource_path('views/templates/' . $name);
        File::ensureDirectoryExists($dir);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

        // Templates are discovered once at boot, and this one is made after
        // it: registering it is what the scan would have done.
        app(\VelaBuild\Core\Vela::class)->registerTemplate($name, [
            'path' => $dir,
            'label' => ucfirst($name),
            'namespace' => 'vela-' . $name,
        ]);

        file_put_contents($dir . '/home-template.json', json_encode([[
            'name' => 'Hero',
            'width' => 'contained',
            'blocks' => [['type' => 'hero', 'content' => ['title' => 'A theme\'s own example']]],
        ]]));

        return $dir;
    }

    private function homepageWithADesignOnIt(): Page
    {
        $page = Page::create([
            'title' => 'Flowblox',
            'slug' => 'home',
            'locale' => 'en',
            'status' => 'published',
            'custom_css' => '.vela-design-abc .hero{background:#101010}',
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
            'content' => ['html' => '<section class="hero"><h1>Streamline Your Team</h1></section>'],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        return $page;
    }

    private function asAnAdmin(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    public function test_the_homepage_it_replaces_is_still_there_afterwards(): void
    {
        $this->themeWithAnExampleHomepage();
        $home = $this->homepageWithADesignOnIt();
        $this->asAnAdmin();

        $this->post(route('vela.admin.settings.appearance.installHomepage'), [
            'template' => 'lantern',
            'mode' => 'replace',
        ])->assertSessionHas('success');

        // The homepage is the theme's example now.
        $home->refresh()->load('rows.blocks');
        $this->assertSame('hero', $home->rows->first()->blocks->first()->type);

        $kept = Page::where('slug', '!=', 'home')->where('status', 'unlisted')->latest('id')->first();

        $this->assertNotNull($kept, 'the homepage that was replaced is kept');
        $this->assertStringStartsWith('home-', $kept->slug);
        $this->assertSame('Flowblox', $kept->title);
        // Its rows, its blocks and the stylesheet that painted them: a design
        // section without its CSS comes back wearing nothing, which is the
        // same loss one step later.
        $this->assertStringContainsString(
            'Streamline Your Team',
            (string) $kept->rows->first()->blocks->first()->content['html']
        );
        $this->assertSame('.vela-design-abc .hero{background:#101010}', $kept->custom_css);

        // A copy, not a rename: everything pointing at the homepage by id goes
        // on pointing at the homepage.
        $this->assertNotSame($home->id, $kept->id);
    }

    /** And the person who pressed it is told where it went. */
    public function test_it_says_where_the_old_homepage_is(): void
    {
        $this->themeWithAnExampleHomepage();
        $this->homepageWithADesignOnIt();
        $this->asAnAdmin();

        $response = $this->post(route('vela.admin.settings.appearance.installHomepage'), [
            'template' => 'lantern',
            'mode' => 'replace',
        ]);

        $kept = Page::where('slug', '!=', 'home')->where('status', 'unlisted')->latest('id')->first();

        $this->assertStringContainsString($kept->slug, (string) session('success'));
        $response->assertSessionHas('success');
    }

    /** An empty homepage is nothing to keep, and parking one is just litter. */
    public function test_an_empty_homepage_leaves_nothing_behind(): void
    {
        $this->themeWithAnExampleHomepage();
        Page::create([
            'title' => 'Home',
            'slug' => 'home',
            'locale' => 'en',
            'status' => 'published',
        ]);
        $this->asAnAdmin();

        $this->post(route('vela.admin.settings.appearance.installHomepage'), [
            'template' => 'lantern',
            'mode' => 'replace',
        ])->assertSessionHas('success');

        $this->assertSame(1, Page::count());
    }
}
