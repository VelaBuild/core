<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;
use VelaBuild\Core\Vela;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ThemeCheckCommandTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_static_check_passes_for_default_theme(): void
    {
        $this->artisan('vela:theme-check', ['--theme' => 'default', '--mode' => 'static'])
            ->assertExitCode(0);
    }

    public function test_static_check_passes_for_minimal_theme(): void
    {
        $this->artisan('vela:theme-check', ['--theme' => 'minimal', '--mode' => 'static'])
            ->assertExitCode(0);
    }

    public function test_static_check_detects_missing_files(): void
    {
        $this->tempDir = storage_path('app/test-theme-' . uniqid());
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/layout.blade.php', '<html lang="en"><head>@include("vela::templates._partials.meta-seo")@include("vela::templates._partials.meta-opengraph")@include("vela::templates._partials.meta-pwa")@include("vela::templates._partials.hreflang")@include("vela::templates._partials.analytics")@include("vela::templates._partials.custom-css")</head><body><nav></nav><main>@yield("content")</main><footer></footer>@include("vela::templates._partials.scripts-footer")</body></html>');

        app(Vela::class)->registerTemplate('test-incomplete', [
            'label' => 'Test',
            'namespace' => 'test-incomplete',
            'path' => $this->tempDir,
        ]);

        $this->artisan('vela:theme-check', ['--theme' => 'test-incomplete', '--mode' => 'static'])
            ->assertExitCode(1);
    }

    public function test_json_output_format(): void
    {
        $this->artisan('vela:theme-check', ['--theme' => 'default', '--mode' => 'static', '--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('"default"');
    }

    public function test_invalid_mode_returns_error(): void
    {
        $this->artisan('vela:theme-check', ['--mode' => 'invalid'])
            ->assertExitCode(1);
    }

    public function test_nonexistent_theme_returns_error(): void
    {
        $this->artisan('vela:theme-check', ['--theme' => 'nonexistent'])
            ->assertExitCode(1);
    }

    public function test_checks_all_themes_when_no_theme_specified(): void
    {
        $this->artisan('vela:theme-check', ['--mode' => 'static', '--json' => true])
            ->assertExitCode(0);
    }
}
