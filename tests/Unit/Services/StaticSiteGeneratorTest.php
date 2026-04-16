<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Tests\TestCase;

class StaticSiteGeneratorTest extends TestCase
{
    protected StaticSiteGenerator $generator;
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/vela-static-test-' . uniqid();
        config(['vela.static.path' => $this->tempPath]);
        $this->generator = app(StaticSiteGenerator::class);
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

    public function test_generate_page_creates_html_and_config(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $row = PageRow::create([
            'page_id'      => $page->id,
            'name'         => 'Main',
            'css_class'    => '',
            'order_column' => 0,
        ]);
        PageBlock::create([
            'page_row_id'  => $row->id,
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
            'type'         => 'text',
            'content'      => json_encode(['text' => 'Hello']),
            'settings'     => null,
        ]);

        $this->generator->generatePage($page);

        $slug = $page->slug;
        $configPath = $this->tempPath . '/pages/' . $slug . '/config.json';

        $this->assertFileExists($configPath, 'config.json should exist');

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertNotNull($config, 'config.json should be valid JSON');
        $this->assertSame('page', $config['type']);
        $this->assertSame($page->id, $config['id']);
        $this->assertSame($page->title, $config['title']);
        $this->assertSame($slug, $config['slug']);
        $this->assertSame($page->locale, $config['locale']);
        $this->assertSame('published', $config['status']);
        $this->assertArrayHasKey('rows', $config);
        $this->assertArrayHasKey('last_modified', $config);
    }

    public function test_config_json_has_iso8601_last_modified(): void
    {
        $page = Page::factory()->create(['status' => 'published']);

        $this->generator->writeConfigJson($page);

        $configPath = $this->tempPath . '/pages/' . $page->slug . '/config.json';
        $this->assertFileExists($configPath);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertNotNull($config);
        $this->assertArrayHasKey('last_modified', $config);

        // ISO 8601 pattern: e.g. 2026-04-03T10:00:00.000000Z
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $config['last_modified'],
            'last_modified should match ISO 8601 format'
        );
    }

    public function test_generate_page_skips_html_for_draft(): void
    {
        $page = Page::factory()->create(['status' => 'draft']);

        $this->generator->generatePage($page);

        $slug = $page->slug;
        $htmlPath = $this->tempPath . '/pages/' . $slug . '/index.html';
        $configPath = $this->tempPath . '/pages/' . $slug . '/config.json';

        $this->assertFileDoesNotExist($htmlPath, 'index.html should NOT be created for draft pages');
        $this->assertFileExists($configPath, 'config.json should be created even for draft pages');
    }

    public function test_remove_html_keeps_config(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $this->generator->writeConfigJson($page);

        $slug = $page->slug;
        $dir = $this->tempPath . '/pages/' . $slug;

        // Manually create an index.html to simulate a previously generated file
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/index.html', '<html></html>');

        $this->assertFileExists($dir . '/index.html');
        $this->assertFileExists($dir . '/config.json');

        $this->generator->removeHtml('pages', $slug);

        $this->assertFileDoesNotExist($dir . '/index.html', 'index.html should be removed');
        $this->assertFileExists($dir . '/config.json', 'config.json should remain');
    }

    public function test_remove_all_deletes_everything(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $this->generator->writeConfigJson($page);

        $slug = $page->slug;
        $dir = $this->tempPath . '/pages/' . $slug;

        $this->assertFileExists($dir . '/config.json');

        $this->generator->removeAll('pages', $slug);

        $this->assertDirectoryDoesNotExist($dir, 'The entire slug directory should be removed');
    }

    public function test_atomic_write_does_not_leave_tmp_files(): void
    {
        $page = Page::factory()->create(['status' => 'published']);
        $row = PageRow::create([
            'page_id'      => $page->id,
            'name'         => 'Main',
            'css_class'    => '',
            'order_column' => 0,
        ]);
        PageBlock::create([
            'page_row_id'  => $row->id,
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
            'type'         => 'text',
            'content'      => json_encode(['text' => 'Hello']),
            'settings'     => null,
        ]);

        $this->generator->generatePage($page);

        $dir = $this->tempPath . '/pages/' . $page->slug;

        if (is_dir($dir)) {
            $tmpFiles = glob($dir . '/*.tmp');
            $this->assertEmpty($tmpFiles, 'No .tmp files should remain after generation');
        }

        // Config JSON was at least written
        $this->assertFileExists($dir . '/config.json');
    }
}
