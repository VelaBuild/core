<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Services\AiChat\Tools\UseThemeForPreviewTool;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The preview page has to be pointed at a theme by the name the theme is
 * really filed under.
 *
 * A build asked for "Zercurity" while its folder was `zercurity`. On a Mac the
 * filesystem does not care about case, so the tool said yes and stored the
 * spelling it was given; the page then rendered stamped `zercurity`, and the
 * check that the preview page is wearing the theme the build wrote compared
 * two spellings of one theme and threw a whole build away at the last step.
 */
class PreviewThemeNameTest extends PackageTestCase
{
    private string $theme = 'ab-case-theme';

    protected function setUp(): void
    {
        parent::setUp();
        @mkdir(resource_path('views/templates/' . $this->theme), 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir(resource_path('views/templates/' . $this->theme));
        parent::tearDown();
    }

    public function test_the_theme_is_filed_under_the_name_it_really_has(): void
    {
        $result = (new UseThemeForPreviewTool)->execute(['theme' => 'AB-Case-Theme']);

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame($this->theme, $result['theme']);
        $this->assertSame($this->theme, app(DesignPreviewFrame::class)->theme());
    }

    public function test_a_theme_that_does_not_exist_is_still_refused(): void
    {
        $result = (new UseThemeForPreviewTool)->execute(['theme' => 'no-such-theme-here']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('no theme called', $result['error']);
    }
}
