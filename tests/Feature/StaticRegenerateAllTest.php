<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Tests\TestCase;

class StaticRegenerateAllTest extends TestCase
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/vela-static-test-' . uniqid();
        config(['vela.static.path' => $this->tempPath, 'vela.static.enabled' => true]);
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

    public function test_artisan_generate_static_creates_all_files(): void
    {
        // Create 2 published pages with rows and blocks
        $pages = [];
        for ($i = 0; $i < 2; $i++) {
            $page = Page::factory()->create(['status' => 'published']);
            $row = PageRow::create([
                'page_id'      => $page->id,
                'name'         => 'Row ' . $i,
                'css_class'    => '',
                'order_column' => $i,
            ]);
            PageBlock::create([
                'page_row_id'  => $row->id,
                'column_index' => 0,
                'column_width' => 12,
                'order_column' => 0,
                'type'         => 'text',
                'content'      => json_encode(['text' => 'Hello ' . $i]),
                'settings'     => null,
            ]);
            $pages[] = $page;
        }

        // Create 2 published posts
        $posts = [];
        for ($i = 0; $i < 2; $i++) {
            $posts[] = Content::factory()->create(['status' => 'published', 'type' => 'post']);
        }

        // Run the artisan command synchronously
        $this->artisan('vela:generate-static')->assertExitCode(0);

        // Assert page files exist
        foreach ($pages as $page) {
            $this->assertFileExists(
                $this->tempPath . '/pages/' . $page->slug . '/index.html',
                "index.html missing for page: {$page->slug}"
            );
        }

        // Assert post files exist
        foreach ($posts as $post) {
            $this->assertFileExists(
                $this->tempPath . '/posts/' . $post->slug . '/index.html',
                "index.html missing for post: {$post->slug}"
            );
        }

        // Assert aggregate files exist
        $this->assertFileExists($this->tempPath . '/home/index.html', 'home/index.html missing');
        $this->assertFileExists($this->tempPath . '/posts/index.html', 'posts/index.html missing');
        $this->assertFileExists($this->tempPath . '/categories/index.html', 'categories/index.html missing');
    }
}
