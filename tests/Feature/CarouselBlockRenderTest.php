<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a carousel block puts on the page, from its slides and settings.
 */
class CarouselBlockRenderTest extends PackageTestCase
{
    private function render(array $slides, array $settings = []): string
    {
        $slug = 'carousel-check-' . Page::count();
        $page = Page::create(['title' => 'Carousel', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'carousel', 'content' => ['slides' => $slides], 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function pictures(int $n): array
    {
        return array_map(fn ($i) => ['image_url' => "https://example.com/{$i}.jpg", 'caption' => "Picture {$i}"], range(1, $n));
    }

    public function test_a_block_saved_before_the_new_settings_still_slides_one_at_a_time(): void
    {
        $html = $this->render($this->pictures(3), ['autoplay' => true, 'interval' => 4000]);

        $this->assertStringContainsString('block-carousel block-carousel--slide"', $html);
        $this->assertStringContainsString('data-per-view="1"', $html);
        $this->assertStringContainsString('data-interval="4000"', $html);
        $this->assertStringContainsString('vela-carousel.js', $html);
        // The caption still shows when a slide has nothing else to say.
        $this->assertStringContainsString('<div class="carousel-caption">Picture 1</div>', $html);
        // No Alpine: it is what hid slides with display:none, so nothing ever moved.
        $this->assertStringNotContainsString('x-data', $html);
    }

    public function test_words_and_a_button_go_on_the_picture(): void
    {
        $html = $this->render([[
            'image_url' => 'https://example.com/hero.jpg',
            'heading' => 'Summer menu', 'text' => "Fresh\nevery day", 'button_label' => 'See the menu', 'button_url' => '/menu',
            'caption' => 'not shown',
        ]], ['text_position' => 'bottom-left']);

        $this->assertStringContainsString('carousel-slide is-active has-overlay', $html);
        $this->assertStringContainsString('carousel-content--overlay carousel-content--bottom-left', $html);
        $this->assertStringContainsString('<h2 class="carousel-heading">Summer menu</h2>', $html);
        $this->assertStringContainsString('Fresh<br />', $html);
        $this->assertStringContainsString('<a href="/menu" class="carousel-button">See the menu</a>', $html);
        $this->assertStringNotContainsString('not shown', $html);
    }

    public function test_several_at_a_time_put_the_words_under_the_pictures_and_cannot_fade(): void
    {
        $slides = $this->pictures(5);
        $slides[0]['heading'] = 'Card one';

        $html = $this->render($slides, ['per_view' => 3, 'effect' => 'fade', 'ratio' => '4:5']);

        $this->assertStringContainsString('block-carousel--slide block-carousel--multi block-carousel--fixed-ratio', $html);
        $this->assertStringContainsString('--carousel-per-view: 3; --carousel-ratio: 4 / 5;', $html);
        $this->assertStringContainsString('carousel-content--below', $html);
        // Five pictures three at a time can start at 1, 2 or 3.
        $this->assertSame(3, preg_match_all('/class="carousel-dot( active)?"/', $html));
    }

    public function test_one_picture_has_nothing_to_move_between(): void
    {
        $html = $this->render($this->pictures(1));

        $this->assertStringNotContainsString('class="carousel-arrow', $html);
        $this->assertStringNotContainsString('class="carousel-dot', $html);
        $this->assertStringContainsString('data-autoplay="0"', $html);
    }

    public function test_settings_out_of_range_are_held_to_what_the_view_can_draw(): void
    {
        $html = $this->render($this->pictures(2), ['per_view' => 12, 'ratio' => '7:3', 'interval' => 50, 'text_position' => '"><script>']);

        $this->assertStringContainsString('data-per-view="4"', $html);
        $this->assertStringNotContainsString('--carousel-ratio', $html);
        $this->assertStringContainsString('data-interval="1500"', $html);
        $this->assertStringNotContainsString('"><script>', $html);
    }

    public function test_slides_with_nothing_in_them_are_left_out(): void
    {
        $html = $this->render([['image_url' => ''], ['image_url' => 'https://example.com/a.jpg']]);

        $this->assertSame(1, substr_count($html, 'aria-roledescription="slide"'));
    }
}
