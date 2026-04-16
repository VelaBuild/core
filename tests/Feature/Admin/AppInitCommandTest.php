<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;

class AppInitCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_init_fails_without_node()
    {
        Process::fake([
            'node --version' => Process::result(output: '', errorOutput: 'command not found', exitCode: 127),
        ]);

        $this->artisan('vela:app-init', ['--app-id' => 'com.test.app'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Node.js');
    }

    public function test_init_fails_on_existing_dir_without_force()
    {
        $dir = base_path('capacitor');
        @mkdir($dir, 0755, true);

        Process::fake([
            'node --version' => Process::result(output: 'v20.0.0', exitCode: 0),
            'npm --version' => Process::result(output: '10.0.0', exitCode: 0),
        ]);

        try {
            $this->artisan('vela:app-init', ['--app-id' => 'com.test.app'])
                ->assertExitCode(1)
                ->expectsOutputToContain('already exists');
        } finally {
            @rmdir($dir);
        }
    }

    public function test_dry_run_creates_no_files()
    {
        // Clean up if exists
        $dir = base_path('capacitor');
        if (is_dir($dir)) {
            @rmdir($dir);
        }

        Process::fake([
            'node --version' => Process::result(output: 'v20.0.0', exitCode: 0),
            'npm --version' => Process::result(output: '10.0.0', exitCode: 0),
        ]);

        $this->artisan('vela:app-init', ['--app-id' => 'com.test.app', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_init_validates_app_id_format()
    {
        Process::fake([
            'node --version' => Process::result(output: 'v20.0.0', exitCode: 0),
            'npm --version' => Process::result(output: '10.0.0', exitCode: 0),
        ]);

        $this->artisan('vela:app-init', ['--app-id' => 'invalid'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid app ID');
    }
}
