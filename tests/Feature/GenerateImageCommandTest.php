<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiImageProvider;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class GenerateImageCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $filesToDelete = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('vela.ai.openai.api_key', '');
        config()->set('vela.ai.anthropic.api_key', '');
        config()->set('vela.ai.gemini.api_key', '');
    }

    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->filesToDelete = [];
        parent::tearDown();
    }

    private function mockAiManagerWithImage(?string $expectProvider = null): \Mockery\MockInterface
    {
        $mockImageProvider = \Mockery::mock(AiImageProvider::class);
        $mockImageProvider->shouldReceive('generateImage')
            ->andReturn(['data' => [['b64_json' => base64_encode('fake-image-data')]]]);

        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasImageProvider')->andReturn(true);

        if ($expectProvider !== null) {
            $mockManager->shouldReceive('resolveImageProvider')
                ->with($expectProvider)
                ->once()
                ->andReturn($mockImageProvider);
        } else {
            $mockManager->shouldReceive('resolveImageProvider')
                ->andReturn($mockImageProvider);
        }

        $this->instance(AiProviderManager::class, $mockManager);

        return $mockManager;
    }

    public function test_generates_image_with_mock_provider(): void
    {
        $this->mockAiManagerWithImage();

        $output = '/tmp/test-vela-image-' . time() . '.png';
        $this->filesToDelete[] = $output;

        $this->artisan('vela:generate-image', [
            '--prompt' => 'A test image',
            '--output' => $output,
        ])->assertExitCode(0);

        $this->assertFileExists($output);
        $this->assertEquals('fake-image-data', file_get_contents($output));
    }

    public function test_provider_flag_overrides_default(): void
    {
        $this->mockAiManagerWithImage('openai');

        $output = '/tmp/test-vela-provider-flag-' . time() . '.png';
        $this->filesToDelete[] = $output;

        $this->artisan('vela:generate-image', [
            '--prompt' => 'A test image',
            '--provider' => 'openai',
            '--output' => $output,
        ])->assertExitCode(0);
    }

    public function test_dry_run_does_not_generate(): void
    {
        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasImageProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveImageProvider')->never();
        $this->instance(AiProviderManager::class, $mockManager);

        $output = '/tmp/test-vela-dryrun-' . time() . '.png';

        $this->artisan('vela:generate-image', [
            '--prompt' => 'A test image',
            '--dry-run' => true,
            '--output' => $output,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($output);
    }

    public function test_output_flag_saves_to_path(): void
    {
        $this->mockAiManagerWithImage();

        $output = '/tmp/test-image-custom-' . time() . '.png';
        $this->filesToDelete[] = $output;

        $this->artisan('vela:generate-image', [
            '--prompt' => 'A custom path image',
            '--output' => $output,
        ])->assertExitCode(0);

        $this->assertFileExists($output);
    }

    public function test_returns_exit_code_1_when_no_image_provider(): void
    {
        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasImageProvider')->andReturn(false);
        $this->instance(AiProviderManager::class, $mockManager);

        $this->artisan('vela:generate-image', [
            '--prompt' => 'A test image',
        ])->assertExitCode(1);
    }
}
