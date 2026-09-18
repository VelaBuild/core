<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Review;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What the three review blocks put on the page. Their stars used to be Font
 * Awesome icons in Bootstrap colour classes, and no public layout loads
 * either: five blank gaps, and no way to tell an earned star from an empty one.
 */
class ReviewBlocksRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    private function review(int $rating, array $attributes = []): Review
    {
        return Review::create(array_merge([
            'source'      => 'google',
            'author'      => 'Visitor ' . $rating,
            'rating'      => $rating,
            'text'        => 'A review worth ' . $rating,
            'review_date' => now()->subDays($rating),
            'published'   => true,
        ], $attributes));
    }

    private function render(string $type, array $settings = [], array $content = []): string
    {
        $slug = 'review-check-' . uniqid();
        $page = Page::create(['title' => 'Reviews', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0])->blocks()->create([
            'type' => $type, 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    public function test_the_stars_are_drawn_rather_than_asked_of_an_icon_font(): void
    {
        $this->review(5);
        $this->review(4);

        $html = $this->render('review-summary');

        // 4.5: four full stars and a half, not five.
        $this->assertSame(4, substr_count($html, 'review-star--full'));
        $this->assertSame(1, substr_count($html, 'review-star--half'));
        $this->assertStringContainsString('<svg class="review-star', $html);
        $this->assertStringNotContainsString('fas fa-star', $html);
        $this->assertStringNotContainsString('text-warning', $html);
        $this->assertStringContainsString('4.5', $html);
    }

    public function test_the_summary_counts_only_the_reviews_it_was_told_to(): void
    {
        $this->review(5);
        $this->review(5);
        $this->review(2);

        $all = $this->render('review-summary', ['min_rating' => 1]);
        $this->assertStringContainsString('based on 3 reviews', $all);

        $good = $this->render('review-summary', ['min_rating' => 4]);
        $this->assertStringContainsString('based on 2 reviews', $good);
        $this->assertStringContainsString('5.0', $good);
    }

    public function test_the_summary_carries_the_rating_a_search_engine_can_show(): void
    {
        $this->review(5);
        $this->review(4);

        $html = $this->render('review-summary');

        $this->assertStringContainsString('"@type":"AggregateRating"', $html);
        $this->assertStringContainsString('"ratingValue":4.5', $html);
        $this->assertStringContainsString('"reviewCount":2', $html);
    }

    public function test_a_setting_that_is_not_one_of_the_choices_is_dropped(): void
    {
        $this->review(5);

        $html = $this->render('review-summary', [
            'min_rating' => 99, 'layout' => 'position:fixed', 'text_alignment' => 'center;inset:0',
            'background' => 'red;position:fixed', 'button_style' => ['x'],
        ]);
        preg_match('/<div class="block-review-summary[^>]*>/', $html, $tag);

        $this->assertStringNotContainsString('position:fixed', $tag[0]);
        $this->assertStringContainsString('class="block-review-summary block-review-summary--align-center block-review-summary--row block-review-summary--btn-solid" style="text-align:center;"', $tag[0]);
        // A rating of 99 would leave nothing; it is held to five.
        $this->assertStringContainsString('based on 1 review', $html);
    }

    public function test_the_words_and_button_a_summary_was_given(): void
    {
        $this->review(5);

        $html = $this->render('review-summary', ['layout' => 'large', 'background' => '#0f172a', 'show_count' => false], [
            'heading' => 'What our guests say', 'note' => 'Every one of them verified.',
            'button_text' => 'Read them all', 'button_url' => '/reviews',
        ]);

        $this->assertStringContainsString('<h2 class="block-review-summary-heading">What our guests say</h2>', $html);
        $this->assertStringContainsString('Every one of them verified.', $html);
        $this->assertStringContainsString('<a href="/reviews" class="block-review-summary-btn">Read them all</a>', $html);
        $this->assertStringContainsString('--review-bg:#0f172a;--review-ink:#ffffff;', $html);
        $this->assertStringNotContainsString('based on', $html);
    }

    public function test_a_block_with_no_reviews_says_so_instead_of_vanishing(): void
    {
        $html = $this->render('review-summary');

        $this->assertStringContainsString('block-empty-state', $html);
        $this->assertStringNotContainsString('block-review-summary', $html);
    }

    public function test_the_grid_holds_its_columns_and_the_cards_carry_their_dates(): void
    {
        $this->review(5);
        $this->review(4);

        $html = $this->render('review-grid', ['columns' => 9, 'card_style' => 'soft', 'show_source' => true]);

        // Nine columns is not one of the choices; four is the most there is.
        $this->assertStringContainsString('--review-columns:4;', $html);
        $this->assertStringContainsString('block-review-grid--soft', $html);
        $this->assertSame(2, substr_count($html, 'class="review-card"'));
        $this->assertStringContainsString('<time datetime="', $html);
        $this->assertStringContainsString('Google', $html);
    }

    public function test_the_carousel_shows_the_newest_first_and_can_be_reached_from_a_keyboard(): void
    {
        $this->review(3, ['author' => 'Older', 'review_date' => now()->subYear()]);
        $this->review(5, ['author' => 'Newer', 'review_date' => now()]);

        $html = $this->render('review-carousel', ['max_count' => 1]);

        $this->assertStringContainsString('Newer', $html);
        $this->assertStringNotContainsString('Older', $html);
        $this->assertStringContainsString('<div class="block-review-carousel-strip" tabindex="0" role="region"', $html);
    }
}
