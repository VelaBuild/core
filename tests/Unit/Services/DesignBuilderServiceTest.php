<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\SiteContext;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Tests\PackageTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;

class DesignBuilderServiceTest extends PackageTestCase
{

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

        return new DesignBuilderService($aiManager, $toolRegistry, $toolExecutor, $siteContext, app(ScreenshotService::class));
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

    /**
     * A builder whose photographing and looking are stubbed, so the decision
     * the conversion check makes can be tested without a browser or a model.
     */
    private function makeCheckingService(?array $verdict, bool $canPhotograph = true): DesignBuilderService
    {
        return new class (
            Mockery::mock(AiProviderManager::class),
            app(ChatToolRegistry::class),
            app(ChatToolExecutor::class),
            app(SiteContext::class),
            app(ScreenshotService::class),
            $verdict,
            $canPhotograph
        ) extends DesignBuilderService {
            public array $captured = [];

            public function __construct($a, $b, $c, $d, $e, private ?array $verdict, private bool $canPhotograph)
            {
                parent::__construct($a, $b, $c, $d, $e);
            }

            protected function safeSectionCapture(string $url, string $handle, string $path): ?string
            {
                $this->captured[] = $handle;

                return $this->canPhotograph ? $path : null;
            }

            protected function compareSection(string $before, string $after): ?array
            {
                return $this->verdict;
            }

            public function check(array $toolCall, string $url, \Closure $run): array
            {
                return $this->keepingTheLook($toolCall, $url, $run);
            }
        };
    }

    public function test_a_conversion_that_kept_the_look_is_left_alone(): void
    {
        $service = $this->makeCheckingService(['same' => true, 'differences' => 'nothing']);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'hero']],
            'http://localhost',
            fn () => ['success' => true, 'converted' => true]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['#row-7', '#row-7'], $service->captured, 'before and after, by the row id the theme renders');
    }

    public function test_a_conversion_that_changed_the_look_comes_back_as_an_error(): void
    {
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'the three cards became a list']);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'icon_box']],
            'http://localhost',
            fn () => ['success' => true, 'converted' => true]
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('three cards became a list', $result['error']);
        $this->assertFalse($result['converted']);
    }

    public function test_a_conversion_stands_where_there_is_nothing_to_photograph(): void
    {
        // No Chrome, or a page the build cannot reach. An unmeasurable change is
        // not a failed one; refusing it would take the feature away from exactly
        // the sites most likely to need it.
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'everything'], canPhotograph: false);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'hero']],
            'http://localhost',
            fn () => ['success' => true, 'converted' => true]
        );

        $this->assertTrue($result['success']);
    }

    public function test_a_conversion_that_the_tool_refused_is_not_photographed_afterwards(): void
    {
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'x']);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'pricing_tiers']],
            'http://localhost',
            fn () => ['error' => 'pricing_tiers is not a shape a section can be read into.']
        );

        $this->assertSame('pricing_tiers is not a shape a section can be read into.', $result['error']);
        $this->assertSame(['#row-7'], $service->captured, 'the "after" picture is of a change that never happened');
    }

    public function test_every_other_tool_call_goes_straight_through(): void
    {
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'x']);

        $result = $service->check(
            ['name' => 'add_designed_section', 'arguments' => ['name' => 'Hero']],
            'http://localhost',
            fn () => ['success' => true]
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $service->captured);
    }
}
