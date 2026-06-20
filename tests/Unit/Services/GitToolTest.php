<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\AiChat\Tools\GitTool;
use Orchestra\Testbench\TestCase;

class GitToolTest extends TestCase
{
    private GitTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new GitTool();
    }

    public function test_safe_command_runs_without_confirmation(): void
    {
        $result = $this->tool->execute(['subcommand' => 'status']);

        $this->assertArrayNotHasKey('requires_confirmation', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertSame('git status', $result['command']);
    }

    public function test_write_command_requires_confirmation(): void
    {
        $result = $this->tool->execute(['subcommand' => 'commit -m "test"']);

        $this->assertTrue($result['requires_confirmation'] ?? false);
    }

    public function test_blocked_command_is_rejected(): void
    {
        $result = $this->tool->execute(['subcommand' => 'reset --hard HEAD']);

        $this->assertStringContainsString('Blocked', $result['error'] ?? '');
    }

    /**
     * The core security guarantee: shell metacharacters must never reach a shell.
     * With argv execution, an injection attempt is handed to git as a literal
     * argument, so git errors out instead of the injected command running.
     */
    public function test_shell_injection_does_not_execute_arbitrary_commands(): void
    {
        $marker = sys_get_temp_dir() . '/vela_git_injection_' . uniqid() . '.txt';
        $this->assertFileDoesNotExist($marker);

        // Old code: shell_exec("... && git status; touch <marker>") -> file created.
        // New code: git receives "status; touch <marker>" tokens -> nothing runs.
        $this->tool->execute(['subcommand' => 'status; touch ' . escapeshellarg($marker)]);
        $this->assertFileDoesNotExist($marker);

        // Also cover command substitution and piping forms.
        $this->tool->execute(['subcommand' => 'status $(touch ' . escapeshellarg($marker) . ')']);
        $this->assertFileDoesNotExist($marker);

        $this->tool->execute(['subcommand' => 'status | touch ' . escapeshellarg($marker)]);
        $this->assertFileDoesNotExist($marker);

        $this->tool->execute(['subcommand' => 'status && touch ' . escapeshellarg($marker), 'confirm' => true]);
        $this->assertFileDoesNotExist($marker);

        if (file_exists($marker)) {
            unlink($marker);
        }
    }

    public function test_unterminated_quote_is_rejected(): void
    {
        $result = $this->tool->execute(['subcommand' => 'commit -m "unterminated']);

        $this->assertStringContainsString('quotes', $result['error'] ?? '');
    }

    public function test_empty_subcommand_is_rejected(): void
    {
        $result = $this->tool->execute(['subcommand' => '']);

        $this->assertStringContainsString('required', $result['error'] ?? '');
    }
}
