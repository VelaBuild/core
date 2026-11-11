<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use VelaBuild\Core\Services\PluginAssetSync;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Plugin CSS/JS used to be published once and never again (vendor:publish
 * without --force), so a plugin update never reached the site. With --force a
 * file the site had edited would be lost. These files are replaced only when
 * the site has not changed them.
 */
class PluginAssetSyncTest extends PackageTestCase
{
    private string $root;
    private string $plugin;
    private string $public;
    private string $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/vela-plugin-assets-' . uniqid();
        $this->plugin = $this->root . '/plugin/public';
        $this->public = $this->root . '/site/public';
        $this->state = $this->root . '/site/storage';
        File::ensureDirectoryExists($this->plugin . '/css');
        File::ensureDirectoryExists($this->public);
        $this->app->usePublicPath($this->public);

        file_put_contents($this->plugin . '/css/shop.css', ".cart{color:red}\n");
        file_put_contents($this->plugin . '/shop.js', "console.log(1);\n");

        $this->publish([$this->plugin => public_path('vendor/acme-shop')]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->plugin, $this->root . '/plugin/views'] as $from) {
            unset(ServiceProvider::$publishes[self::class][$from], ServiceProvider::$publishGroups[PluginAssetSync::TAG][$from]);
        }
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** As a plugin's service provider does. */
    private function publish(array $paths): void
    {
        foreach ($paths as $from => $to) {
            ServiceProvider::$publishes[self::class][$from] = $to;
            ServiceProvider::$publishGroups[PluginAssetSync::TAG][$from] = $to;
        }
    }

    private function sync(): PluginAssetSync
    {
        return new PluginAssetSync($this->state);
    }

    private function site(string $path): string
    {
        return $this->public . '/vendor/acme-shop/' . $path;
    }

    public function test_a_plugin_installed_for_the_first_time_has_its_files_copied(): void
    {
        $done = $this->sync()->sync();

        $this->assertContains('vendor/acme-shop/css/shop.css', $done['updated']);
        $this->assertContains('vendor/acme-shop/shop.js', $done['updated']);
        $this->assertStringEqualsFile($this->site('css/shop.css'), ".cart{color:red}\n");
        $this->assertSame([], $done['backed_up']);

        $again = $this->sync()->sync();
        $this->assertSame([], $again['updated'], 'nothing changed, so nothing is copied');
    }

    public function test_an_update_replaces_a_file_the_site_did_not_change(): void
    {
        $this->sync()->sync();

        file_put_contents($this->plugin . '/css/shop.css', ".cart{color:blue}\n");
        $done = $this->sync()->sync();

        $this->assertSame(['vendor/acme-shop/css/shop.css'], $done['updated']);
        $this->assertStringEqualsFile($this->site('css/shop.css'), ".cart{color:blue}\n");
        $this->assertSame([], $this->sync()->modified());
    }

    public function test_a_file_the_site_changed_is_kept_and_reported(): void
    {
        $this->sync()->sync();

        file_put_contents($this->site('css/shop.css'), ".cart{color:green} /* ours */\n");
        file_put_contents($this->plugin . '/css/shop.css', ".cart{color:blue}\n");
        $done = $this->sync()->sync();

        $this->assertSame(['vendor/acme-shop/css/shop.css'], $done['kept']);
        $this->assertStringEqualsFile($this->site('css/shop.css'), ".cart{color:green} /* ours */\n", 'the site\'s change must survive');
        $this->assertSame(['vendor/acme-shop/css/shop.css'], $this->sync()->modified());

        $diff = $this->sync()->diff('vendor/acme-shop/css/shop.css');
        $this->assertStringContainsString('-.cart{color:green} /* ours */', $diff);
        $this->assertStringContainsString('+.cart{color:blue}', $diff);

        // Deleting the file takes the plugin's version.
        unlink($this->site('css/shop.css'));
        $this->sync()->sync();
        $this->assertStringEqualsFile($this->site('css/shop.css'), ".cart{color:blue}\n");
        $this->assertSame([], $this->sync()->modified());
    }

    public function test_a_file_published_before_this_was_recorded_is_backed_up_before_it_is_replaced(): void
    {
        // As vendor:publish left it on a site that had an older plugin.
        File::ensureDirectoryExists(dirname($this->site('css/shop.css')));
        file_put_contents($this->site('css/shop.css'), ".cart{color:old}\n");

        $done = $this->sync()->sync();

        $this->assertSame(['vendor/acme-shop/css/shop.css'], $done['backed_up']);
        $this->assertStringEqualsFile($this->site('css/shop.css'), ".cart{color:red}\n");
        $this->assertStringEqualsFile($done['backup'] . '/vendor/acme-shop/css/shop.css', ".cart{color:old}\n");
    }

    public function test_files_published_outside_public_are_never_touched(): void
    {
        // A template under the same tag must not be copied over a site's override.
        $views = $this->root . '/plugin/views';
        File::ensureDirectoryExists($views);
        file_put_contents($views . '/cart.blade.php', 'plugin');
        $override = $this->root . '/site/resources/views/vendor/acme-shop';
        File::ensureDirectoryExists($override);
        file_put_contents($override . '/cart.blade.php', 'site');
        $this->publish([$views => $override]);

        $this->sync()->sync();

        $this->assertStringEqualsFile($override . '/cart.blade.php', 'site');
        $this->assertArrayNotHasKey('cart.blade.php', $this->sync()->files());
    }

    public function test_only_published_files_can_be_diffed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sync()->diff('../../.env');
    }
}
