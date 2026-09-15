<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a call to action block puts on the page.
 */
class CtaBlockRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    private function render(array $content, array $settings = []): string
    {
        $slug = 'cta-check-' . uniqid();
        $page = Page::create(['title' => 'Ask', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $page->rows()->create(['name' => 'Body', 'width' => 'full', 'order_column' => 0])->blocks()->create([
            'type' => 'cta', 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function section(string $html): string
    {
        $start = strpos($html, '<div class="block-cta');

        return $start === false ? '' : substr($html, $start, 3000);
    }

    public function test_the_heading_keeps_emphasis_and_nothing_else(): void
    {
        preg_match('#<h2 class="block-cta-heading">.*?</h2>#s', $this->render(['heading' => 'Ready to <em onmouseover="alert(1)">dive</em>? <strong>Book</strong> <script>alert(2)</script><img src=x onerror=alert(3)>']), $m);
        $html = $m[0] ?? '';

        $this->assertStringContainsString('Ready to <em>dive</em>? <strong>Book</strong>', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_a_call_to_action_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $html = $this->render(['heading' => 'Want to See More?', 'primary_button_text' => 'All Articles', 'primary_button_url' => '/posts', 'note' => 'Free, always.'], ['text_alignment' => 'center']);

        $this->assertStringContainsString('<div class="block-cta block-cta--stacked block-cta--normal block-cta--align-center block-cta--btn-solid" style="text-align:center;">', $html);
        $this->assertStringContainsString('<div class="block-cta-actions" style="justify-content:center;">', $html);
        $this->assertStringContainsString('<div class="block-cta-note">Free, always.</div>', $html);
    }

    public function test_css_in_a_setting_does_not_reach_the_style(): void
    {
        $html = $this->section($this->render(['heading' => 'Hi'], [
            'text_alignment' => 'left;position:fixed', 'background' => 'red;position:fixed;inset:0', 'button_color' => 'url(x)', 'layout' => 'grid', 'size' => '"><x',
        ]));
        preg_match('/<div class="block-cta[^>]*>/', $html, $tag);

        $this->assertStringNotContainsString('position:fixed', $tag[0]);
        $this->assertStringNotContainsString('url(', $tag[0]);
        $this->assertStringContainsString('class="block-cta block-cta--stacked block-cta--normal block-cta--align-center block-cta--btn-solid" style="text-align:center;"', $tag[0]);
    }

    public function test_a_chosen_background_and_button_carry_readable_ink(): void
    {
        $html = $this->section($this->render(
            ['heading' => 'Hi', 'primary_button_text' => 'Go', 'secondary_button_text' => 'Later'],
            ['layout' => 'split', 'size' => 'large', 'text_alignment' => 'left', 'background' => '#0f172a', 'button_style' => 'pill', 'button_color' => '#fde047']
        ));
        preg_match('/<div class="block-cta[^>]*>/', $html, $tag);

        $this->assertStringContainsString('block-cta--split block-cta--large block-cta--align-left block-cta--btn-pill has-cta-bg has-btn-color', $tag[0]);
        $this->assertStringContainsString('--cta-bg:#0f172a;--cta-ink:#ffffff;--cta-btn-bg:#fde047;--cta-btn-ink:#111827;', $tag[0]);
        $this->assertStringContainsString('<div class="block-cta-actions" style="justify-content:flex-start;">', $html);
    }

    public function test_a_theme_colour_background_follows_the_theme(): void
    {
        $html = $this->section($this->render(['heading' => 'Hi'], ['background' => 'token:band', 'layout' => 'card']));

        $this->assertMatchesRegularExpression('/--cta-bg:var\(--vela-band, #1a1a1a\);--cta-ink:var\(--vela-band-ink, #ffffff\);/', $html);
        $this->assertStringContainsString('block-cta--card', $html);

        // On the accent, the accent button would vanish; the class that swaps it is there.
        $this->assertStringContainsString('has-cta-bg is-accent-bg', $this->section($this->render(['heading' => 'Hi'], ['background' => 'token:accent'])));
    }
}
