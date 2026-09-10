<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\DesignTokens;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A row's colour is stored either as a name from the theme's palette or as a
 * literal the author insisted on. Both have to survive: the first because it
 * is the whole point, the second because pages saved before it existed are
 * full of them and must keep rendering exactly as they did.
 */
class DesignTokensTest extends PackageTestCase
{
    public function test_a_palette_name_resolves_to_the_theme_property(): void
    {
        $this->assertSame('var(--vela-surface, #f9fafb)', DesignTokens::colour('token:surface'));
        $this->assertSame('var(--vela-primary, #2563eb)', DesignTokens::colour('token:accent'));
    }

    public function test_a_name_that_is_not_in_the_palette_is_dropped(): void
    {
        // Rather than emitting `var(--vela-whatever)`, which paints nothing
        // and leaves no trace of why.
        $this->assertNull(DesignTokens::colour('token:whatever'));
    }

    public function test_colours_saved_before_the_palette_existed_still_work(): void
    {
        foreach (['#fff', '#ffffff', '#ffffffcc', 'rebeccapurple', 'rgba(0, 0, 0, 0.5)'] as $literal) {
            $this->assertSame($literal, DesignTokens::colour($literal), $literal . ' stopped rendering.');
        }
    }

    public function test_a_value_that_is_not_a_colour_never_reaches_the_style_attribute(): void
    {
        // These are written into `style="..."`. Blade escapes the quotes, so
        // the attribute cannot be closed, but a value can still carry its own
        // declarations into the same rule — and a row is a full-width element.
        $this->assertNull(DesignTokens::colour('red; position: fixed; inset: 0; z-index: 9999'));
        $this->assertNull(DesignTokens::colour('url(https://example.com/x.png)'));
        $this->assertNull(DesignTokens::colour('expression(alert(1))'));
    }

    public function test_spacing_keeps_zero_and_rejects_the_rest(): void
    {
        // "0" is a real answer to "how much space", and a truthiness check
        // reads it as false — which is how choosing None in the row style
        // used to leave the template's 20px exactly where it was.
        $this->assertSame('0', DesignTokens::spacing('0'));
        $this->assertSame('40px', DesignTokens::spacing('40px'));
        $this->assertSame('2rem 0', DesignTokens::spacing('2rem 0'));
        $this->assertNull(DesignTokens::spacing(''));
        $this->assertNull(DesignTokens::spacing('40px; display: none'));
        $this->assertNull(DesignTokens::spacing('calc(100% - 4px)'));
    }

    public function test_alignment_is_one_of_four_words(): void
    {
        $this->assertSame('center', DesignTokens::alignment('center'));
        $this->assertSame('right', DesignTokens::alignment(' RIGHT '));
        $this->assertNull(DesignTokens::alignment('center; background: url(x)'));
    }

    public function test_the_editor_is_told_which_picker_offers_each_colour(): void
    {
        $roles = array_column(DesignTokens::paletteForEditor(), 'role', 'value');

        $this->assertSame('text', $roles['token:muted'], 'Muted text is not a background.');
        $this->assertSame('bg', $roles['token:surface'], 'A surface is not a text colour.');
        $this->assertSame('both', $roles['token:accent']);
    }
}
