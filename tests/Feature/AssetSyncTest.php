<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use VelaBuild\Core\Services\AssetSync;
use VelaBuild\Core\Services\ThemeSkeleton;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * After `composer update velabuild/core` a site's public/vendor/vela is still
 * the old copy until something publishes it and rebuilds the bundles.
 */
class AssetSyncTest extends PackageTestCase
{
    private string $public;
    private string $theme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = sys_get_temp_dir() . '/vela-asset-sync-' . uniqid();
        File::ensureDirectoryExists($this->public);
        $this->app->usePublicPath($this->public);
        config([
            'vela.assets.output_dir' => $this->public . '/vendor/vela/bundles',
            'vela.assets.manifest' => $this->public . '/vendor/vela/bundles/manifest.json',
            // A site lists bundle sources relative to its root, whose public/
            // this temporary folder stands in for.
            'vela.assets.bundles' => ['public' => ['css' => [$this->public . '/vendor/vela/css/page-blocks.css'], 'js' => []]],
        ]);

        $this->theme = resource_path('views/templates/asset-sync-check');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->public);
        File::deleteDirectory($this->theme);

        parent::tearDown();
    }

    public function test_a_site_that_never_published_gets_the_files_and_the_bundles(): void
    {
        $sync = app(AssetSync::class);
        $this->assertFalse($sync->isCurrent());

        $done = $sync->syncIfStale();

        $this->assertTrue($done['copied']);
        $this->assertFileExists($this->public . '/vendor/vela/js/vela-carousel.js');
        $this->assertFileExists($this->public . '/vendor/vela/css/page-blocks.css');
        $this->assertGreaterThan(0, $done['bundles']);
        $this->assertFileExists($this->public . '/vendor/vela/bundles/manifest.json');
        $this->assertTrue($sync->isCurrent());
    }

    public function test_an_up_to_date_site_does_nothing(): void
    {
        $sync = app(AssetSync::class);
        $sync->syncIfStale();

        $this->assertNull($sync->syncIfStale());
    }

    public function test_an_old_copy_is_replaced(): void
    {
        $sync = app(AssetSync::class);
        $sync->syncIfStale();

        // The copy a site had from before the update, and a stamp that no
        // longer matches the package.
        file_put_contents($this->public . '/vendor/vela/css/page-blocks.css', '/* old */');
        foreach (glob($this->public . '/vendor/vela/.synced-*') as $stamp) {
            file_put_contents($stamp, 'an older release');
        }

        $this->assertNotNull($sync->syncIfStale());
        $this->assertStringNotContainsString('/* old */', file_get_contents($this->public . '/vendor/vela/css/page-blocks.css'));
    }

    public function test_the_sites_own_themes_get_the_token_contract(): void
    {
        File::ensureDirectoryExists($this->theme);
        $bare = preg_replace('/^\s*--vela-[a-z-]+: var\(--[a-z-]+\);\n/m', '', app(ThemeSkeleton::class)->layout());
        file_put_contents($this->theme . '/layout.blade.php', $bare);

        $done = app(AssetSync::class)->syncIfStale();

        $this->assertContains('asset-sync-check', $done['themes']);
        $this->assertStringContainsString('--vela-primary: var(--accent);', file_get_contents($this->theme . '/layout.blade.php'));
    }
}
