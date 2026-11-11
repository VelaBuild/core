<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use VelaBuild\Core\Services\PluginAssetSync;
use VelaBuild\Core\Services\SiteHealth;
use VelaBuild\Core\Tests\TestCase;

/**
 * An outdated template override is said in the admin: on every page, and on
 * Installed packages with both versions and a diff.
 */
class PluginFileNoticesTest extends TestCase
{
    use DatabaseTransactions;

    private string $root;
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/vela-plugin-notices-' . uniqid();
        $plugin = $this->root . '/plugin/views';
        File::ensureDirectoryExists($plugin);
        File::ensureDirectoryExists($this->root . '/site/views/vendor/acme-shop');
        file_put_contents($plugin . '/cart.blade.php', "{{-- @version 1.2.0 --}}\n<div>new</div>\n");
        $this->site = $this->root . '/site/views/vendor/acme-shop/cart.blade.php';
        file_put_contents($this->site, "{{-- @version 1.0.0 --}}\n<div>ours</div>\n");

        $this->app['view']->addNamespace('acme-shop', $plugin);
        config(['view.paths' => array_merge(config('view.paths'), [$this->root . '/site/views'])]);
        SiteHealth::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        SiteHealth::forget();

        parent::tearDown();
    }

    public function test_every_admin_page_says_it_in_one_line_with_a_button_to_installed_packages(): void
    {
        $this->loginAsAdmin();

        $this->get(route('vela.admin.pages.index'))
            ->assertOk()
            ->assertSee('1 plugin file needs a look after an update')
            ->assertSee(route('vela.admin.packages.index'), false);

        // Installed packages lists the files itself, so the line is not repeated there.
        $this->get(route('vela.admin.packages.index'))
            ->assertOk()
            ->assertDontSee('needs a look after an update');
    }

    public function test_installed_packages_lists_both_versions_and_links_the_diff(): void
    {
        $this->loginAsAdmin();

        $this->get(route('vela.admin.packages.index'))
            ->assertOk()
            ->assertSee($this->site)
            ->assertSee('1.0.0')
            ->assertSee('1.2.0')
            ->assertSee(route('vela.admin.packages.templates.diff', ['path' => $this->site]), false);

        $this->get(route('vela.admin.packages.templates.diff', ['path' => $this->site]))
            ->assertOk()
            ->assertSee('+<div>new</div>', false);
    }

    public function test_keep_mine_stops_reporting_a_plugin_file_the_site_changed(): void
    {
        $this->loginAsAdmin();

        // A plugin CSS file, copied once, then edited here and updated upstream.
        $public = $this->root . '/public';
        File::ensureDirectoryExists($public);
        $this->app->usePublicPath($public);
        $source = $this->root . '/plugin/public';
        File::ensureDirectoryExists($source);
        file_put_contents($source . '/shop.css', "a{}\n");
        \Illuminate\Support\ServiceProvider::$publishes[self::class][$source] = $public . '/vendor/acme-shop';
        \Illuminate\Support\ServiceProvider::$publishGroups['laravel-assets'][$source] = $public . '/vendor/acme-shop';
        $this->app->instance(PluginAssetSync::class, $sync = new PluginAssetSync($this->root . '/state'));

        try {
            $sync->sync();
            file_put_contents($public . '/vendor/acme-shop/shop.css', "a{color:red}\n");
            file_put_contents($source . '/shop.css', "a{color:blue}\n");

            $this->get(route('vela.admin.packages.index'))->assertSee('Keep mine');

            $this->post(route('vela.admin.packages.assets.keep'), ['path' => 'vendor/acme-shop/shop.css'])
                ->assertRedirect(route('vela.admin.packages.index'));

            $this->assertSame([], $sync->modified());
            $this->assertStringEqualsFile($public . '/vendor/acme-shop/shop.css', "a{color:red}\n");
            $this->post(route('vela.admin.packages.assets.keep'), ['path' => '../.env'])->assertNotFound();
        } finally {
            unset(\Illuminate\Support\ServiceProvider::$publishes[self::class], \Illuminate\Support\ServiceProvider::$publishGroups['laravel-assets'][$source]);
        }
    }

    public function test_the_diff_only_opens_override_files(): void
    {
        $this->loginAsAdmin();

        $this->get(route('vela.admin.packages.templates.diff', ['path' => base_path('.env')]))->assertNotFound();
        $this->get(route('vela.admin.packages.assets.diff', ['path' => '../.env']))->assertNotFound();
    }
}
