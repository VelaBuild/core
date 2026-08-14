<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\AiChat\PageSectionExtractor;
use VelaBuild\Core\Tests\PackageTestCase;

class PageSectionExtractorTest extends PackageTestCase
{
    private function samplePage(): string
    {
        return <<<'HTML'
<!doctype html>
<html><head><title>Acme</title><style>.x{color:red}</style></head>
<body>
  <div class="page-wrapper">
    <header class="site-header"><nav><a href="/">Acme</a><a href="/pricing">Pricing</a></nav></header>
    <main>
      <section class="hero">
        <h1>Ship faster with Acme</h1>
        <p>The platform teams use to launch products in days rather than quarters.</p>
        <a class="btn btn-primary" href="/signup">Start free</a>
        <a class="btn" href="/demo">Book a demo</a>
        <img src="/img/hero.png" alt="Dashboard">
      </section>
      <section class="features">
        <h2>Why teams choose us</h2>
        <div class="grid">
          <div class="card"><h3>Fast</h3><p>Deploy in seconds.</p></div>
          <div class="card"><h3>Safe</h3><p>Backups on every write.</p></div>
          <div class="card"><h3>Simple</h3><p>No config to learn.</p></div>
        </div>
      </section>
      <section id="pricing">
        <h2>Pricing plans</h2>
        <div class="tiers">
          <div class="tier"><h3>Starter</h3><p>$9 per month</p></div>
          <div class="tier"><h3>Team</h3><p>$29 per month</p></div>
        </div>
      </section>
      <section class="contact"><h2>Talk to us</h2><form><input name="email"></form></section>
    </main>
    <footer class="site-footer"><p>© Acme 2026. All rights reserved.</p></footer>
  </div>
  <script>console.log('tracking')</script>
</body></html>
HTML;
    }

    public function test_reports_every_top_level_section_in_order(): void
    {
        $result = (new PageSectionExtractor())->extract($this->samplePage(), 'https://acme.test/');

        $headings = array_map(fn ($s) => $s['heading'] ?? null, $result['sections']);

        // The bare nav header carries too little to be a section of its own
        // and drops out; the footer stays, flagged as template furniture
        // rather than content to rebuild.
        $this->assertSame(5, $result['section_count']);
        $this->assertSame(
            ['Ship faster with Acme', 'Why teams choose us', 'Pricing plans', 'Talk to us', null],
            $headings
        );
        $this->assertStringStartsWith('skip', $result['sections'][4]['suggested_block']);
    }

    public function test_counts_repeated_cards(): void
    {
        $result = (new PageSectionExtractor())->extract($this->samplePage(), 'https://acme.test/');
        $features = $result['sections'][1];

        $this->assertSame(3, $features['repeated_items']['count']);
        $this->assertSame(['Fast', 'Safe', 'Simple'], $features['repeated_items']['titles']);
        $this->assertSame('icon_box', $features['suggested_block']);
    }

    public function test_suggests_blocks_from_section_shape(): void
    {
        $sections = (new PageSectionExtractor())->extract($this->samplePage(), 'https://acme.test/')['sections'];

        $this->assertSame('hero', $sections[0]['suggested_block']);
        $this->assertSame('pricing_tiers', $sections[2]['suggested_block']);
        $this->assertSame('contact_form', $sections[3]['suggested_block']);
    }

    public function test_captures_buttons_lead_text_and_absolute_image_urls(): void
    {
        $hero = (new PageSectionExtractor())->extract($this->samplePage(), 'https://acme.test/')['sections'][0];

        $this->assertSame(['Start free', 'Book a demo'], array_column($hero['buttons'], 'text'));
        $this->assertStringContainsString('launch products', $hero['lead_text']);
        $this->assertSame('https://acme.test/img/hero.png', $hero['images'][0]['src']);
    }

    public function test_ignores_scripts_and_styles(): void
    {
        $result = (new PageSectionExtractor())->extract($this->samplePage(), 'https://acme.test/');
        $everything = json_encode($result);

        $this->assertStringNotContainsString('console.log', $everything);
        $this->assertStringNotContainsString('color:red', $everything);
    }

    public function test_prefers_lazy_loading_source_over_placeholder(): void
    {
        $html = '<body><section><h2>Gallery</h2>'
            . '<img src="data:image/gif;base64,R0lGOD" data-src="/real/photo.jpg" alt="Photo">'
            . '<img srcset="/wide-800.jpg 800w, /wide-1600.jpg 1600w" alt="Wide">'
            . '</section></body>';

        $section = (new PageSectionExtractor())->extract($html, 'https://acme.test/page')['sections'][0];

        $this->assertSame('https://acme.test/real/photo.jpg', $section['images'][0]['src']);
        $this->assertSame('https://acme.test/wide-800.jpg', $section['images'][1]['src']);
    }

    public function test_keeps_non_ascii_headings_intact(): void
    {
        $html = '<body><section><h1>ยินดีต้อนรับสู่ Acme</h1><p>' . str_repeat('ทดสอบ ', 10) . '</p></section></body>';

        $section = (new PageSectionExtractor())->extract($html, 'https://acme.test/')['sections'][0];

        $this->assertSame('ยินดีต้อนรับสู่ Acme', $section['heading']);
    }

    public function test_returns_no_sections_for_empty_markup(): void
    {
        $this->assertSame([], (new PageSectionExtractor())->extract('', '')['sections']);
        $this->assertSame(0, (new PageSectionExtractor())->extract('<body></body>', '')['section_count']);
    }

    public function test_two_columns_of_one_row_stay_one_section(): void
    {
        // A "talk to sales" page is a headline beside a form. Split apart,
        // each column became a full-width row and the copy read as two
        // stacked blocks instead of the two-column section it really is.
        $html = '<body><div class="app"><main><div class="grid grid-cols-12">'
            . '<div class="col-span-6"><h1>Talk to our Sales team</h1><p>' . str_repeat('Get a custom demo. ', 4) . '</p></div>'
            . '<div class="col-start-7 col-end-13"><form><label>Full name</label><input name="name"></form></div>'
            . '</div></main></div></body>';

        $result = (new PageSectionExtractor())->extract($html, 'https://acme.example/contact');

        $this->assertSame(1, $result['section_count']);
        $this->assertStringContainsString('Talk to our Sales team', $result['sections'][0]['text']);
        $this->assertTrue($result['sections'][0]['has_form']);
    }

    public function test_ordinary_sections_are_still_split_apart(): void
    {
        $html = '<body><div class="app"><main>'
            . '<div class="hero"><h1>Ship faster</h1><p>' . str_repeat('Launch in days. ', 4) . '</p></div>'
            . '<div class="features"><h2>Why us</h2><p>' . str_repeat('Deploy in seconds. ', 4) . '</p></div>'
            . '</main></div></body>';

        $result = (new PageSectionExtractor())->extract($html, 'https://acme.example/');

        $this->assertSame(2, $result['section_count']);
    }
}
