<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a categories grid block puts on the page, as the block editor saves it.
 */
class CategoriesGridBlockRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A public page request imports the categories and pages written out
        // to config files by earlier tests, and the block lists every
        // category — so the grid filled with other tests' topics. Its daily
        // lock keeps the import out.
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    private function render(array $settings = []): string
    {
        $slug = 'categories-check-' . uniqid();
        $page = Page::create(['title' => 'Topics', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'categories_grid', 'content' => [], 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function category(string $name, int $published = 0, int $order = 0, ?string $icon = null): Category
    {
        $category = Category::create(['name' => $name, 'order_by' => $order, 'icon' => $icon]);
        for ($i = 0; $i < $published; $i++) {
            $post = Content::create([
                'title' => "{$name} {$i}", 'slug' => \Illuminate\Support\Str::slug("{$name}-{$i}"), 'type' => 'post',
                'status' => 'published', 'author_id' => 1, 'written_at' => now(),
            ]);
            $post->categories()->attach($category->id);
        }

        return $category;
    }

    /** The category names in the order the block drew them. */
    private function names(string $html): array
    {
        preg_match_all('/<h2 class="category-card-title">([^<]+)<\/h2>/', $html, $m);

        return $m[1];
    }

    public function test_a_block_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $this->category('Wrecks', 2, 1);
        $this->category('Reefs', 1, 2);

        $html = $this->render(['columns' => 3, 'max_count' => 12, 'show_post_count' => true]);

        // Two categories, so two columns — never an empty third.
        $this->assertStringContainsString('class="block-categories-grid block-categories-grid--image block-categories-grid--ratio-auto block-categories-grid--multi" style="grid-template-columns:repeat(2,minmax(0,1fr));"', $html);
        $this->assertSame(['Wrecks', 'Reefs'], $this->names($html));
        $this->assertStringContainsString('<span class="category-card-count">2 articles</span>', $html);
        $this->assertStringContainsString('<span class="category-card-count">1 article</span>', $html);
        // No picture anywhere, so no icon panels either.
        $this->assertStringNotContainsString('category-card-media', $html);
    }

    public function test_post_counts_are_read_in_one_query_not_one_per_card(): void
    {
        foreach (range(1, 6) as $n) {
            $this->category("Topic {$n}", 1, $n);
        }
        $withBlock = Page::create(['title' => 'Grid', 'slug' => 'grid-' . uniqid(), 'locale' => 'en', 'status' => 'published']);
        $withBlock->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0])->blocks()->create([
            'type' => 'categories_grid', 'content' => [], 'settings' => [],
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);
        $without = Page::create(['title' => 'Plain', 'slug' => 'plain-' . uniqid(), 'locale' => 'en', 'status' => 'published']);

        // The page around the block counts posts per category in places of
        // its own; what the block adds on top is what is measured.
        $perCategoryCounts = function (Page $page): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/' . $page->slug)->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'count(*) as "aggregate"')
                && str_contains($q['query'], '"vela_article_category"."category_id" = ?'))->count();
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($perCategoryCounts($without), $perCategoryCounts($withBlock));
    }

    public function test_chosen_categories_come_in_the_order_they_were_put(): void
    {
        $a = $this->category('Alpha', 1, 1);
        $this->category('Bravo', 1, 2);
        $c = $this->category('Charlie', 1, 3);

        $html = $this->render(['source' => 'chosen', 'category_ids' => [$c->id, $a->id]]);

        $this->assertSame(['Charlie', 'Alpha'], $this->names($html));
    }

    public function test_hide_empty_leaves_out_categories_with_no_published_posts(): void
    {
        $this->category('Busy', 1, 1);
        $this->category('Quiet', 0, 2);

        $this->assertSame(['Busy', 'Quiet'], $this->names($this->render()));
        $this->assertSame(['Busy'], $this->names($this->render(['hide_empty' => true])));
    }

    public function test_compact_and_pills_draw_the_category_icon(): void
    {
        $this->category('Wrecks', 1, 1, 'fas fa-ship');
        $this->category('Reefs', 1, 2);

        $compact = $this->render(['card_style' => 'compact']);
        $this->assertStringContainsString('<span class="category-card-icon" aria-hidden="true"><i class="fas fa-ship"></i></span>', $compact);
        $this->assertStringContainsString('<i class="fas fa-folder"></i>', $compact);

        $pills = $this->render(['card_style' => 'pills', 'show_post_count' => false]);
        $this->assertStringContainsString('class="block-categories-grid block-categories-grid--pills block-categories-grid--ratio-auto">', $pills);
        $this->assertStringNotContainsString('category-card-count', $pills);
    }

    public function test_values_the_view_does_not_know_fall_back(): void
    {
        $this->category('Wrecks', 1, 1);

        $html = $this->render(['card_style' => '"><script>', 'ratio' => 'tall', 'source' => 'everything', 'columns' => 99, 'max_count' => -4]);

        $this->assertStringContainsString('block-categories-grid--image block-categories-grid--ratio-auto', $html);
        $this->assertStringNotContainsString('<script>"', $html);
    }
}
