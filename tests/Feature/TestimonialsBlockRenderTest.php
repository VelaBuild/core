<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A testimonials block shows its quotes whichever key they were saved under.
 *
 * The page reads `items`, as the registry declares; the block editor saved
 * `testimonials`, so every quote added through its dialog saved and did not
 * appear.
 */
class TestimonialsBlockRenderTest extends PackageTestCase
{
    private function render(array $content, array $settings = ['layout' => 'cards']): string
    {
        $slug = 'testimonials-check-' . Page::count();
        $page = Page::create(['title' => 'Quotes', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'testimonials', 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    public function test_quotes_saved_as_items_show(): void
    {
        $html = $this->render(['items' => [['quote' => 'Fixed it the same day.', 'name' => 'Jane Doe', 'title' => 'Homeowner']]]);

        $this->assertStringContainsString('Fixed it the same day.', $html);
        $this->assertStringContainsString('Jane Doe', $html);
    }

    public function test_quotes_saved_by_the_old_editor_under_testimonials_still_show(): void
    {
        $html = $this->render(['testimonials' => [['quote' => 'Best burger on the island.', 'name' => 'Sam', 'title' => '']]]);

        $this->assertStringContainsString('Best burger on the island.', $html);
        $this->assertStringContainsString('class="block-testimonials block-testimonials--grid"', $html);
    }

    private function quotes(int $n): array
    {
        return ['items' => array_map(fn ($i) => ['quote' => "Quote {$i}", 'name' => "Person {$i}", 'title' => ''], range(1, $n))];
    }

    public function test_cards_saved_before_the_slider_existed_stay_side_by_side(): void
    {
        $html = $this->render($this->quotes(3), ['layout' => 'cards']);

        $this->assertStringContainsString('class="block-testimonials block-testimonials--grid"', $html);
        $this->assertStringNotContainsString('data-vela-carousel data-autoplay', $html);
    }

    public function test_a_slider_moves_through_the_quotes_with_the_carousel(): void
    {
        $html = $this->render($this->quotes(5), ['layout' => 'slider', 'per_view' => 3, 'interval' => 4000]);

        $this->assertStringContainsString('block-testimonials--slider block-carousel block-carousel--slide block-carousel--multi', $html);
        $this->assertStringContainsString('data-per-view="3"', $html);
        $this->assertStringContainsString('data-interval="4000"', $html);
        $this->assertStringContainsString('vela-carousel.js', $html);
        $this->assertSame(5, substr_count($html, 'aria-roledescription="slide"'));
        // Five quotes three at a time can start at 1, 2 or 3.
        $this->assertSame(3, preg_match_all('/class="carousel-dot( active)?"/', $html));
    }

    public function test_one_at_a_time_is_a_single_centred_quote(): void
    {
        $html = $this->render($this->quotes(2), ['layout' => 'slider', 'per_view' => 1]);

        $this->assertStringContainsString('block-testimonials--single', $html);
        $this->assertStringContainsString('class="carousel-arrow carousel-prev"', $html);
    }

    public function test_a_slider_with_nothing_to_move_to_does_not_pretend_to(): void
    {
        $html = $this->render($this->quotes(2), ['layout' => 'slider', 'per_view' => 3, 'autoplay' => true]);

        $this->assertStringContainsString('data-autoplay="0"', $html);
        $this->assertStringNotContainsString('class="carousel-arrow', $html);
        $this->assertStringNotContainsString('class="carousel-dot', $html);
    }
}
