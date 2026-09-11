<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\ScreenshotService;
use Illuminate\Support\Facades\File;
use VelaBuild\Core\Tests\TestCase;

class DesignToSiteCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $tempDirs = [];
    private array $createdConfigKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            // Recursively: a run leaves an `output` directory inside the temp
            // folder, and unlink() refuses a directory — which surfaced as
            // "Operation not permitted" out of tearDown rather than as
            // anything to do with the test.
            File::deleteDirectory($dir);
        }
        foreach ($this->createdConfigKeys as $key) {
            VelaConfig::where('key', $key)->delete();
        }
        parent::tearDown();
    }

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/design-test-' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    protected function addDummyPng(string $dir, string $name = 'design.png'): void
    {
        $img = imagecreatetruecolor(1, 1);
        imagepng($img, $dir . '/' . $name);
        imagedestroy($img);
    }

    protected function visionProvider(): \Mockery\MockInterface
    {
        $provider = \Mockery::mock(AiTextProvider::class)->shouldIgnoreMissing();
        $provider->shouldReceive('supportsVision')->andReturn(true);
        // The command asks the provider to answer something before it trusts
        // it — a step added after these doubles were written.
        $provider->shouldReceive('generateText')->andReturn('ok');

        return $provider;
    }

    protected function mockAiManagerWithVision(): void
    {
        $mockProvider = $this->visionProvider();

        $mockManager = \Mockery::mock(AiProviderManager::class)->shouldIgnoreMissing();
        $mockManager->shouldReceive('hasTextProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockProvider);

        $this->instance(AiProviderManager::class, $mockManager);
    }

    protected function mockScreenshotAvailable(bool $available = true): void
    {
        $mock = \Mockery::mock(ScreenshotService::class)->shouldIgnoreMissing();
        $mock->shouldReceive('isAvailable')->andReturn($available);

        $this->instance(ScreenshotService::class, $mock);
    }

    public function test_exits_1_without_ai_provider(): void
    {
        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(false);
        $this->instance(AiProviderManager::class, $mockManager);

        $this->artisan('vela:design-to-site')
            ->assertExitCode(1);
    }

    public function test_exits_1_when_provider_lacks_vision(): void
    {
        $mockProvider = \Mockery::mock(AiTextProvider::class);
        $mockProvider->shouldReceive('supportsVision')->andReturn(false);

        $mockManager = \Mockery::mock(AiProviderManager::class)->shouldIgnoreMissing();
        $mockManager->shouldReceive('hasTextProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockProvider);

        $this->instance(AiProviderManager::class, $mockManager);

        $this->mockScreenshotAvailable(true);

        $this->artisan('vela:design-to-site')
            ->assertExitCode(1);
    }

    public function test_exits_1_without_chrome(): void
    {
        $this->mockAiManagerWithVision();
        $this->mockScreenshotAvailable(false);

        $this->artisan('vela:design-to-site')
            ->assertExitCode(1);
    }

    public function test_exits_1_with_empty_design_folder(): void
    {
        $tempDir = $this->makeTempDir();

        $this->mockAiManagerWithVision();
        $this->mockScreenshotAvailable(true);

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $this->artisan('vela:design-to-site', [
            '--design-path' => $tempDir,
            '--force'       => true,
        ])->assertExitCode(1);
    }

    public function test_warns_and_requires_force_for_existing_content(): void
    {
        $tempDir = $this->makeTempDir();
        $this->addDummyPng($tempDir);

        $key = 'css_--primary-' . uniqid();
        VelaConfig::create(['key' => $key, 'value' => '#ff0000']);
        $this->createdConfigKeys[] = $key;

        $this->mockAiManagerWithVision();
        $this->mockScreenshotAvailable(true);

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        // Declining ends the run with 1 on purpose. It used to answer 0 —
        // a success code for a build that never happened, with nothing said
        // about why — so a caller could not tell the two apart.
        $this->artisan('vela:design-to-site', ['--design-path' => $tempDir])
            ->expectsConfirmation('Continue? Use --force to skip this prompt.', 'no')
            ->assertExitCode(1);
    }

    public function test_force_flag_skips_overwrite_warning(): void
    {
        $tempDir = $this->makeTempDir();
        $this->addDummyPng($tempDir);

        $key = 'css_--primary-' . uniqid();
        VelaConfig::create(['key' => $key, 'value' => '#ff0000']);
        $this->createdConfigKeys[] = $key;

        $this->mockAiManagerWithVision();

        // Create a fake screenshot file so filesize check passes
        $fakeScreenshotPath = $tempDir . '/loop_1_screenshot.png';
        $this->addDummyPng($tempDir, 'loop_1_screenshot.png');
        // Pad it to be > 1024 bytes
        file_put_contents($fakeScreenshotPath, str_repeat('X', 2048), FILE_APPEND);

        $mockScreenshot = \Mockery::mock(ScreenshotService::class)->shouldIgnoreMissing();
        $mockScreenshot->shouldReceive('isAvailable')->andReturn(true);
        $mockScreenshot->shouldReceive('capture')->andReturn($fakeScreenshotPath);
        $this->instance(ScreenshotService::class, $mockScreenshot);

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $testContext = [
            'assets'            => [['file' => 'design.png', 'type' => 'image', 'size' => 100, 'role' => 'design']],
            'instructions'      => [],
            'created_resources' => [],
        ];

        $mockBuilder = \Mockery::mock(DesignBuilderService::class)->shouldIgnoreMissing();
        $mockBuilder->shouldReceive('provider')->andReturn($this->visionProvider());
        $mockBuilder->shouldReceive('onProgress');
        // Called once: proof the run got past the overwrite gate, which is
        // what --force is for. Mockery verifies it at teardown.
        $mockBuilder->shouldReceive('generateContext')->once()->andReturn($testContext);
        $mockBuilder->shouldReceive('runBuildLoop')->once();
        $mockBuilder->shouldReceive('runQaComparison')->andReturn([
            'passed'  => true,
            'summary' => 'Looks great',
            'fixes'   => [],
            'report'  => '# Passed',
            'usage'   => ['input' => 0, 'output' => 0],
        ]);

        $this->instance(DesignBuilderService::class, $mockBuilder);

        // What --force is for: the overwrite question is not asked, and the
        // run gets past that gate to generate its context.
        //
        // Not asserting exit 0. runBuildLoop is a double here, so it creates
        // no theme, and the command refuses to call a build that left the
        // preview page wearing the site's existing theme a success — a guard
        // added after this test was written, and the right answer.
        $this->artisan('vela:design-to-site', [
            '--design-path' => $tempDir,
            '--force'       => true,
        ])->doesntExpectOutputToContain('Use --force to skip this prompt.');
    }

    public function test_dry_run_shows_plan_without_executing(): void
    {
        $tempDir = $this->makeTempDir();
        $this->addDummyPng($tempDir);
        file_put_contents($tempDir . '/README.md', '# Design Notes');

        $this->mockAiManagerWithVision();
        $this->mockScreenshotAvailable(true);

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $testContext = [
            'assets'            => [['file' => 'design.png', 'type' => 'image', 'size' => 100, 'role' => 'design']],
            'instructions'      => [['file' => 'README.md', 'content' => '# Design Notes']],
            'created_resources' => [],
        ];

        $mockBuilder = \Mockery::mock(DesignBuilderService::class)->shouldIgnoreMissing();
        $mockBuilder->shouldReceive('provider')->andReturn($this->visionProvider());
        $mockBuilder->shouldReceive('onProgress');
        $mockBuilder->shouldReceive('generateContext')->andReturn($testContext);
        $mockBuilder->shouldNotReceive('runBuildLoop');

        $this->instance(DesignBuilderService::class, $mockBuilder);

        $this->artisan('vela:design-to-site', [
            '--design-path' => $tempDir,
            '--dry-run'     => true,
            '--force'       => true,
        ])->assertExitCode(0);
    }

    public function test_figma_url_requires_token(): void
    {
        config()->set('vela.ai.figma.access_token', null);
        putenv('FIGMA_ACCESS_TOKEN=');

        $this->mockAiManagerWithVision();
        $this->mockScreenshotAvailable(true);

        $this->artisan('vela:design-to-site', [
            '--figma-url' => 'https://www.figma.com/file/abc123/test',
        ])->assertExitCode(1);
    }
}
