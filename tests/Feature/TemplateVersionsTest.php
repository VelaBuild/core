<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use VelaBuild\Core\Services\TemplateVersions;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A site's copy of a plugin template (resources/views/vendor/{namespace}/...)
 * never changes when the plugin does. As WooCommerce does, plugin templates
 * state a version, and an override older than the plugin's is reported.
 */
class TemplateVersionsTest extends PackageTestCase
{
    private string $root;
    private string $plugin;
    private string $override;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/vela-template-versions-' . uniqid();
        $this->plugin = $this->root . '/plugin/resources/views';
        $this->override = $this->root . '/site/views/vendor/acme-shop';
        File::ensureDirectoryExists($this->plugin . '/public');
        File::ensureDirectoryExists($this->override . '/public');

        // As a plugin's loadViewsFrom() does, with the site's views folder here.
        $this->app['view']->addNamespace('acme-shop', $this->plugin);
        config(['view.paths' => [$this->root . '/site/views']]);
        TemplateVersions::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        TemplateVersions::forget();

        parent::tearDown();
    }

    private function plugin(string $path, string $contents): void
    {
        file_put_contents($this->plugin . '/' . $path, $contents);
    }

    private function override(string $path, string $contents): string
    {
        file_put_contents($this->override . '/' . $path, $contents);

        return $this->override . '/' . $path;
    }

    public function test_the_version_is_read_from_the_header(): void
    {
        $this->assertSame('1.2.0', TemplateVersions::versionOf("{{-- @version 1.2.0 --}}\n<div></div>"));
        $this->assertSame('0.3.0', TemplateVersions::versionOf("{{--\n  Cart page.\n  @version v0.3.0\n--}}"));
        $this->assertSame('2.0.0-beta.1', TemplateVersions::versionOf('{{-- @version 2.0.0-beta.1 --}}'));
        $this->assertNull(TemplateVersions::versionOf('<div>@version is not here</div>'));
        $this->assertNull(TemplateVersions::versionOf(str_repeat(' ', 4096) . '{{-- @version 1.0.0 --}}'), 'only the header counts');
    }

    public function test_an_override_older_than_the_plugin_is_outdated(): void
    {
        $this->plugin('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n<div class=\"cart\">new</div>\n");
        $site = $this->override('public/cart.blade.php', "{{-- @version 1.0.0 --}}\n<div class=\"cart\">ours</div>\n");

        $report = (new TemplateVersions())->report();

        $this->assertCount(1, $report);
        $this->assertSame('acme-shop::public.cart', $report[0]['view']);
        $this->assertSame($site, $report[0]['path']);
        $this->assertSame('1.0.0', $report[0]['site_version']);
        $this->assertSame('1.2.0', $report[0]['plugin_version']);
        $this->assertSame(TemplateVersions::OUTDATED, $report[0]['status']);
        $this->assertCount(1, (new TemplateVersions())->outdated());

        // Reported, never changed.
        $this->assertStringContainsString('ours', file_get_contents($site));
    }

    public function test_an_override_without_a_version_is_outdated(): void
    {
        $this->plugin('public/cart.blade.php', "{{-- @version 1.0.0 --}}\n<div></div>\n");
        $this->override('public/cart.blade.php', "<div>copied before versions</div>\n");

        $report = (new TemplateVersions())->report();

        $this->assertNull($report[0]['site_version']);
        $this->assertSame(TemplateVersions::OUTDATED, $report[0]['status']);
    }

    public function test_an_override_at_or_past_the_plugin_version_is_current(): void
    {
        $this->plugin('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n");
        $this->plugin('public/shop.blade.php', "{{-- @version 1.2.0 --}}\n");
        $this->override('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n");
        $this->override('public/shop.blade.php', "{{-- @version 1.10.0 --}}\n");

        $versions = new TemplateVersions();

        $this->assertSame([TemplateVersions::CURRENT, TemplateVersions::CURRENT], array_column($versions->report(), 'status'));
        $this->assertSame([], $versions->outdated());
        $this->assertSame(0, $versions->outdatedCount());
    }

    public function test_templates_the_plugin_does_not_version_and_files_it_does_not_have_are_left_out(): void
    {
        $this->plugin('public/cart.blade.php', "<div>no version</div>\n");
        $this->override('public/cart.blade.php', "<div>ours</div>\n");
        $this->override('public/extra.blade.php', "{{-- @version 0.1.0 --}}\n");

        $this->assertSame([], (new TemplateVersions())->report());
    }

    public function test_the_diff_shows_the_override_against_the_plugin_template(): void
    {
        $this->plugin('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n<div class=\"cart\">new</div>\n");
        $site = $this->override('public/cart.blade.php', "{{-- @version 1.0.0 --}}\n<div class=\"cart\">ours</div>\n");

        $diff = (new TemplateVersions())->diff($site);

        $this->assertStringContainsString('-{{-- @version 1.0.0 --}}', $diff);
        $this->assertStringContainsString('+{{-- @version 1.2.0 --}}', $diff);
        $this->assertStringContainsString('+<div class="cart">new</div>', $diff);
    }

    public function test_only_an_override_can_be_diffed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TemplateVersions())->diff(base_path('.env'));
    }

    public function test_the_command_lists_outdated_overrides_with_both_versions(): void
    {
        $this->plugin('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n<div>new</div>\n");
        $site = $this->override('public/cart.blade.php', "{{-- @version 1.0.0 --}}\n<div>ours</div>\n");

        $this->artisan('vela:templates')
            ->expectsOutputToContain($site)
            ->expectsOutputToContain('vela:templates --diff')
            ->assertSuccessful();

        $this->artisan('vela:templates', ['--diff' => true])
            ->expectsOutputToContain('+<div>new</div>')
            ->assertSuccessful();
    }

    public function test_the_command_says_so_when_every_override_is_current(): void
    {
        $this->plugin('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n");
        $this->override('public/cart.blade.php', "{{-- @version 1.2.0 --}}\n");

        $this->artisan('vela:templates')
            ->expectsOutputToContain('up to date')
            ->assertSuccessful();
    }
}
