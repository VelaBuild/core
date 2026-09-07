<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\BrowserInstaller;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The promise this makes to somebody who does not install software.
 *
 * The brief was that a build takes care of its own headless browser, with a
 * cloud service as an optional no-setup route and a local browser as the
 * default. All of that was written and none of it was ever pinned: the machine
 * it was developed on has Chrome in /Applications, so every run took the first
 * branch and the other two were reached by reading only.
 *
 * The download itself is deliberately not exercised here — 350 MB is not a
 * test — but everything that decides WHETHER to download is.
 */
class BrowserForScreenshotsTest extends PackageTestCase
{
    /**
     * A browser already on the machine is used, and nothing is fetched.
     *
     * The order matters more than it looks: a download that fires when there
     * was a perfectly good browser costs a first-time operator ten minutes and
     * 350 MB, and it would do it on a machine where nothing was wrong.
     */
    public function test_a_browser_the_machine_already_has_is_used_as_it_is(): void
    {
        $installer = $this->neverInstalls();

        $screenshots = \Mockery::mock(ScreenshotService::class)->makePartial();
        $screenshots->shouldReceive('findChromeBinary')->andReturn('/usr/bin/chromium');

        $this->assertStringContainsString('/usr/bin/chromium', $screenshots->ensureCaptureRoute());
        $this->assertSame(0, $installer->installs);
    }

    /**
     * With no browser but a rendering service configured, the service is used
     * rather than several hundred megabytes fetched.
     */
    public function test_a_configured_cloud_service_is_preferred_to_a_download(): void
    {
        $installer = $this->neverInstalls();
        config(['vela.browser_rendering.url' => 'https://renderer.example.com']);

        $screenshots = \Mockery::mock(ScreenshotService::class)->makePartial();
        $screenshots->shouldReceive('findChromeBinary')->andReturn(null);

        $this->assertStringContainsString('cloud', $screenshots->ensureCaptureRoute());
        $this->assertSame(0, $installer->installs);
    }

    /** With neither, one is fetched — the whole point of the feature. */
    public function test_with_neither_a_browser_is_fetched(): void
    {
        $installer = $this->neverInstalls();
        config(['vela.browser_rendering.url' => null]);

        $screenshots = \Mockery::mock(ScreenshotService::class)->makePartial();
        $screenshots->shouldReceive('findChromeBinary')->andReturn(null);

        $this->assertStringContainsString('Vela installed', $screenshots->ensureCaptureRoute());
        $this->assertSame(1, $installer->installs);
    }

    /**
     * On a machine nothing is published for, the refusal names both ways out.
     *
     * This is the one message a non-technical operator can be left holding, so
     * it has to say what to do rather than what went wrong.
     */
    public function test_a_machine_with_no_download_is_told_both_ways_out(): void
    {
        $this->app->bind(BrowserInstaller::class, fn () => new class extends BrowserInstaller
        {
            public function platform(): ?string
            {
                return null;
            }
        });

        $screenshots = \Mockery::mock(ScreenshotService::class)->makePartial();
        $screenshots->shouldReceive('findChromeBinary')->andReturn(null);

        try {
            $screenshots->ensureCaptureRoute();
            $this->fail('a machine with no browser and no download should say so');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Chrome', $e->getMessage());
            $this->assertStringContainsString('CLOUDFLARE_BROWSER_RENDERING_URL', $e->getMessage());
        }
    }

    /**
     * A second build does not download a second browser.
     *
     * install() is called on every build, so the guard against paying for the
     * archive twice is the only thing between an operator and a 350 MB fetch
     * every time they press the button.
     */
    public function test_a_browser_already_downloaded_is_not_downloaded_again(): void
    {
        Http::fake();

        $installer = app(BrowserInstaller::class);
        $platform = $installer->platform();

        if ($platform === null) {
            $this->markTestSkipped('no download is published for this machine');
        }

        $binary = $this->stageAnInstalledBrowser($installer, $platform);

        $this->assertSame($binary, $installer->install());
        $this->assertSame($binary, $installer->installedBinary());
        // Not even the version listing: an installed browser asks nothing of
        // the network at all.
        Http::assertNothingSent();
    }

    /** A file that is there but cannot be run is not a browser. */
    public function test_a_browser_that_cannot_be_executed_does_not_count_as_installed(): void
    {
        $installer = app(BrowserInstaller::class);
        $platform = $installer->platform();

        if ($platform === null) {
            $this->markTestSkipped('no download is published for this machine');
        }

        $binary = $this->stageAnInstalledBrowser($installer, $platform);
        chmod($binary, 0644);

        $this->assertNull($installer->installedBinary());
    }

    /**
     * With no browser and no service, the refusal tells the operator the three
     * things that would fix it — this is what a build stops on.
     */
    public function test_a_capture_with_nowhere_to_run_says_what_to_do(): void
    {
        config(['vela.browser_rendering.url' => null]);

        $screenshots = \Mockery::mock(ScreenshotService::class)->makePartial();
        $screenshots->shouldReceive('findChromeBinary')->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('install one');

        $screenshots->capture('https://example.com', sys_get_temp_dir() . '/vela-test-shot.png');
    }

    /**
     * An installer that would fail the test rather than fetch anything.
     */
    private function neverInstalls(): object
    {
        $installer = new class extends BrowserInstaller
        {
            public int $installs = 0;

            public function installedBinary(): ?string
            {
                return null;
            }

            public function install(?\Closure $progress = null): string
            {
                $this->installs++;

                return '/downloaded/chrome';
            }
        };

        $this->app->instance(BrowserInstaller::class, $installer);

        return $installer;
    }

    /** Put a runnable file where a downloaded browser would have landed. */
    private function stageAnInstalledBrowser(BrowserInstaller $installer, string $platform): string
    {
        $reflection = new \ReflectionClass($installer);
        $binary = $installer->directory() . '/' . $reflection->getConstant('BINARIES')[$platform];

        File::ensureDirectoryExists(dirname($binary));
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($installer->directory()));

        return $binary;
    }
}
