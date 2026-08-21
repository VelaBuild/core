<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ImportedCarousels;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A copied slider keeps its slides, its arrows and its dots, and loses the
 * script that moved them — so one slide shows, the arrows do nothing, and the
 * mask hides the rest of the content outright. These pin what gets marked for
 * the footer's scroll-snap driver, and what is left as an ordinary grid.
 */
class ImportedCarouselsTest extends PackageTestCase
{
    private string $webflowSlider = '<div class="slider-resource w-slider">'
        . '<div class="mask-blog w-slider-mask">'
        . '<div class="blog-item w-slide">One</div>'
        . '<div class="blog-item w-slide">Two</div>'
        . '<div class="blog-item w-slide">Three</div>'
        . '</div>'
        . '<div class="left-arrow-2 w-slider-arrow-left"><div class="w-icon-slider-left"></div></div>'
        . '<div class="right-arrow-2 w-slider-arrow-right"><div class="w-icon-slider-right"></div></div>'
        . '<div class="slide-nav w-slider-nav w-round"></div>'
        . '</div>';

    public function test_the_mask_becomes_the_track_and_its_children_the_slides(): void
    {
        $out = ImportedCarousels::wire($this->webflowSlider);

        $this->assertSame(1, substr_count($out, 'data-vela-carousel-track='));
        $this->assertSame(3, substr_count($out, 'data-vela-slide='));
        // The mask, not the outer slider: the outer one holds the arrows too.
        $this->assertMatchesRegularExpression('/w-slider-mask[^>]*data-vela-carousel-track/', $out);
    }

    public function test_the_copied_arrows_are_pointed_at_that_track(): void
    {
        $out = ImportedCarousels::wire($this->webflowSlider);

        $this->assertSame(1, substr_count($out, 'data-vela-carousel-prev='));
        $this->assertSame(1, substr_count($out, 'data-vela-carousel-next='));
        $this->assertStringContainsString('aria-label="Previous slide"', $out);
        $this->assertStringContainsString('role="button"', $out);
    }

    public function test_an_empty_nav_is_filled_with_one_dot_per_slide(): void
    {
        $out = ImportedCarousels::wire($this->webflowSlider);

        $this->assertSame(1, substr_count($out, 'data-vela-carousel-dots='));
        $this->assertSame(3, substr_count($out, 'data-vela-carousel-dot='));
        $this->assertStringContainsString('aria-label="Go to slide 1"', $out);
    }

    public function test_the_sliders_own_chrome_is_never_read_as_a_slide(): void
    {
        // w-slider-mask, w-slider-arrow-left and w-slider-nav all contain the
        // word "slide"; matching on substrings turned them into the content.
        $out = ImportedCarousels::wire($this->webflowSlider);

        $this->assertStringNotContainsString('w-slider-mask" data-vela-slide', $out);
        $this->assertStringNotContainsString('w-slider-nav" data-vela-slide', $out);
    }

    public function test_a_swiper_export_is_wired_too(): void
    {
        $out = ImportedCarousels::wire(
            '<div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide">A</div><div class="swiper-slide">B</div>'
            . '</div><div class="swiper-button-next"></div></div>'
        );

        $this->assertSame(1, substr_count($out, 'data-vela-carousel-track='));
        $this->assertSame(2, substr_count($out, 'data-vela-slide='));
        $this->assertSame(1, substr_count($out, 'data-vela-carousel-next='));
    }

    public function test_a_lone_slide_is_not_a_carousel(): void
    {
        $html = '<div class="w-slider-mask"><div class="w-slide">Only one</div></div>';

        $this->assertSame($html, ImportedCarousels::wire($html));
    }

    public function test_an_ordinary_grid_is_left_as_a_grid(): void
    {
        $html = '<div class="cards"><div class="card">A</div><div class="card">B</div><div class="card">C</div></div>';

        $this->assertSame($html, ImportedCarousels::wire($html));
    }

    public function test_a_track_inside_a_wired_track_is_not_wired_again(): void
    {
        $out = ImportedCarousels::wire(
            '<div class="swiper-wrapper"><div class="swiper-slide"><div class="carousel-inner">'
            . '<div class="carousel-item">a</div><div class="carousel-item">b</div>'
            . '</div></div><div class="swiper-slide">B</div></div>'
        );

        $this->assertSame(1, substr_count($out, 'data-vela-carousel-track='));
    }

    public function test_markup_with_no_slider_comes_back_untouched(): void
    {
        $html = '<section><h2>Pricing</h2><p>One price, everything included.</p></section>';

        $this->assertSame($html, ImportedCarousels::wire($html));
    }
}
