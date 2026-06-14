<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Illuminate\Support\Facades\Log;

class RunCommandTool extends BaseTool
{
    private const ALLOWED_COMMANDS = [
        'php artisan',
        'composer require',
        'composer remove',
        'composer update',
        'npm install',
        'npm run',
        'npx',
    ];

    private const BLOCKED_PATTERNS = [
        '/rm\s+-rf/',
        '/drop\s+database/i',
        '/drop\s+table/i',
        '/truncate/i',
        '/\bsudo\b/',
        '/\bchmod\b.*777/',
        '/\bkill\b/',
        '/\bshutdown\b/',
        '/\breboot\b/',
        '/>\s*\/etc\//',
        '/\bpasswd\b/',
        '/\bcurl\b.*\|\s*bash/',
        '/\bwget\b.*\|\s*bash/',
    ];

    private const MAX_OUTPUT = 50_000;
    private const TIMEOUT = 120;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $command = trim($parameters['command'] ?? '');
        if (!$command) {
            return ['error' => 'command is required'];
        }

        if (!$this->isAllowed($command)) {
            return ['error' => "Command not in allowlist. Allowed prefixes: " . implode(', ', self::ALLOWED_COMMANDS)];
        }

        if ($this->isBlocked($command)) {
            return ['error' => 'Command blocked by safety rules'];
        }

        Log::info('AI chat executing command', ['command' => $command]);

        $cwd = base_path();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $cwd, null);

        if (!is_resource($proc)) {
            return ['error' => 'Failed to start process'];
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            if (time() - $start > self::TIMEOUT) {
                proc_terminate($proc);
                return ['error' => 'Command timed out after ' . self::TIMEOUT . 's', 'stdout' => substr($stdout, 0, self::MAX_OUTPUT)];
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            usleep(50_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [
            'exit_code' => $exitCode,
            'stdout' => mb_substr($stdout, 0, self::MAX_OUTPUT),
            'stderr' => mb_substr($stderr, 0, self::MAX_OUTPUT),
        ];
    }

    private function isAllowed(string $command): bool
    {
        foreach (self::ALLOWED_COMMANDS as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isBlocked(string $command): bool
    {
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }
        return false;
    }
}
