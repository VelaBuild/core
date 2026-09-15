<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What an icon box block puts on the page, as the block editor saves it.
 */
class IconBoxBlockRenderTest extends PackageTestCase
{
    private function render(array $items, array $settings = []): string
    {
        $slug = 'icon-box-check-' . Page::count();
        $page = Page::create(['title' => 'Boxes', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'icon_box', 'content' => ['items' => $items], 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function box(string $title = 'Fast'): array
    {
        return ['icon' => 'fas fa-bolt', 'title' => $title, 'description' => 'Same day.'];
    }

    public function test_a_block_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $html = $this->render([$this->box(), $this->box(), $this->box()]);

        $this->assertStringContainsString('class="block-icon-boxes block-icon-boxes--plain icon-shape--none" style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;"', $html);
        $this->assertStringContainsString('<div class="icon-box icon-box--vertical">', $html);
        $this->assertStringContainsString('<i class="fas fa-bolt"></i>', $html);
    }

    public function test_never_more_columns_than_boxes(): void
    {
        $this->assertStringContainsString('repeat(2,1fr)', $this->render([$this->box(), $this->box()], ['columns' => 4]));
        $this->assertStringContainsString('repeat(4,1fr)', $this->render(array_fill(0, 5, $this->box()), ['columns' => 4]));
        $this->assertStringContainsString('repeat(6,1fr)', $this->render(array_fill(0, 8, $this->box()), ['columns' => 40]));
    }

    public function test_a_box_with_no_icon_and_no_title_takes_no_column(): void
    {
        $html = $this->render([$this->box(), ['icon' => '', 'title' => '', 'description' => 'orphan']], ['columns' => 3]);

        $this->assertStringContainsString('repeat(1,1fr)', $html);
        $this->assertStringNotContainsString('orphan', $html);
    }

    public function test_chosen_look_reaches_the_page(): void
    {
        $html = $this->render([$this->box()], [
            'layout' => 'horizontal', 'card_style' => 'soft', 'icon_shape' => 'circle', 'icon_color' => '#0f766e',
        ]);

        $this->assertStringContainsString('block-icon-boxes--soft icon-shape--circle', $html);
        $this->assertStringContainsString('--ib-icon:#0f766e;', $html);
        $this->assertStringContainsString('<div class="icon-box icon-box--horizontal">', $html);
    }

    public function test_values_the_view_does_not_know_fall_back_instead_of_reaching_the_markup(): void
    {
        $html = $this->render([$this->box()], [
            'layout' => 'diagonal', 'card_style' => '"><script>', 'icon_shape' => 'hexagon', 'icon_color' => 'red;background:url(x)',
        ]);

        $this->assertStringContainsString('class="block-icon-boxes block-icon-boxes--plain icon-shape--none"', $html);
        $this->assertStringContainsString('icon-box--vertical', $html);
        $this->assertStringNotContainsString('--ib-icon', $html);
    }

    public function test_a_palette_colour_follows_the_theme(): void
    {
        $html = $this->render([$this->box()], ['icon_color' => 'token:accent']);

        $this->assertMatchesRegularExpression('/--ib-icon:var\(--vela-[a-z-]+, [^)]+\);/', $html);
    }

    public function test_a_link_with_words_is_a_line_under_the_description(): void
    {
        $html = $this->render([$this->box() + ['link' => '/services/repairs', 'link_text' => 'Learn more']]);

        $this->assertStringContainsString('<a href="/services/repairs" class="icon-box-link">Learn more <span aria-hidden="true">&rarr;</span></a>', $html);
        $this->assertStringNotContainsString('icon-box--linked', $html);
    }

    public function test_a_link_without_words_makes_the_whole_box_the_link(): void
    {
        $html = $this->render([$this->box('Repairs') + ['link' => 'https://elsewhere.example/repairs', 'link_text' => '']]);

        $this->assertStringContainsString('<div class="icon-box icon-box--vertical icon-box--linked">', $html);
        $this->assertStringContainsString('<a href="https://elsewhere.example/repairs" class="icon-box-stretched" target="_blank" rel="noopener noreferrer">Repairs</a>', $html);
    }

    public function test_a_script_link_is_dropped(): void
    {
        $html = $this->render([$this->box('Repairs') + ['link' => ' JavaScript:alert(1)', 'link_text' => 'Go']]);

        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('icon-box-link', $html);
    }

    public function test_a_slider_moves_only_when_there_are_more_boxes_than_it_shows(): void
    {
        $html = $this->render(array_fill(0, 5, $this->box()), ['display' => 'slider', 'columns' => 3, 'autoplay' => true, 'interval' => 500]);

        $this->assertStringContainsString('block-icon-boxes--slider block-carousel block-carousel--slide block-carousel--multi', $html);
        $this->assertStringContainsString('data-vela-carousel data-autoplay="1" data-interval="2000" data-per-view="3"', $html);
        $this->assertSame(5, substr_count($html, 'class="carousel-slide'));
        $this->assertSame(3, preg_match_all('/class="carousel-dot(?: active)?"/', $html));
        $this->assertStringContainsString('vela-carousel.js', $html);

        $still = $this->render(array_fill(0, 3, $this->box()), ['display' => 'slider', 'columns' => 3, 'autoplay' => true]);
        $this->assertStringContainsString('data-autoplay="0"', $still);
        $this->assertStringNotContainsString('carousel-arrow', $still);
    }
}
