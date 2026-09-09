<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\MisreadWords;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A build reads its wording off a photograph of a design, and has put "Wluctn
 * znoe." onto a page and reported success: nothing in a build asks whether
 * what was read is language at all.
 *
 * The rules here answer that from the letters alone, so what matters is both
 * halves — that nonsense is caught, and that ordinary wording, an acronym, a
 * brand name, a compound word and a page written in another script are not.
 * A check that reports real words is worse than none: it sends a fix round
 * off to rewrite copy that was read correctly.
 */
class MisreadWordsTest extends PackageTestCase
{
    public function test_it_catches_letters_no_language_puts_in_that_order(): void
    {
        $this->assertSame(['Wluctn', 'znoe'], MisreadWords::in('Wluctn znoe.'));

        // No vowel at all, an opening no language has, five letters the same.
        $this->assertSame(['vtprs'], MisreadWords::in('vtprs'));
        $this->assertSame(['Klqwn'], MisreadWords::in('Klqwn'));
        $this->assertSame(['aaaargh'], MisreadWords::in('aaaargh'));

        // Six consonants running, behind an opening that is fine on its own.
        $this->assertSame(['bergschmnd'], MisreadWords::in('bergschmnd'));

        // Letters that only ever begin a word, standing as the whole of one.
        $this->assertSame(['schw'], MisreadWords::in('schw'));
    }

    public function test_it_leaves_ordinary_wording_alone(): void
    {
        $this->assertSame([], MisreadWords::in(
            'Real-Time Monitoring for your infrastructure. Get started with our pricing, '
            . 'read the documentation, or talk to us about what you need.'
        ));

        // Compound words are five consonants in a row all day long.
        $this->assertSame([], MisreadWords::in(
            'nightshade witchcraft birthplace yachtsman postscript matchstick heartthrob strengths'
        ));

        // An acronym is not spelled, it is said letter by letter.
        $this->assertSame([], MisreadWords::in('Our API is WCAG compliant. See the FAQ about SaaS plans.'));
    }

    public function test_it_leaves_a_page_written_in_another_script_alone(): void
    {
        $this->assertSame([], MisreadWords::in('สวัสดีครับ ยินดีต้อนรับสู่เว็บไซต์ของเรา 日本語のページ'));
    }

    public function test_a_name_the_brief_uses_is_not_a_misreading(): void
    {
        $this->assertSame(['mntn'], MisreadWords::in('Welcome to mntn'));

        $this->assertSame([], MisreadWords::in('Welcome to mntn', ['Build me a site for mntn, a hiking club.']));
    }

    public function test_it_reads_the_wording_and_not_the_markup(): void
    {
        $html = '<section class="fb-hero hdr wrap lg-pd"><h1>Pricing</h1>'
            . '<img src="/img/hh-bg-xl.jpg" alt="A phtograph of the shop">'
            . '<a href="https://znoe.example.com/wluctn">Read more</a></section>';

        // Class names are written short on purpose and an address is not
        // wording; alt text is read aloud, so it is.
        $this->assertSame(['phtograph'], MisreadWords::in(MisreadWords::visibleText($html)));
    }

    public function test_it_reads_a_tool_calls_wording_and_not_its_addresses(): void
    {
        $text = MisreadWords::inArguments([
            'name' => 'Hero',
            'slug' => 'hjklm-page',
            'css' => '.fb-hero{--bg:#fff}',
            'html' => '<h1>Wluctn</h1>',
            'items' => [['label' => 'Prcng znoe', 'url' => '/qwrtp']],
        ]);

        $this->assertSame(['Wluctn', 'Prcng', 'znoe'], MisreadWords::in($text));
    }
}
