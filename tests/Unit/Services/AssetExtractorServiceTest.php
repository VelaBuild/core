<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\AssetExtractorService;
use VelaBuild\Core\Tests\TestCase;

class AssetExtractorServiceTest extends TestCase
{
    protected AssetExtractorService $service;
    protected ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssetExtractorService();
    }

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vela_test_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDir = $dir;
        return $dir;
    }

    public function test_is_available_returns_bool(): void
    {
        $this->assertIsBool($this->service->isAvailable());
    }

    public function test_extract_all_returns_empty_for_no_psd_files(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/image.png', 'fake-png-content');

        $result = $this->service->extractAll($dir);

        $this->assertEmpty($result);
    }

    public function test_extract_all_returns_skipped_when_imagemagick_unavailable(): void
    {
        if ($this->service->isAvailable()) {
            $this->markTestSkipped('ImageMagick is available');
        }

        $dir = $this->makeTempDir();
        file_put_contents($dir . '/design.psd', 'fake-psd-content');

        $result = $this->service->extractAll($dir);

        $this->assertArrayHasKey('skipped', $result);
    }
}
