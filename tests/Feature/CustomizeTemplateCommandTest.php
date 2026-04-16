<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\TestCase;
use VelaBuild\Core\Vela;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class CustomizeTemplateCommandTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $tempTemplateDir = null;
    private ?string $tempTemplateFile = null;
    private array $backupFilesToClean = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('vela.ai.openai.api_key', '');
        config()->set('vela.ai.anthropic.api_key', '');
        config()->set('vela.ai.gemini.api_key', '');
    }

    protected function tearDown(): void
    {
        // Clean up temp template files
        if ($this->tempTemplateFile && file_exists($this->tempTemplateFile)) {
            @unlink($this->tempTemplateFile);
        }
        if ($this->tempTemplateDir && is_dir($this->tempTemplateDir)) {
            @rmdir($this->tempTemplateDir);
        }

        // Clean up backup files
        foreach ($this->backupFilesToClean as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    private function createTempTemplateFile(string $content = '<div>Original content</div>'): array
    {
        $dir = storage_path('app/test-template-' . uniqid());
        mkdir($dir, 0755, true);

        $file = $dir . '/test.blade.php';
        file_put_contents($file, $content);

        $this->tempTemplateDir = $dir;
        $this->tempTemplateFile = $file;

        // Register temp template
        app(Vela::class)->registerTemplate('test-template', [
            'label' => 'Test Template',
            'namespace' => 'test-template',
            'path' => $dir,
        ]);

        return ['dir' => $dir, 'file' => $file];
    }

    private function mockAiManagerWithText(string $text): void
    {
        $mockProvider = \Mockery::mock(AiTextProvider::class);
        $mockProvider->shouldReceive('generateText')->andReturn($text);

        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockProvider);

        $this->instance(AiProviderManager::class, $mockManager);
    }

    public function test_applies_color_config_changes(): void
    {
        $this->artisan('vela:customize-template', [
            '--colors' => '{"--primary":"#ff0000"}',
        ])->assertExitCode(0);

        $this->assertEquals(
            '#ff0000',
            VelaConfig::where('key', 'css_--primary')->value('value')
        );
    }

    public function test_dry_run_shows_without_modifying(): void
    {
        // Make sure no existing record for this key
        $key = 'css_--dry-run-test-color';
        VelaConfig::where('key', $key)->delete();

        $this->artisan('vela:customize-template', [
            '--colors' => '{"--dry-run-test-color":"#abcdef"}',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertNull(
            VelaConfig::where('key', $key)->value('value'),
            'Dry run should not write to the database'
        );
    }

    public function test_rejects_invalid_json_colors(): void
    {
        $this->artisan('vela:customize-template', [
            '--colors' => 'invalid-json',
        ])->assertExitCode(1);
    }

    public function test_backs_up_before_template_edit(): void
    {
        $originalContent = '<div>Original template content</div>';
        $this->createTempTemplateFile($originalContent);

        $validBladeContent = '<div>Updated template content by AI</div>';
        $this->mockAiManagerWithText($validBladeContent);

        $backupDir = storage_path('app/template-backups');
        $basename = 'test.blade.php';

        // Track any backup files before running
        $existingBackups = glob($backupDir . '/' . $basename . '.*.backup') ?: [];

        $this->artisan('vela:customize-template', [
            '--template' => 'test-template',
            '--prompt' => 'Update the template',
            '--file' => 'test.blade.php',
        ])->assertExitCode(0);

        // Find newly created backup files
        $allBackups = glob($backupDir . '/' . $basename . '.*.backup') ?: [];
        $newBackups = array_diff($allBackups, $existingBackups);

        $this->assertNotEmpty($newBackups, 'A backup file should have been created before editing');

        // Register for cleanup
        foreach ($newBackups as $backup) {
            $this->backupFilesToClean[] = $backup;
        }
    }

    public function test_rollback_on_blade_compilation_failure(): void
    {
        $originalContent = '<div>Original content that must be preserved</div>';
        $this->createTempTemplateFile($originalContent);

        // Mock AI to return content with a dangerous pattern that gets rejected
        // OR mock the blade compiler to throw an exception
        $this->mockAiManagerWithText($originalContent);

        // Mock blade compiler to throw during compilation
        $mockCompiler = \Mockery::mock(\Illuminate\View\Compilers\BladeCompiler::class)->makePartial();
        $mockCompiler->shouldReceive('compileString')
            ->andThrow(new \Exception('Simulated Blade compilation failure'));

        $this->app->instance('blade.compiler', $mockCompiler);

        $backupDir = storage_path('app/template-backups');
        $basename = 'test.blade.php';
        $existingBackups = glob($backupDir . '/' . $basename . '.*.backup') ?: [];

        $this->artisan('vela:customize-template', [
            '--template' => 'test-template',
            '--prompt' => 'Update the template',
            '--file' => 'test.blade.php',
        ])->assertExitCode(1);

        // Original file content should be preserved after rollback
        $this->assertEquals(
            $originalContent,
            file_get_contents($this->tempTemplateFile),
            'Original file content should be restored after Blade compilation failure'
        );

        // Clean up any backup files created during this test
        $allBackups = glob($backupDir . '/' . $basename . '.*.backup') ?: [];
        $newBackups = array_diff($allBackups, $existingBackups);
        foreach ($newBackups as $backup) {
            $this->backupFilesToClean[] = $backup;
        }
    }
}
