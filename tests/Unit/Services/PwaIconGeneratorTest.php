<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\PwaIconGenerator;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class PwaIconGeneratorTest extends TestCase
{
    use DatabaseTransactions;

    private string $testImagePath;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a test 512x512 image using GD
        $this->testImagePath = storage_path('app/test-pwa-icon.png');
        $img = imagecreatetruecolor(512, 512);
        $color = imagecolorallocate($img, 100, 150, 200);
        imagefill($img, 0, 0, $color);
        imagepng($img, $this->testImagePath);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testImagePath)) {
            unlink($this->testImagePath);
        }
        // Clean up generated icons
        $outputDir = storage_path('app/public/pwa-icons');
        if (is_dir($outputDir)) {
            foreach (glob("{$outputDir}/icon-*") as $file) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_generates_all_standard_sizes(): void
    {
        $generator = new PwaIconGenerator();
        $result = $generator->generate($this->testImagePath);

        $this->assertTrue($result['success']);

        $outputDir = $generator->getOutputPath();
        foreach ([48, 72, 96, 128, 144, 192, 512] as $size) {
            $this->assertFileExists("{$outputDir}/icon-{$size}x{$size}.png", "Standard icon {$size}x{$size} not generated");
        }
    }

    public function test_generates_maskable_variants(): void
    {
        $generator = new PwaIconGenerator();
        $result = $generator->generate($this->testImagePath);

        $outputDir = $generator->getOutputPath();
        foreach ([192, 512] as $size) {
            $this->assertFileExists("{$outputDir}/icon-{$size}x{$size}-maskable.png", "Maskable icon {$size}x{$size} not generated");
        }
    }

    public function test_rejects_undersized_image(): void
    {
        // Create a too-small test image
        $smallPath = storage_path('app/test-pwa-icon-small.png');
        $img = imagecreatetruecolor(256, 256);
        imagepng($img, $smallPath);
        imagedestroy($img);

        $generator = new PwaIconGenerator();
        $result = $generator->generate($smallPath);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);

        unlink($smallPath);
    }

    public function test_clears_old_icons_before_regenerating(): void
    {
        $generator = new PwaIconGenerator();
        $outputDir = $generator->getOutputPath();

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Create a dummy old icon
        file_put_contents("{$outputDir}/icon-48x48.png", 'old');

        $generator->generate($this->testImagePath);

        // The file should exist but with new content (not 'old')
        $this->assertNotEquals('old', file_get_contents("{$outputDir}/icon-48x48.png"));
    }
}
