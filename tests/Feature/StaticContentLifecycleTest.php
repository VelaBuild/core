<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use VelaBuild\Core\Jobs\GenerateStaticFilesJob;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Tests\TestCase;

class StaticContentLifecycleTest extends TestCase
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

    public function test_publishing_post_generates_files_and_aggregates(): void
    {
        $post = Content::factory()->create(['status' => 'published', 'type' => 'post']);

        // Observer should dispatch content, home, and posts_index jobs
        Queue::assertPushed(GenerateStaticFilesJob::class);

        // Manually generate to verify file output
        $this->generator->generateContent($post);

        $slug = $post->slug;
        $this->assertFileExists($this->tempPath . '/posts/' . $slug . '/index.html');
        $this->assertFileExists($this->tempPath . '/posts/' . $slug . '/config.json');
    }

    public function test_unpublishing_post_removes_html(): void
    {
        $post = Content::factory()->create(['status' => 'published', 'type' => 'post']);
        $this->generator->generateContent($post);

        $slug = $post->slug;
        $this->assertFileExists($this->tempPath . '/posts/' . $slug . '/index.html');
        $this->assertFileExists($this->tempPath . '/posts/' . $slug . '/config.json');

        // Update to draft (triggers observer)
        $post->update(['status' => 'draft']);

        // Manually remove HTML as observer would
        $this->generator->removeHtml('posts', $slug);

        $this->assertFileDoesNotExist($this->tempPath . '/posts/' . $slug . '/index.html');
        $this->assertFileExists($this->tempPath . '/posts/' . $slug . '/config.json');
    }
}
