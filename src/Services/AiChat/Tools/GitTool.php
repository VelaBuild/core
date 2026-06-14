<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Illuminate\Support\Facades\Log;

class GitTool extends BaseTool
{
    private const SAFE_COMMANDS = [
        'status', 'log', 'diff', 'branch', 'show', 'stash list',
        'remote -v', 'tag', 'describe',
    ];

    private const NEEDS_CONFIRM = [
        'add', 'commit', 'push', 'pull', 'stash', 'stash pop',
        'stash drop', 'checkout', 'switch', 'merge', 'rebase',
        'tag -a', 'tag -d', 'branch -d', 'branch -D',
    ];

    private const BLOCKED = [
        'reset --hard', 'push --force', 'push -f', 'clean -fd',
        'rebase -i', 'filter-branch', 'reflog expire',
    ];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $subcommand = trim($parameters['subcommand'] ?? '');
        if (!$subcommand) {
            return ['error' => 'subcommand is required (e.g. "status", "add .", "commit -m msg")'];
        }

        if ($this->isBlocked($subcommand)) {
            return ['error' => "Blocked: git {$subcommand} — destructive git operations are not allowed"];
        }

        if ($this->needsConfirm($subcommand) && !($parameters['confirm'] ?? false)) {
            return [
                'requires_confirmation' => true,
                'command' => "git {$subcommand}",
                'message' => "This git operation modifies the repository. Call git tool again with confirm: true to proceed.",
            ];
        }

        $base = base_path();
        $cmd = 'git ' . $subcommand;

        Log::info('AI chat git command', ['command' => $cmd]);

        $output = shell_exec("cd " . escapeshellarg($base) . " && {$cmd} 2>&1") ?? '';

        return [
            'command' => "git {$subcommand}",
            'output' => mb_substr($output, 0, 30_000),
        ];
    }

    private function isBlocked(string $sub): bool
    {
        foreach (self::BLOCKED as $pattern) {
            if (str_starts_with($sub, $pattern)) return true;
        }
        return false;
    }

    private function needsConfirm(string $sub): bool
    {
        foreach (self::SAFE_COMMANDS as $safe) {
            if (str_starts_with($sub, $safe)) return false;
        }
        return true;
    }
}
