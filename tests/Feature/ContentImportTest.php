<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\TestCase;

class ContentImportTest extends TestCase
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/vela-import-test-' . uniqid();
        config(['vela.static.path' => $this->tempPath]);

        // Clear daily cache key so imports always run
        \Illuminate\Support\Facades\Cache::forget('import-content-ran:' . now()->toDateString());
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

        // Clear daily cache key after test
        \Illuminate\Support\Facades\Cache::forget('import-content-ran:' . now()->toDateString());

        parent::tearDown();
    }

    private function writeConfigJson(string $slug, array $data): void
    {
        $dir = $this->tempPath . '/pages/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/config.json', json_encode($data));
    }

    public function test_import_creates_page_from_config(): void
    {
        $slug = 'test-import-' . uniqid();
        $title = 'Test Import Page';

        $this->writeConfigJson($slug, [
            'type'             => 'page',
            'title'            => $title,
            'slug'             => $slug,
            'locale'           => 'en',
            'status'           => 'published',
            'meta_title'       => 'Test Meta',
            'meta_description' => 'Test Description',
            'custom_css'       => null,
            'custom_js'        => null,
            'order_column'     => 0,
            'parent_id'        => null,
            'rows'             => [],
            'last_modified'    => now()->toISOString(),
        ]);

        $this->artisan('vela:import-content');

        $page = Page::where('slug', $slug)->first();
        $this->assertNotNull($page);
        $this->assertEquals($title, $page->title);
        $this->assertEquals($slug, $page->slug);
    }

    public function test_import_skips_older_config(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $originalTitle = $page->title;

        $this->writeConfigJson($page->slug, [
            'type'          => 'page',
            'title'         => 'Should Not Update',
            'slug'          => $page->slug,
            'locale'        => 'en',
            'status'        => 'published',
            'rows'          => [],
            // Use a timestamp older than DB record
            'last_modified' => now()->subYear()->toISOString(),
        ]);

        $this->artisan('vela:import-content');

        $page->refresh();
        $this->assertEquals($originalTitle, $page->title);
    }

    public function test_import_updates_when_config_newer(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $page->forceFill(['updated_at' => now()->subYear()])->save();

        $newTitle = 'Updated Title ' . uniqid();

        $this->writeConfigJson($page->slug, [
            'type'          => 'page',
            'title'         => $newTitle,
            'slug'          => $page->slug,
            'locale'        => 'en',
            'status'        => 'published',
            'rows'          => [],
            // Use a timestamp newer than DB record
            'last_modified' => now()->toISOString(),
        ]);

        $this->artisan('vela:import-content');

        $page->refresh();
        $this->assertEquals($newTitle, $page->title);
    }
}
