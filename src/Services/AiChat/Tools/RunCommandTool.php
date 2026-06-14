<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Illuminate\Support\Facades\Log;

class RunCommandTool extends BaseTool
{
    // Safe artisan commands — auto-approved, no confirmation needed
    private const SAFE_ARTISAN = [
        'php artisan route:list',
        'php artisan route:clear',
        'php artisan view:clear',
        'php artisan cache:clear',
        'php artisan config:clear',
        'php artisan config:cache',
        'php artisan event:list',
        'php artisan schedule:list',
        'php artisan queue:work',
        'php artisan queue:restart',
        'php artisan test',
        'php artisan migrate:status',
        'php artisan make:',
        'php artisan tinker --execute',
        'php artisan storage:link',
        'php artisan optimize',
        'php artisan optimize:clear',
        'php artisan about',
        'php artisan list',
        'php artisan vela:',
    ];

    // Dangerous artisan commands — require confirm: true
    private const DANGEROUS_ARTISAN = [
        'php artisan migrate:fresh',
        'php artisan migrate:reset',
        'php artisan migrate:rollback',
        'php artisan db:wipe',
        'php artisan db:seed',
        'php artisan key:generate',
        'php artisan down',
        'php artisan env:encrypt',
        'php artisan env:decrypt',
    ];

    private const ALLOWED_PREFIXES = [
        'php artisan',
        'composer require',
        'composer remove',
        'composer update',
        'composer dump-autoload',
        'composer show',
        'composer outdated',
        'npm install',
        'npm run',
        'npm list',
        'npx',
        'grep ',
        'find ',
        'wc ',
        'cat ',
        'head ',
        'tail ',
        'ls ',
        'diff ',
    ];

    private const BLOCKED_PATTERNS = [
        '/rm\s+-rf/',
        '/rm\s+.*vendor/',
        '/\bsudo\b/',
        '/\bchmod\b.*777/',
        '/\bkill\b/',
        '/\bshutdown\b/',
        '/\breboot\b/',
        '/>\s*\/etc\//',
        '/\bpasswd\b/',
        '/\bcurl\b.*\|\s*(ba)?sh/',
        '/\bwget\b.*\|\s*(ba)?sh/',
        '/\beval\b/',
        '/\bexec\b/',
        '/>\s*\.env/',
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
            return ['error' => "Command not allowed. Allowed: php artisan, composer, npm, grep, find, ls, cat, head, tail, wc, diff"];
        }

        if ($this->isBlocked($command)) {
            return ['error' => 'Command blocked by safety rules'];
        }

        if ($this->isDangerous($command)) {
            if (!($parameters['confirm'] ?? false)) {
                return [
                    'requires_confirmation' => true,
                    'command' => $command,
                    'message' => "This is a destructive command. Call run_command again with confirm: true to proceed.",
                ];
            }
            Log::warning('AI chat executing dangerous command with confirmation', ['command' => $command]);
        }

        Log::info('AI chat executing command', ['command' => $command]);

        return $this->runProcess($command);
    }

    private function runProcess(string $command): array
    {
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
        foreach (self::ALLOWED_PREFIXES as $prefix) {
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

    private function isDangerous(string $command): bool
    {
        foreach (self::DANGEROUS_ARTISAN as $dangerous) {
            if (str_starts_with($command, $dangerous)) {
                return true;
            }
        }
        return false;
    }
}
