<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The no-setup route for screenshots, as far as it can be checked for free.
 *
 * The brief asked for a cloud service as an optional route and a local browser
 * as the default. The local one is now pinned by BrowserForScreenshotsTest and
 * has been run for real; this branch had never been executed at all, against a
 * real account or otherwise.
 *
 * What is checked here is everything on Vela's side of the wire: the request
 * it sends, and what it does with what comes back. What is NOT checked is
 * whether any real service answers that shape — see the note in the docs about
 * what a site actually has to deploy.
 */
class CloudScreenshotTest extends PackageTestCase
{
    private const ENDPOINT = 'https://renderer.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['vela.browser_rendering.url' => self::ENDPOINT]);
    }

    /** A capture with no local browser goes over the wire and lands as a file. */
    public function test_a_shot_taken_in_the_cloud_is_written_like_any_other(): void
    {
        Http::fake([self::ENDPOINT . '/screenshot' => Http::response($this->aPng(), 200)]);

        $target = $this->target('png');

        $this->assertSame($target, $this->cloudOnly()->capture('https://example.com/', $target));
        $this->assertFileExists($target);

        [$width, $height] = getimagesize($target);
        $this->assertSame(1280, $width);
        $this->assertSame(800, $height);
    }

    /**
     * The service is asked for the page in the shape it expects.
     *
     * Nothing here can be discovered by trying it — a wrong key is a service
     * quietly rendering the default viewport — so the request is pinned.
     */
    public function test_the_request_says_what_to_photograph_and_how_big(): void
    {
        Http::fake([self::ENDPOINT . '/screenshot' => Http::response($this->aPng(), 200)]);

        $this->cloudOnly()->capture('https://example.com/pricing', $this->target('png'));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === self::ENDPOINT . '/screenshot'
                && $request->method() === 'POST'
                && $body['url'] === 'https://example.com/pricing'
                // The service's own default is 1280x800; what must arrive is
                // the SIZE THIS SERVICE WORKS AT, or the cloud route quietly
                // photographs a narrower page than the local one does and the
                // two routes stop being interchangeable.
                && $body['viewport'] === ['width' => 1920, 'height' => 1080]
                && $body['format'] === 'png'
                && $body['fullPage'] === false;
        });
    }

    /**
     * A .jpg target really is a JPEG.
     *
     * The service is asked for PNG whatever the caller wanted, exactly as a
     * local Chrome only ever writes PNG, so the re-encode has to happen on
     * this side of both routes — a PNG named .jpg is served with the wrong
     * content type.
     */
    public function test_a_jpeg_target_is_re_encoded_not_merely_renamed(): void
    {
        Http::fake([self::ENDPOINT . '/screenshot' => Http::response($this->aPng(), 200)]);

        $target = $this->target('jpg');
        $this->cloudOnly()->capture('https://example.com/', $target, 640);

        $this->assertSame('image/jpeg', getimagesize($target)['mime']);
        $this->assertSame(640, getimagesize($target)[0], 'and maxWidth scales it down');
    }

    /**
     * A service that answers with nothing stops the build with a sentence
     * somebody can act on, rather than writing a broken file and going on.
     */
    public function test_a_service_that_answers_with_nothing_says_so(): void
    {
        Http::fake([self::ENDPOINT . '/screenshot' => Http::response('', 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return a screenshot');

        $this->cloudOnly()->capture('https://example.com/', $this->target('png'));
    }

    /** And one that answers with a few bytes of nothing is not a screenshot. */
    public function test_a_reply_too_small_to_be_a_picture_is_refused(): void
    {
        Http::fake([self::ENDPOINT . '/screenshot' => Http::response('not a png', 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty screenshot');

        $this->cloudOnly()->capture('https://example.com/', $this->target('png'));
    }

    /**
     * A configured service is enough to report screenshots as available, so a
     * host with nowhere to put a Chrome is never told to install one.
     */
    public function test_a_configured_service_is_on_its_own_enough(): void
    {
        $this->assertTrue($this->cloudOnly()->isAvailable());

        config(['vela.browser_rendering.url' => null]);
        $this->assertFalse($this->cloudOnly()->isAvailable());
    }

    /** A ScreenshotService that believes this machine has no browser. */
    private function cloudOnly(): ScreenshotService
    {
        $screenshots = \Mockery::mock(ScreenshotService::class)->makePartial();
        $screenshots->shouldReceive('findChromeBinary')->andReturn(null);

        return $screenshots;
    }

    private function target(string $extension): string
    {
        $path = sys_get_temp_dir() . '/vela-cloud-shot-' . uniqid() . '.' . $extension;

        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * A PNG big enough to pass the "did anything arrive?" floor.
     *
     * Noise rather than a flat fill: a solid rectangle compresses to a few
     * hundred bytes, which the service's own emptiness check would reject —
     * and a test that trips a guard it is not testing proves nothing.
     */
    private function aPng(): string
    {
        $image = imagecreatetruecolor(1280, 800);

        for ($x = 0; $x < 1280; $x += 4) {
            for ($y = 0; $y < 800; $y += 4) {
                $colour = imagecolorallocate($image, ($x * 7) % 255, ($y * 11) % 255, ($x + $y) % 255);
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $colour);
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
