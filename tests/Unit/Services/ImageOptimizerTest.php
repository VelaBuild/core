<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\ImageOptimizer;
use VelaBuild\Core\Tests\TestCase;

class ImageOptimizerTest extends TestCase
{
    protected ImageOptimizer $optimizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->optimizer = app(ImageOptimizer::class);
    }

    public function test_hmac_roundtrip(): void
    {
        $src = 'storage/app/public/test.jpg';
        $width = 800;

        $url = $this->optimizer->generateUrl($src, $width);

        // Extract config param (everything after /imgp/)
        $this->assertStringStartsWith('/imgp/', $url);
        $config = substr($url, strlen('/imgp/'));

        $decoded = $this->optimizer->verifyAndDecode($config);

        $this->assertNotNull($decoded, 'verifyAndDecode should return data for valid config');
        $this->assertSame($src, $decoded['s']);
        $this->assertSame($width, $decoded['w']);
    }

    public function test_invalid_signature_rejected(): void
    {
        $url = $this->optimizer->generateUrl('storage/app/public/test.jpg', 400);
        $config = substr($url, strlen('/imgp/'));

        // Modify the last character of the config (the HMAC portion)
        $lastChar = substr($config, -1);
        $newLastChar = ($lastChar === 'a') ? 'b' : 'a';
        $tampered = substr($config, 0, -1) . $newLastChar;

        $result = $this->optimizer->verifyAndDecode($tampered);

        $this->assertNull($result, 'Tampered signature should be rejected');
    }

    public function test_base64_encode_decode(): void
    {
        $payload = json_encode(['s' => 'storage/app/public/photo.jpg', 'w' => 600, 'h' => 0, 'm' => 'fit']);

        // Base64url encode (same as in ImageOptimizer)
        $encoded = strtr(base64_encode($payload), '+/', '-_');

        // Base64url decode
        $decoded = base64_decode(strtr($encoded, '-_', '+/'));

        $this->assertSame($payload, $decoded, 'Base64url roundtrip should preserve payload');
    }

    public function test_rejects_oversized_dimensions(): void
    {
        $maxWidth = config('vela.images.max_width', 2000);
        $oversizedWidth = $maxWidth + 1;

        // Generate a valid HMAC URL with oversized width
        $url = $this->optimizer->generateUrl('storage/app/public/test.jpg', $oversizedWidth);
        $config = substr($url, strlen('/imgp/'));

        // HMAC is valid but dimensions exceed max
        $result = $this->optimizer->verifyAndDecode($config);

        $this->assertNull($result, 'Oversized dimensions should be rejected even with valid HMAC');
    }

    public function test_resize_url_starts_with_imgr(): void
    {
        $url = $this->optimizer->generateResizeUrl('storage/app/public/test.jpg', 800);

        $this->assertStringStartsWith('/imgr/', $url);
    }

    public function test_resize_url_hmac_roundtrip(): void
    {
        $src = 'storage/app/public/test.jpg';
        $width = 600;

        $url = $this->optimizer->generateResizeUrl($src, $width);
        $config = substr($url, strlen('/imgr/'));

        $decoded = $this->optimizer->verifyAndDecode($config);

        $this->assertNotNull($decoded, 'verifyAndDecode should work for imgr config too');
        $this->assertSame($src, $decoded['s']);
        $this->assertSame($width, $decoded['w']);
    }

    public function test_path_traversal_in_src_rejected(): void
    {
        // Generate a valid HMAC URL, then manually craft one with path traversal in src
        // We must construct a config that has a valid HMAC but contains '..' in src
        // The easiest way: use generateUrl with a traversal src and check verifyAndDecode rejects it
        $url = $this->optimizer->generateUrl('../../etc/passwd', 400);
        $config = substr($url, strlen('/imgp/'));

        $result = $this->optimizer->verifyAndDecode($config);

        $this->assertNull($result, 'Path traversal in src should be rejected');
    }
}
