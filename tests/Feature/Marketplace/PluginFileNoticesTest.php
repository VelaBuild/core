<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
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

    public function test_every_admin_page_names_the_outdated_template_count_and_command(): void
    {
        $this->loginAsAdmin();

        $this->get(route('vela.admin.pages.index'))
            ->assertOk()
            ->assertSee('1 template your site overrides is older than its plugin')
            ->assertSee('php artisan vela:templates --diff');
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

    public function test_the_diff_only_opens_override_files(): void
    {
        $this->loginAsAdmin();

        $this->get(route('vela.admin.packages.templates.diff', ['path' => base_path('.env')]))->assertNotFound();
        $this->get(route('vela.admin.packages.assets.diff', ['path' => '../.env']))->assertNotFound();
    }
}
