<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ImportedAnimations;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Builders write the first frame of a scroll animation into the markup and
 * animate out of it in script. With the script stripped on import that frame
 * is permanent, so copied sections arrive with invisible headings and cards
 * sitting 130px below where they belong — the section looks half-copied.
 */
class ImportedAnimationsTest extends PackageTestCase
{
    public function test_an_element_left_at_zero_opacity_becomes_visible(): void
    {
        $out = ImportedAnimations::settle('<div style="opacity:0" data-w-id="x">Heading</div>');

        $this->assertStringNotContainsString('opacity:0', $out);
        $this->assertStringContainsString('Heading', $out);
    }

    public function test_a_builders_staged_transform_is_dropped(): void
    {
        // The vendor-prefixed set is the signature of a staged first frame.
        $style = '-webkit-transform:translate3d(0, 130px, 0);-moz-transform:translate3d(0, 130px, 0);'
            . '-ms-transform:translate3d(0, 130px, 0);transform:translate3d(0, 130px, 0)';

        $out = ImportedAnimations::settle('<img style="' . $style . '" src="/logo.png" alt="">');

        $this->assertStringNotContainsString('translate3d', $out);
        $this->assertStringContainsString('src="/logo.png"', $out);
    }

    public function test_a_deliberate_single_transform_is_left_alone(): void
    {
        $html = '<div style="transform:rotate(-3deg)">Tilted card</div>';

        $this->assertSame($html, ImportedAnimations::settle($html));
    }

    public function test_other_declarations_on_the_same_element_survive(): void
    {
        $out = ImportedAnimations::settle('<div style="color:red;opacity:0;margin-top:10px">Hi</div>');

        $this->assertStringContainsString('color:red', $out);
        $this->assertStringContainsString('margin-top:10px', $out);
        $this->assertStringNotContainsString('opacity:0', $out);
    }

    public function test_a_visible_opacity_is_not_touched(): void
    {
        $html = '<div style="opacity:0.5">Half there on purpose</div>';

        $this->assertSame($html, ImportedAnimations::settle($html));
    }

    public function test_an_element_whose_only_style_was_the_first_frame_loses_the_attribute(): void
    {
        $out = ImportedAnimations::settle('<div style="opacity:0">Text</div>');

        $this->assertStringNotContainsString('style=', $out);
    }

    public function test_markup_with_no_inline_animation_state_comes_back_untouched(): void
    {
        $html = '<section><h2 class="title">Pricing</h2><p>One flat fee.</p></section>';

        $this->assertSame($html, ImportedAnimations::settle($html));
    }
}
