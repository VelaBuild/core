<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use VelaBuild\Core\Models\Review;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ReviewBlocksTest extends TestCase
{
    use DatabaseTransactions;

    public function test_review_summary_block_only_shows_published(): void
    {
        // Create published and unpublished reviews
        Review::create(['source' => 'manual', 'author' => 'A', 'rating' => 5, 'published' => true]);
        Review::create(['source' => 'manual', 'author' => 'B', 'rating' => 1, 'published' => false]);

        $block = (object) ['content' => [], 'settings' => []];
        $view = view('vela::public.pages.blocks.review-summary', ['block' => $block])->render();

        $this->assertStringContainsString('5.0', $view); // Only published 5-star
        $this->assertStringContainsString('based on 1 review', $view); // The unpublished one is not counted
        $this->assertStringContainsString('review-stars', $view);
    }

    public function test_review_grid_respects_max_count(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Review::create(['source' => 'manual', 'author' => "Author {$i}", 'rating' => 4, 'published' => true]);
        }

        $block = (object) ['content' => [], 'settings' => ['max_count' => 3, 'columns' => 3]];
        $view = view('vela::public.pages.blocks.review-grid', ['block' => $block])->render();

        // Should only show 3 reviews. The class name alone appears on each
        // card's parts too, so count the cards themselves.
        $this->assertEquals(3, substr_count($view, 'class="review-card"'));
    }
}
