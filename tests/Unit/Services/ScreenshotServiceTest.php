<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Tests\PackageTestCase;

class ScreenshotServiceTest extends PackageTestCase
{
    protected ScreenshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScreenshotService();
    }

    public function test_is_available_returns_bool(): void
    {
        $result = $this->service->isAvailable();
        $this->assertIsBool($result);
    }

    public function test_find_chrome_binary_returns_string_or_null(): void
    {
        $result = $this->service->findChromeBinary();
        $this->assertTrue(is_string($result) || is_null($result));
    }

    public function test_mark_element_puts_a_strip_either_side_of_a_written_section(): void
    {
        $html = '<html><body><div class="page"><div class="vela-design-a" data-vela-block="b1">'
            . '<h1>Hero</h1></div><div data-vela-block="b2">Next</div></div></body></html>';

        $marked = $this->service->markElement($html, 'b1');

        $this->assertNotNull($marked);
        $this->assertSame(2, substr_count($marked, ScreenshotService::SECTION_MARKER_COLOUR));

        // Above and below the section itself, not around the whole page and not
        // around the section that follows it.
        $before = strpos($marked, ScreenshotService::SECTION_MARKER_COLOUR);
        $after = strrpos($marked, ScreenshotService::SECTION_MARKER_COLOUR);
        $hero = strpos($marked, '<h1>Hero</h1>');
        $next = strpos($marked, 'Next');

        $this->assertLessThan($hero, $before);
        $this->assertGreaterThan($hero, $after);
        $this->assertLessThan($next, $after);
    }

    public function test_mark_element_accepts_a_class_or_an_id(): void
    {
        $html = '<html><body><section class="hero band" id="top">Words</section></body></html>';

        foreach (['.hero', '#top'] as $handle) {
            $marked = $this->service->markElement($html, $handle);
            $this->assertNotNull($marked, $handle . ' should match');
            $this->assertSame(2, substr_count($marked, ScreenshotService::SECTION_MARKER_COLOUR));
        }

        // A class is a whole word: "hero" must not match "hero-image".
        $this->assertNull($this->service->markElement(
            '<html><body><div class="hero-image">x</div></body></html>',
            '.hero'
        ));
    }

    public function test_mark_element_reports_an_element_that_is_not_there(): void
    {
        // The caller measures fidelity; it has to tell "it looks wrong" from
        // "there was nothing to photograph".
        $this->assertNull($this->service->markElement(
            '<html><body><div data-vela-block="b1">x</div></body></html>',
            'b9'
        ));
    }

    public function test_section_markers_bound_the_element_in_a_capture(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd is required to build the test capture');
        }

        // A 60px-wide page: white, with the section's two 2px strips at rows
        // 10-11 and 40-41 — and the strips only over the left-hand column, the
        // case that a centre-of-the-page scan would miss.
        $image = imagecreatetruecolor(60, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        [$r, $g, $b] = sscanf(ScreenshotService::SECTION_MARKER_COLOUR, '#%02x%02x%02x');
        $marker = imagecolorallocate($image, $r, $g, $b);
        imagefilledrectangle($image, 0, 10, 20, 11, $marker);
        imagefilledrectangle($image, 0, 40, 20, 41, $marker);

        $find = new \ReflectionMethod(ScreenshotService::class, 'findSectionMarkers');
        $find->setAccessible(true);

        try {
            $this->assertSame([12, 40], $find->invoke($this->service, $image));
        } finally {
            imagedestroy($image);
        }
    }

    public function test_section_markers_are_absent_when_only_one_strip_was_drawn(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd is required to build the test capture');
        }

        $image = imagecreatetruecolor(60, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        [$r, $g, $b] = sscanf(ScreenshotService::SECTION_MARKER_COLOUR, '#%02x%02x%02x');
        imagefilledrectangle($image, 0, 10, 59, 11, imagecolorallocate($image, $r, $g, $b));

        $find = new \ReflectionMethod(ScreenshotService::class, 'findSectionMarkers');
        $find->setAccessible(true);

        try {
            $this->assertNull($find->invoke($this->service, $image));
        } finally {
            imagedestroy($image);
        }
    }
}
