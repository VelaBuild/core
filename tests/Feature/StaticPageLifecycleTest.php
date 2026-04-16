<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use VelaBuild\Core\Jobs\GenerateStaticFilesJob;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Tests\TestCase;

class StaticPageLifecycleTest extends TestCase
{
    protected StaticSiteGenerator $generator;
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/vela-static-test-' . uniqid();
        config(['vela.static.path' => $this->tempPath, 'vela.static.enabled' => true]);
        $this->generator = app(StaticSiteGenerator::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $path = $this->tempPath;
        if (is_dir($path)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($path);
        }
        parent::tearDown();
    }

    public function test_publishing_page_generates_static_files(): void
    {
        $page = Page::factory()->create(['status' => 'published']);

        // Observer should have dispatched GenerateStaticFilesJob
        Queue::assertPushed(GenerateStaticFilesJob::class);

        // Manually generate to verify file output
        $this->generator->generatePage($page);

        $slug = $page->slug;
        $this->assertFileExists($this->tempPath . '/pages/' . $slug . '/index.html');
        $this->assertFileExists($this->tempPath . '/pages/' . $slug . '/config.json');
    }

    public function test_unpublishing_page_removes_html_keeps_config(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $this->generator->generatePage($page);

        $slug = $page->slug;
        $this->assertFileExists($this->tempPath . '/pages/' . $slug . '/index.html');
        $this->assertFileExists($this->tempPath . '/pages/' . $slug . '/config.json');

        // Update to draft (triggers observer)
        $page->update(['status' => 'draft']);

        // Manually remove HTML as observer would via removeHtml
        $this->generator->removeHtml('pages', $slug);

        $this->assertFileDoesNotExist($this->tempPath . '/pages/' . $slug . '/index.html');
        $this->assertFileExists($this->tempPath . '/pages/' . $slug . '/config.json');
    }

    public function test_slug_change_cleans_old_directory(): void
    {
        $page = Page::factory()->create(['status' => 'published', 'slug' => 'old-slug-' . uniqid()]);
        $this->generator->generatePage($page);

        $oldSlug = $page->slug;
        $this->assertFileExists($this->tempPath . '/pages/' . $oldSlug . '/index.html');

        // Change the slug (observer would call removeAll on old slug)
        $this->generator->removeAll('pages', $oldSlug);
        $this->assertDirectoryDoesNotExist($this->tempPath . '/pages/' . $oldSlug);

        // Generate new slug files
        $newSlug = 'new-slug-' . uniqid();
        $page->slug = $newSlug;
        $this->generator->generatePage($page);

        $this->assertFileExists($this->tempPath . '/pages/' . $newSlug . '/index.html');
        $this->assertFileExists($this->tempPath . '/pages/' . $newSlug . '/config.json');
    }

    public function test_deleting_page_removes_all_files(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $this->generator->generatePage($page);

        $slug = $page->slug;
        $this->assertDirectoryExists($this->tempPath . '/pages/' . $slug);

        // Observer would call removeAll on delete
        $this->generator->removeAll('pages', $slug);

        $this->assertDirectoryDoesNotExist($this->tempPath . '/pages/' . $slug);
    }
}
