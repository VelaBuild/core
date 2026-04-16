<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\SiteContext;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;

class DesignBuilderServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDir($file);
            }
        }
        rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vela_test_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDir = $dir;
        return $dir;
    }

    private function makeService(): DesignBuilderService
    {
        $aiManager = Mockery::mock(AiProviderManager::class);
        $toolRegistry = app(ChatToolRegistry::class);
        $toolExecutor = app(ChatToolExecutor::class);
        $siteContext = app(SiteContext::class);

        return new DesignBuilderService($aiManager, $toolRegistry, $toolExecutor, $siteContext);
    }

    private function createMinimalPng(string $path): void
    {
        // Minimal valid 1x1 PNG binary
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($path, $png);
    }

    public function test_generates_context_from_folder(): void
    {
        $dir = $this->makeTempDir();
        $this->createMinimalPng($dir . '/banner.png');
        file_put_contents($dir . '/README.md', '# Design Notes\nUse blue primary color.');

        $service = $this->makeService();
        $context = $service->generateContext($dir);

        $this->assertArrayHasKey('assets', $context);
        $this->assertArrayHasKey('instructions', $context);
        $this->assertCount(1, $context['assets']);
        $this->assertCount(1, $context['instructions']);
        $this->assertEquals('banner.png', $context['assets'][0]['file']);
        $this->assertStringContainsString('Design Notes', $context['instructions'][0]['content']);
        $this->assertFileExists($dir . '/context.json');
    }

    public function test_context_skips_unsupported_file_types(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/malware.exe', 'fake executable');
        $this->createMinimalPng($dir . '/banner.png');

        $service = $this->makeService();
        $context = $service->generateContext($dir);

        $files = array_column($context['assets'], 'file');
        $this->assertContains('banner.png', $files);
        $this->assertNotContains('malware.exe', $files);
        $this->assertCount(1, $context['assets']);
    }

    public function test_context_detects_design_role_from_filename(): void
    {
        $dir = $this->makeTempDir();
        $this->createMinimalPng($dir . '/homepage-design.png');
        file_put_contents($dir . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $service = $this->makeService();
        $context = $service->generateContext($dir);

        $assetsByFile = [];
        foreach ($context['assets'] as $asset) {
            $assetsByFile[$asset['file']] = $asset;
        }

        $this->assertArrayHasKey('homepage-design.png', $assetsByFile);
        $this->assertArrayHasKey('logo.svg', $assetsByFile);
        $this->assertEquals('design', $assetsByFile['homepage-design.png']['role']);
        $this->assertEquals('asset', $assetsByFile['logo.svg']['role']);
    }

    public function test_progress_callback_is_called(): void
    {
        $dir = $this->makeTempDir();
        $this->createMinimalPng($dir . '/banner.png');

        $service = $this->makeService();

        $messages = [];
        $service->onProgress(function (string $msg) use (&$messages) {
            $messages[] = $msg;
        });

        $service->generateContext($dir);

        $this->assertNotEmpty($messages);
    }
}
