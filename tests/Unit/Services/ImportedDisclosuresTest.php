<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ImportedDisclosures;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A copied accordion arrives with its answers hidden and its script stripped,
 * so the questions sit there and clicking does nothing. These pin which pairs
 * get wired back up — and, just as importantly, which hidden things are left
 * alone, because opening a cookie banner or a mobile menu on a stray click is
 * worse than an accordion that does not move.
 */
class ImportedDisclosuresTest extends PackageTestCase
{
    /** The shape a Webflow-style FAQ arrives in: no aria, panel hidden inline. */
    private string $webflowFaq = '<div class="faq__row"><div>'
        . '<div class="faq__question">How fast will I receive my designs?</div>'
        . '<p style="display:none" class="faq__answer">On average, most requests are completed in two business days.</p>'
        . '</div><img class="faq__arrow" src="/chevron.svg" alt=""></div>';

    public function test_a_hidden_answer_after_a_question_becomes_a_toggle(): void
    {
        $out = ImportedDisclosures::wire($this->webflowFaq);

        $this->assertStringContainsString('data-vela-disclosure="', $out);
        $this->assertStringContainsString('data-vela-disclosure-panel', $out);
        $this->assertStringContainsString('aria-expanded="false"', $out);
        $this->assertStringContainsString('cursor:pointer', $out);
    }

    public function test_the_whole_row_is_clickable_not_just_the_words(): void
    {
        $out = ImportedDisclosures::wire($this->webflowFaq);

        // The chevron lives in the row, so wiring only the question text
        // leaves the arrow dead — which is where people aim.
        $this->assertMatchesRegularExpression('/<div class="faq__row"[^>]*data-vela-disclosure=/', $out);
    }

    public function test_every_row_of_a_repeated_faq_is_wired(): void
    {
        $out = ImportedDisclosures::wire('<div class="faq">' . str_repeat($this->webflowFaq, 6) . '</div>');

        $this->assertSame(6, substr_count($out, 'data-vela-disclosure="'));
        $this->assertSame(6, substr_count($out, 'data-vela-disclosure-panel'));
        // Each panel needs its own id for the trigger to point at.
        preg_match_all('/id="(vela-disclosure-[^"]+)"/', $out, $ids);
        $this->assertCount(6, array_unique($ids[1]));
    }

    public function test_an_aria_accordion_keeps_the_panel_the_source_left_open(): void
    {
        $out = ImportedDisclosures::wire(
            '<div><button aria-controls="p1" aria-expanded="true">Question one</button>'
            . '<div id="p1">The answer to the first question, spelled out.</div></div>'
        );

        $this->assertStringContainsString('data-vela-disclosure="p1"', $out);
        $this->assertStringContainsString('aria-expanded="true"', $out);
        $this->assertStringNotContainsString('display:none', $out);
    }

    public function test_a_native_details_accordion_is_left_alone(): void
    {
        $html = '<details><summary>Question</summary><p style="display:none">A hidden answer inside details.</p></details>';

        $this->assertSame($html, ImportedDisclosures::wire($html));
    }

    public function test_hidden_things_that_are_not_accordions_are_left_alone(): void
    {
        $cases = [
            'modal' => '<div><h2>Newsletter</h2><div class="modal" style="display:none">Sign up for our newsletter today.</div></div>',
            'menu' => '<div><span>Menu</span><div class="mobile-menu" style="display:none">Home About Pricing Contact Us</div></div>',
            'cookie' => '<div><span>Notice</span><div class="cookie-banner" style="display:none">We use cookies to improve your experience.</div></div>',
        ];

        foreach ($cases as $label => $html) {
            $this->assertSame($html, ImportedDisclosures::wire($html), $label . ' should not be wired');
        }
    }

    public function test_a_hidden_element_with_no_prose_is_not_an_answer(): void
    {
        $html = '<div><div class="label">Upload</div><div style="display:none"><img src="/x.png" alt=""></div></div>';

        $this->assertSame($html, ImportedDisclosures::wire($html));
    }

    public function test_markup_with_nothing_hidden_comes_back_untouched(): void
    {
        $html = '<section><h2>Pricing</h2><p>Everything you need, one price.</p></section>';

        $this->assertSame($html, ImportedDisclosures::wire($html));
    }

    public function test_an_existing_panel_id_is_reused_rather_than_replaced(): void
    {
        $out = ImportedDisclosures::wire(
            '<div class="faq__row"><div class="q">Question</div>'
            . '<p id="answer-7" style="display:none">The answer, long enough to count as prose.</p></div>'
        );

        $this->assertStringContainsString('data-vela-disclosure="answer-7"', $out);
        $this->assertStringNotContainsString('vela-disclosure-', explode('data-vela-disclosure="answer-7"', $out)[0]);
    }

    public function test_the_panels_display_is_forced_closed_before_the_first_click(): void
    {
        // The source hid it with a class this site never received, so the
        // stored markup shows every answer at once until something says so.
        $out = ImportedDisclosures::wire(
            '<div class="accordion-item"><button aria-controls="a1" aria-expanded="false">Q</button>'
            . '<div id="a1" style="color:red">An answer that the source hid with a class.</div></div>'
        );

        $this->assertStringContainsString('color:red;display:none;', $out);
        $this->assertStringContainsString('data-state="closed"', $out);
    }
}
