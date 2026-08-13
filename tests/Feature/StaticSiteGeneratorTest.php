<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The generator renders the same Blade views the public controllers do, so it
 * has to hand them the same shape. Where it did not, the failure was silent —
 * the render threw, the error went to the log, and the site simply never had
 * a pre-rendered copy of that page.
 */
class StaticSiteGeneratorTest extends PackageTestCase
{
    private function basePath(): string
    {
        return config('vela.static.path', resource_path('static'));
    }

    protected function tearDown(): void
    {
        app(StaticSiteGenerator::class)->purgeHtml();
        parent::tearDown();
    }

    private function categoryWithAnArticle(): Category
    {
        $category = Category::create(['name' => 'Diving Tips']);

        $article = Content::create([
            'title'      => 'Beginner Mistakes',
            'slug'       => 'beginner-mistakes',
            'type'       => 'post',
            'status'     => 'published',
            'author_id'  => 1,
            'written_at' => now(),
        ]);
        $article->categories()->attach($category->id);

        return $category;
    }

    public function test_a_category_page_reaches_the_static_cache(): void
    {
        // The view asks the result set for hasPages(); a plain collection
        // cannot answer, so every category died on render.
        app(StaticSiteGenerator::class)->generateCategoryPage($this->categoryWithAnArticle());

        $file = $this->basePath() . '/categories/diving-tips/index.html';

        $this->assertFileExists($file, 'the category page must be pre-rendered like every other page');
        $this->assertStringContainsString('Beginner Mistakes', file_get_contents($file));
    }

    public function test_purging_clears_the_pages_a_visitor_would_be_served(): void
    {
        // What a sitewide change needs: nothing left behind holding the
        // previous theme's markup.
        $generator = app(StaticSiteGenerator::class);
        $generator->generateCategoryPage($this->categoryWithAnArticle());
        $generator->generateHomePage();

        $this->assertNotEmpty(glob($this->basePath() . '/categories/*/index.html'));

        $generator->purgeHtml();

        $this->assertSame([], glob($this->basePath() . '/categories/*/index.html'));
        $this->assertFileDoesNotExist($this->basePath() . '/home/index.html');
    }
}
