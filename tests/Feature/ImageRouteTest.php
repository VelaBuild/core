<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Services\ImageOptimizer;
use VelaBuild\Core\Tests\TestCase;

class ImageRouteTest extends TestCase
{
    protected string $testImagePath;
    protected string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testImagePath = storage_path('app/public/test-image.jpg');
        $this->cachePath = config('vela.images.cache_path', storage_path('app/image-cache'));

        // Create test image
        $img = imagecreatetruecolor(100, 100);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        if (!is_dir(dirname($this->testImagePath))) {
            mkdir(dirname($this->testImagePath), 0755, true);
        }
        imagejpeg($img, $this->testImagePath);
        imagedestroy($img);

        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Delete test image
        if (file_exists($this->testImagePath)) {
            unlink($this->testImagePath);
        }

        // Clean cache dir contents
        if (is_dir($this->cachePath)) {
            foreach (glob($this->cachePath . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    public function test_valid_imgp_url_returns_image(): void
    {
        $optimizer = app(ImageOptimizer::class);
        $url = $optimizer->generateUrl('storage/app/public/test-image.jpg', 100);

        // Extract the /imgp/{config} portion
        $response = $this->get($url);
        $response->assertStatus(200);
    }

    public function test_invalid_hmac_returns_403(): void
    {
        $optimizer = app(ImageOptimizer::class);
        $url = $optimizer->generateUrl('storage/app/public/test-image.jpg', 100);

        // Extract config param and tamper with it
        $path = parse_url($url, PHP_URL_PATH);
        $config = substr($path, strlen('/imgp/'));

        // Modify last character of config to invalidate HMAC
        $lastChar = substr($config, -1);
        $newLastChar = ($lastChar === 'a') ? 'b' : 'a';
        $tamperedConfig = substr($config, 0, -1) . $newLastChar;

        $response = $this->get('/imgp/' . $tamperedConfig);
        $response->assertStatus(403);
    }

    public function test_missing_source_returns_404(): void
    {
        $optimizer = app(ImageOptimizer::class);
        $url = $optimizer->generateUrl('storage/app/public/nonexistent.jpg', 100);

        $response = $this->get($url);
        $response->assertStatus(404);
    }

    public function test_imgr_returns_original_format(): void
    {
        $optimizer = app(ImageOptimizer::class);
        $url = $optimizer->generateResizeUrl('storage/app/public/test-image.jpg', 100);

        $response = $this->get($url);
        $response->assertStatus(200);

        $contentType = $response->headers->get('Content-Type');
        $this->assertNotEquals('image/webp', $contentType);
    }
}
