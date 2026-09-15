<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\Blocks\PostsGrid;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a posts grid block puts on the page, and what its editor preview is told.
 */
class PostsGridBlockRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A public page request imports posts written out to config files by
        // earlier tests, and this block lists every post; the import's daily
        // lock keeps them out.
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    private function render(array $settings = []): string
    {
        $slug = 'posts-check-' . uniqid();
        $page = Page::create(['title' => 'News', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0])->blocks()->create([
            'type' => 'posts_grid', 'content' => [], 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function article(string $title, int $daysAgo, array $extra = []): Content
    {
        return Content::create($extra + [
            'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title) . '-' . uniqid(), 'type' => 'post',
            'status' => 'published', 'author_id' => 1, 'written_at' => now(),
            'published_at' => now()->subDays($daysAgo), 'description' => "About {$title}.",
        ]);
    }

    private function titles(string $html): array
    {
        preg_match_all('/<h2 class="post-card-title">([^<]+)<\/h2>/', $html, $m);

        return $m[1];
    }

    public function test_a_block_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $this->article('Older', 3);
        $this->article('Newer', 1);

        $html = $this->render(['columns' => 3, 'max_count' => 12, 'category_id' => '', 'order_by' => 'newest', 'show_excerpt' => true]);

        $this->assertStringContainsString('class="block-posts-grid block-posts-grid--grid block-posts-grid--bordered block-posts-grid--ratio-auto block-posts-grid--multi" style="grid-template-columns:repeat(2,minmax(0,1fr));"', $html);
        $this->assertSame(['Newer', 'Older'], $this->titles($html));
        $this->assertStringContainsString('<p class="post-card-excerpt">About Newer.</p>', $html);
        $this->assertStringContainsString('<small class="post-card-meta">' . now()->subDay()->format('M j, Y') . '</small>', $html);
    }

    public function test_the_single_category_filter_it_had_still_filters(): void
    {
        $diving = Category::create(['name' => 'Diving ' . uniqid()]);
        $this->article('Unfiled', 1);
        $this->article('Filed', 2)->categories()->attach($diving->id);

        $this->assertSame(['Filed'], $this->titles($this->render(['category_id' => (string) $diving->id])));
    }

    public function test_several_categories_and_skip(): void
    {
        $a = Category::create(['name' => 'A ' . uniqid()]);
        $b = Category::create(['name' => 'B ' . uniqid()]);
        $this->article('In A', 1)->categories()->attach($a->id);
        $this->article('In B', 2)->categories()->attach($b->id);
        $this->article('In neither', 3);

        $this->assertSame(['In A', 'In B'], $this->titles($this->render(['category_ids' => [$a->id, $b->id]])));
        $this->assertSame(['In B'], $this->titles($this->render(['category_ids' => [$a->id, $b->id], 'skip' => 1])));
    }

    public function test_chosen_posts_come_in_the_order_they_were_put(): void
    {
        $one = $this->article('One', 1);
        $this->article('Two', 2);
        $three = $this->article('Three', 3);
        $draft = $this->article('Draft', 4, ['status' => 'draft']);

        $html = $this->render(['source' => 'chosen', 'post_ids' => [$three->id, $draft->id, $one->id]]);

        $this->assertSame(['Three', 'One'], $this->titles($html));
    }

    public function test_featured_list_and_slider_layouts(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $i => $t) {
            $this->article("Post {$t}", $i + 1);
        }

        $featured = $this->render(['layout' => 'featured']);
        $this->assertStringContainsString('post-card post-card--lead', $featured);
        $this->assertSame(3, substr_count($featured, 'post-card post-card--row'));
        $this->assertStringContainsString('<div class="posts-grid-rest">', $featured);

        $list = $this->render(['layout' => 'list']);
        $this->assertStringContainsString('block-posts-grid--list', $list);
        $this->assertStringNotContainsString('grid-template-columns:repeat', $list);

        $slider = $this->render(['layout' => 'slider', 'columns' => 3, 'autoplay' => true]);
        $this->assertStringContainsString('data-vela-carousel data-autoplay="1" data-interval="5000" data-per-view="3"', $slider);
        $this->assertSame(2, preg_match_all('/class="carousel-dot(?: active)?"/', $slider));
    }

    public function test_what_each_card_says_is_chosen(): void
    {
        $topic = Category::create(['name' => 'Reefs ' . uniqid()]);
        $this->article('Long read', 1, ['content' => json_encode(['blocks' => [['type' => 'paragraph', 'data' => ['text' => str_repeat('word ', 800)]]]])])
            ->categories()->attach($topic->id);

        $html = $this->render(['show_excerpt' => false, 'show_date' => false, 'show_category' => true, 'show_reading_time' => true, 'show_author' => true]);

        $this->assertStringNotContainsString('post-card-excerpt', $html);
        $this->assertStringContainsString('<span class="post-card-category">' . $topic->name . '</span>', $html);
        $this->assertStringContainsString('4 min read', $html);
    }

    public function test_a_link_underneath_goes_to_the_posts_page_unless_told_otherwise(): void
    {
        $this->article('Only', 1);

        $this->assertStringContainsString('<a href="' . url('/posts') . '" class="block-posts-grid-more">See all <span aria-hidden="true">&rarr;</span></a>', $this->render(['button_text' => 'See all']));
        $this->assertStringContainsString('<a href="/blog" class="block-posts-grid-more">', $this->render(['button_text' => 'Blog', 'button_url' => '/blog']));
        $this->assertStringNotContainsString('javascript:', $this->render(['button_text' => 'X', 'button_url' => 'javascript:alert(1)']));
    }

    public function test_translations_and_pictures_are_not_read_one_card_at_a_time(): void
    {
        foreach (range(1, 8) as $n) {
            $this->article("Bulk {$n}", $n);
        }
        $s = PostsGrid::settings([]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        PostsGrid::cards(PostsGrid::posts($s));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // posts, media, categories, authors, translations — not 8 × anything.
        $this->assertLessThanOrEqual(6, $queries);
    }

    public function test_settings_the_view_does_not_know_fall_back(): void
    {
        $s = PostsGrid::settings(['layout' => 'mosaic', 'card_style' => '"><x', 'ratio' => 'tall', 'columns' => 40, 'max_count' => -1, 'skip' => -3, 'show_image' => 'no', 'interval' => 10]);

        $this->assertSame(['grid', 'bordered', 'auto', 6, 1, 0, false, 2000], [
            $s['layout'], $s['card_style'], $s['ratio'], $s['columns'], $s['max_count'], $s['skip'], $s['show_image'], $s['interval'],
        ]);
    }

    public function test_the_editor_preview_answers_with_the_cards_the_page_would_draw(): void
    {
        $this->signIn();
        Gate::define('page_access', fn () => true);
        $this->article('Preview me', 1);
        $hidden = $this->article('Not published', 2, ['status' => 'draft']);

        $cards = $this->getJson(route('vela.admin.posts-grid.preview', ['settings' => json_encode(['max_count' => 5])]))
            ->assertOk()->json('cards');
        $this->assertContains('Preview me', array_column($cards, 'title'));
        $this->assertNotContains('Not published', array_column($cards, 'title'));

        $found = $this->getJson(route('vela.admin.posts-grid.search', ['q' => 'Preview']))->assertOk()->json('results');
        $this->assertSame(['Preview me'], array_column($found, 'title'));
        $this->assertSame([], $this->getJson(route('vela.admin.posts-grid.search', ['ids' => (string) $hidden->id]))->json('results'));
    }
}
