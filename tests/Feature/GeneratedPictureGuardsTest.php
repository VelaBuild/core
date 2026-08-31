<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Services\AiChat\Tools\GenerateImageTool;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Shown a design with three icons and a strip of customer logos across it, a
 * build asked for ten pictures: one illustration, three icons that came back
 * looking like stickers, and six approximated company trademarks — "Logo of
 * Intel", "Logo of Amazon", "Logo of Slack", "Logo of IBM" — which it then put
 * on the page. The strip in a design is a placeholder showing where a site's
 * own partners go. Reproduced, it announces relationships that do not exist,
 * in marks that belong to somebody else.
 */
class GeneratedPictureGuardsTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_it_will_not_draw_somebody_elses_mark(): void
    {
        $asked = [
            'Logo of Intel, simplistic vector style',
            'Logo of Amazon',
            'wordmark for Slack',
            'A trademark for IBM in flat style',
        ];

        foreach ($asked as $prompt) {
            $result = (new GenerateImageTool())->execute(['prompt' => $prompt]);

            $this->assertArrayHasKey('error', $result, "\"{$prompt}\" was not refused.");
            $this->assertStringContainsString('stands for somebody', $result['error']);
        }
    }

    /**
     * A site's own mark is a different thing, and is asked for as one.
     */
    public function test_a_site_may_still_ask_for_its_own_mark(): void
    {
        $asked = [
            'a logo for my bakery, a wheat sheaf in one colour',
            'our logo, a simple monogram in navy',
        ];

        foreach ($asked as $prompt) {
            $result = (new GenerateImageTool())->execute(['prompt' => $prompt]);

            // No image provider is configured in a test, so reaching that
            // complaint instead is what proves the guard let it through.
            $this->assertArrayHasKey('error', $result);
            $this->assertStringNotContainsString('stands for somebody', $result['error']);
        }
    }

    public function test_a_picture_that_is_not_a_mark_is_left_alone(): void
    {
        $result = (new GenerateImageTool())->execute([
            'prompt' => 'A cozy library with a person reading by a window, warm vintage illustration',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('stands for somebody', $result['error']);
    }

    public function test_the_placeholder_a_build_without_pictures_uses_is_really_there(): void
    {
        // The build is told to point empty slots at this. An address that
        // serves nothing would be the very fault the image guard exists to
        // catch, written into the instructions.
        $path = __DIR__ . '/../../public' . str_replace('/vendor/vela', '', DesignBuilderService::PLACEHOLDER);

        $this->assertFileExists($path);
    }
}
