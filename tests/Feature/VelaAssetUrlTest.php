<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A core stylesheet or script whose URL never changes stays in the browser's
 * cache after an update. The admin then ran new page-editor.js against old
 * vela-admin.css, and a block editor's previews and choices came out unstyled.
 */
class VelaAssetUrlTest extends PackageTestCase
{
    public function test_the_url_carries_a_version(): void
    {
        $this->assertMatchesRegularExpression('#/vendor/vela/css/vela-admin\.css\?v=\S+$#', vela_asset('vendor/vela/css/vela-admin.css'));
    }

    public function test_layouts_do_not_link_a_core_asset_without_a_version(): void
    {
        foreach (['admin', 'public'] as $layout) {
            $blade = file_get_contents(__DIR__ . '/../../resources/views/layouts/' . $layout . '.blade.php');

            $this->assertDoesNotMatchRegularExpression(
                "#(?<!vela_)asset\('vendor/vela/[^']+\.(css|js)'\) \}\}(?!\?v=)#",
                $blade,
                "layouts/{$layout} links a core asset with a URL that never changes; use vela_asset()."
            );
        }
    }
}
