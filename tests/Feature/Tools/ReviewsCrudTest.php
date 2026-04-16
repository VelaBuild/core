<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use VelaBuild\Core\Models\Review;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ReviewsCrudTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_manual_review(): void
    {
        $this->loginAsAdmin();

        $response = $this->post(route('vela.admin.tools.reviews.store'), [
            'author' => 'John Doe',
            'rating' => 5,
            'text' => 'Great service!',
            'review_date' => '2026-01-15',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_reviews', [
            'author' => 'John Doe',
            'rating' => 5,
            'source' => 'manual',
        ]);
    }

    public function test_update_review_published_toggle(): void
    {
        $this->loginAsAdmin();

        $review = Review::create([
            'source' => 'manual',
            'author' => 'Jane',
            'rating' => 4,
            'published' => true,
        ]);

        $response = $this->put(route('vela.admin.tools.reviews.update', $review->id), [
            'published' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($review->fresh()->published);
    }

    public function test_delete_review(): void
    {
        $this->loginAsAdmin();

        $review = Review::create([
            'source' => 'manual',
            'author' => 'Bob',
            'rating' => 3,
        ]);

        $response = $this->delete(route('vela.admin.tools.reviews.destroy', $review->id));
        $response->assertRedirect();

        $this->assertSoftDeleted('vela_reviews', ['id' => $review->id]);
    }
}
