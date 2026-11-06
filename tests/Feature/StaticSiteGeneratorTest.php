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

    public function test_regenerating_from_an_admin_request_renders_public_urls(): void
    {
        // Regeneration usually runs inside an admin request (cache clear, save,
        // import). The page must still read as the public site: og:url was
        // stamped /admin/cache/clear, and an install served from a subdirectory
        // leaked /vela/vela.build/public into asset URLs.
        config(['app.url' => 'https://acme.test']);
        $admin = \Illuminate\Http\Request::create('http://localhost/vela/vela.build/public/admin/cache/clear', 'POST', [], [], [], [
            'SCRIPT_NAME' => '/vela/vela.build/public/index.php',
            'SCRIPT_FILENAME' => '/srv/vela.build/public/index.php',
        ]);
        app()->instance('request', $admin);
        app('url')->setRequest($admin);

        $page = \VelaBuild\Core\Models\Page::create([
            'title' => 'About us', 'slug' => 'about-us', 'status' => 'published',
            'locale' => config('vela.primary_language', 'en'),
        ]);
        app(StaticSiteGenerator::class)->generatePage($page);

        $html = file_get_contents($this->basePath() . '/pages/about-us/index.html');
        $this->assertStringContainsString('content="https://acme.test/about-us"', $html, 'og:url is the page itself');
        $this->assertStringNotContainsString('/admin/cache/clear', $html);
        $this->assertStringNotContainsString('/vela/vela.build/public', $html);
        $this->assertSame('http://localhost/vela/vela.build/public/admin/cache/clear', request()->url(), 'the real request is restored');
    }

    public function test_a_translated_page_is_rendered_in_its_own_language(): void
    {
        $locales = [
            'en' => ['name' => 'English', 'script' => 'Latn', 'native' => 'English', 'regional' => 'en_GB'],
            'fr' => ['name' => 'French', 'script' => 'Latn', 'native' => 'français', 'regional' => 'fr_FR'],
        ];
        config(['laravellocalization.supportedLocales' => $locales, 'vela.primary_language' => 'en', 'app.locale' => 'en']);
        app('laravellocalization')->setSupportedLocales($locales);

        $page = \VelaBuild\Core\Models\Page::create([
            'title' => 'À propos', 'slug' => 'about-us', 'status' => 'published', 'locale' => 'fr',
        ]);
        app(StaticSiteGenerator::class)->generatePage($page);

        $html = file_get_contents($this->basePath() . '/pages/about-us/translations/fr.html');
        $this->assertStringContainsString('<html lang="fr"', $html);
        $this->assertSame('en', app()->getLocale(), 'the language is restored afterwards');
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
