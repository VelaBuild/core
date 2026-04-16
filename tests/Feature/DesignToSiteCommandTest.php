<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Tests\TestCase;

class DesignToSiteCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $tempDirs = [];
    private array $createdConfigKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*') ?: []);
                rmdir($dir);
            }
        }
        foreach ($this->createdConfigKeys as $key) {
            VelaConfig::where('key', $key)->delete();
        }
        parent::tearDown();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/design-test-' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function addDummyPng(string $dir, string $name = 'design.png'): void
    {
        $img = imagecreatetruecolor(1, 1);
        imagepng($img, $dir . '/' . $name);
        imagedestroy($img);
    }

    private function mockAiManagerWithVision(): void
    {
        $mockProvider = \Mockery::mock(AiTextProvider::class);
        $mockProvider->shouldReceive('supportsVision')->andReturn(true);

        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockProvider);

        $this->app->instance(AiProviderManager::class, $mockManager);
    }

    private function mockScreenshotAvailable(bool $available = true): void
    {
        $mock = \Mockery::mock(ScreenshotService::class);
        $mock->shouldReceive('isAvailable')->andReturn($available);

        $this->app->instance(ScreenshotService::class, $mock);
    }

    public function test_exits_1_without_ai_provider(): void
    {
        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(false);
        $this->app->instance(AiProviderManager::class, $mockManager);

        $this->artisan('vela:design-to-site')
            ->assertExitCode(1);
    }

    public function test_exits_1_when_provider_lacks_vision(): void
    {
        $mockProvider = \Mockery::mock(AiTextProvider::class);
        $mockProvider->shouldReceive('supportsVision')->andReturn(false);

        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockProvider);

        $this->app->instance(AiProviderManager::class, $mockManager);

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

        $this->artisan('vela:design-to-site', ['--design-path' => $tempDir])
            ->expectsConfirmation('Continue? Use --force to skip this prompt.', 'no')
            ->assertExitCode(0);
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

        $mockScreenshot = \Mockery::mock(ScreenshotService::class);
        $mockScreenshot->shouldReceive('isAvailable')->andReturn(true);
        $mockScreenshot->shouldReceive('capture')->andReturn($fakeScreenshotPath);
        $this->app->instance(ScreenshotService::class, $mockScreenshot);

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $testContext = [
            'assets'            => [['file' => 'design.png', 'type' => 'image', 'size' => 100, 'role' => 'design']],
            'instructions'      => [],
            'created_resources' => [],
        ];

        $mockBuilder = \Mockery::mock(DesignBuilderService::class);
        $mockBuilder->shouldReceive('onProgress')->once();
        $mockBuilder->shouldReceive('generateContext')->andReturn($testContext);
        $mockBuilder->shouldReceive('runBuildLoop')->once();
        $mockBuilder->shouldReceive('runQaComparison')->once()->andReturn([
            'passed'  => true,
            'summary' => 'Looks great',
            'fixes'   => [],
            'report'  => '# Passed',
            'usage'   => ['input' => 0, 'output' => 0],
        ]);

        $this->app->instance(DesignBuilderService::class, $mockBuilder);

        $this->artisan('vela:design-to-site', [
            '--design-path' => $tempDir,
            '--force'       => true,
        ])->assertExitCode(0);
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

        $mockBuilder = \Mockery::mock(DesignBuilderService::class);
        $mockBuilder->shouldReceive('onProgress')->once();
        $mockBuilder->shouldReceive('generateContext')->andReturn($testContext);
        $mockBuilder->shouldNotReceive('runBuildLoop');

        $this->app->instance(DesignBuilderService::class, $mockBuilder);

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
