<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\Blocks\Hero;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a hero block puts on the page, as its editor saves it and as heroes
 * saved before the editor had choices still are.
 */
class HeroBlockRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    /** @param array<int, array{content?: array, settings?: array, background_image?: string}> $heroes */
    private function render(array ...$heroes): string
    {
        $slug = 'hero-check-' . uniqid();
        $page = Page::create(['title' => 'Home', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        foreach ($heroes as $i => $hero) {
            $page->rows()->create(['name' => "Row {$i}", 'width' => 'full', 'order_column' => $i])->blocks()->create([
                'type' => 'hero',
                'content' => ($hero['content'] ?? []) + ['title' => 'Welcome ' . $i],
                'settings' => $hero['settings'] ?? [],
                'background_image' => $hero['background_image'] ?? null,
                'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
            ]);
        }

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function heroTag(string $html): string
    {
        preg_match('/<div class="block-hero[^"]*"[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    public function test_a_hero_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $html = $this->render(['settings' => ['background_overlay' => 'rgba(0,0,0,0.55)', 'text_alignment' => 'center', 'min_height' => '60vh']]);

        $this->assertStringContainsString('class="block-hero block-hero--x-center block-hero--y-center block-hero--ink-theme block-hero--btn-solid" style="text-align:center;min-height:60vh;"', $html);
        $this->assertStringContainsString('<div class="block-hero-overlay" style="background:rgba(0,0,0,0.55);"></div>', $html);
        $this->assertStringContainsString('<h1 class="block-hero-title">Welcome 0</h1>', $html);
    }

    public function test_css_typed_into_height_or_overlay_does_not_reach_the_style(): void
    {
        $html = $this->render(['settings' => [
            'min_height' => '80vh;background:url(https://evil.example/x.png)',
            'background_overlay' => 'red;position:fixed;inset:0',
            'button_color' => 'red;display:none',
            'text_alignment' => 'justify;color:red',
        ]]);

        preg_match('/<div class="block-hero-overlay"[^>]*>/', $html, $overlay);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('position:fixed', $overlay[0] ?? '');
        $this->assertStringNotContainsString('display:none', $this->heroTag($html));
        $this->assertStringContainsString('style="text-align:center;min-height:80vh;"', $html);
        $this->assertStringContainsString('background:rgba(0,0,0,0.4);', $html);
    }

    public function test_an_ai_written_gradient_overlay_is_kept(): void
    {
        $html = $this->render(['settings' => ['background_overlay' => 'linear-gradient(90deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0) 60%)']]);

        $this->assertStringContainsString('background:linear-gradient(90deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0) 60%);', $html);
    }

    public function test_the_first_hero_is_the_page_heading_and_the_rest_are_sections(): void
    {
        $html = $this->render([], [], ['settings' => ['heading_level' => 'h1']]);

        $this->assertStringContainsString('<h1 class="block-hero-title">Welcome 0</h1>', $html);
        $this->assertStringContainsString('<h2 class="block-hero-title">Welcome 1</h2>', $html);
        $this->assertStringContainsString('<h1 class="block-hero-title">Welcome 2</h1>', $html);
    }

    public function test_the_picture_is_drawn_by_the_hero_on_its_focal_point_and_not_twice(): void
    {
        $html = $this->render([
            'background_image' => 'https://elsewhere.example/reef.jpg',
            'settings' => ['overlay_style' => 'gradient_left', 'focal_x' => 30, 'focal_y' => 75, 'mobile_background_image' => 'https://elsewhere.example/reef-tall.jpg'],
        ]);

        $this->assertStringContainsString('<picture class="block-hero-media">', $html);
        $this->assertStringContainsString('<source media="(max-width: 640px)"', $html);
        $this->assertMatchesRegularExpression('/<img src="https:\/\/elsewhere\.example\/reef\.jpg"[^>]*loading="eager" fetchpriority="high"[^>]*class="block-hero-media-img"[^>]*style="object-position:30% 75%"/', $html);
        // Not also the wrapper's CSS background.
        $this->assertStringNotContainsString('background-image:url(', $html);
        $this->assertStringContainsString('block-hero--ink-light', $html);
    }

    public function test_automatic_words_colour_reads_what_is_behind_them(): void
    {
        // No picture, a light shade: the theme's ink, not white on near-white.
        $this->assertStringContainsString('block-hero--ink-dark', $this->heroTag($this->render(['settings' => ['overlay_style' => 'light']])));
        $this->assertStringContainsString('block-hero--ink-light', $this->heroTag($this->render(['settings' => ['overlay_style' => 'dark']])));
        $this->assertStringContainsString('block-hero--ink-light', $this->heroTag($this->render(['settings' => ['overlay_style' => 'gradient_left']])));
        $this->assertStringContainsString('block-hero--ink-dark', $this->heroTag($this->render(['settings' => ['overlay_style' => 'dark', 'text_color_mode' => 'dark']])));
    }

    public function test_position_eyebrow_and_buttons(): void
    {
        $html = $this->render([
            'content' => ['eyebrow' => 'Since 2010', 'primary_button_text' => 'Book', 'primary_button_url' => '/book'],
            'settings' => ['overlay_style' => 'none', 'text_alignment' => 'left', 'vertical_align' => 'bottom', 'min_height' => 'auto', 'button_style' => 'pill', 'button_color' => '#fde047'],
        ]);
        $tag = $this->heroTag($html);

        $this->assertStringContainsString('block-hero--x-left block-hero--y-bottom', $tag);
        $this->assertStringContainsString('block-hero--btn-pill', $tag);
        $this->assertStringContainsString('has-btn-color', $tag);
        // A yellow button gets dark words.
        $this->assertStringContainsString('--hero-btn-bg:#fde047;--hero-btn-ink:#111827;', $tag);
        $this->assertStringNotContainsString('min-height', $tag);
        $this->assertStringNotContainsString('block-hero-overlay', $html);
        $this->assertStringContainsString('<p class="block-hero-eyebrow">Since 2010</p>', $html);
        $this->assertStringContainsString('style="justify-content:flex-start;"', $html);
    }

    public function test_settings_fall_back_to_what_the_view_knows(): void
    {
        $s = Hero::settings(['min_height' => '9999999px', 'focal_x' => 250, 'focal_y' => 'top', 'overlay_style' => 'sepia', 'heading_level' => 'h6']);

        $this->assertSame(['80vh', 100, 50, null, 'auto'], [$s['min_height'], $s['focal_x'], $s['focal_y'], $s['overlay_style'], $s['heading_level']]);
        $this->assertSame('600px', Hero::height('600px'));
    }
}
