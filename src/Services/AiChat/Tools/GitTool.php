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

    private const MAX_OUTPUT = 30_000;
    private const TIMEOUT = 120;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $subcommand = trim($parameters['subcommand'] ?? '');
        if (!$subcommand) {
            return ['error' => 'subcommand is required (e.g. "status", "add .", "commit -m msg")'];
        }

        // Tokenize into individual arguments so we can run git without a shell.
        // This is what makes the tool injection-proof: arguments are passed as an
        // argv array to proc_open (execvp), so shell metacharacters such as
        // ; | & $() ` && in a subcommand are never interpreted by a shell.
        $args = $this->tokenize($subcommand);
        if ($args === null) {
            return ['error' => 'Could not parse subcommand: unbalanced or unterminated quotes'];
        }
        if (empty($args)) {
            return ['error' => 'subcommand is required (e.g. "status", "add .", "commit -m msg")'];
        }

        // Build a normalized representation from the parsed tokens for policy
        // matching, so the allowlist/blocklist can't be tricked by extra
        // whitespace or shell punctuation that the old string matching missed.
        $normalized = implode(' ', $args);

        if ($this->isBlocked($normalized)) {
            return ['error' => "Blocked: git {$normalized} — destructive git operations are not allowed"];
        }

        if ($this->needsConfirm($normalized) && !($parameters['confirm'] ?? false)) {
            return [
                'requires_confirmation' => true,
                'command' => "git {$normalized}",
                'message' => "This git operation modifies the repository. Call git tool again with confirm: true to proceed.",
            ];
        }

        Log::info('AI chat git command', ['command' => 'git ' . $normalized]);

        return $this->runGit($args);
    }

    /**
     * Run git with the given argument list via proc_open's array form, which
     * executes the program directly (no shell), preventing command injection.
     *
     * @param string[] $args
     */
    private function runGit(array $args): array
    {
        $cmd = array_merge(['git'], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $proc = proc_open($cmd, $descriptors, $pipes, base_path(), null);
        if (!is_resource($proc)) {
            return ['error' => 'Failed to start git process'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $start = time();

        while (true) {
            $status = proc_get_status($proc);
            $output .= stream_get_contents($pipes[1]) ?: '';
            $output .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                break;
            }
            if (time() - $start > self::TIMEOUT) {
                proc_terminate($proc);
                proc_close($proc);
                return [
                    'command' => 'git ' . implode(' ', $args),
                    'error' => 'git command timed out after ' . self::TIMEOUT . 's',
                    'output' => mb_substr($output, 0, self::MAX_OUTPUT),
                ];
            }
            usleep(50_000);
        }

        $output .= stream_get_contents($pipes[1]) ?: '';
        $output .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [
            'command' => 'git ' . implode(' ', $args),
            'output' => mb_substr($output, 0, self::MAX_OUTPUT),
        ];
    }

    /**
     * Split a subcommand string into an argv array, honouring single and double
     * quotes and backslash escapes (so e.g. commit -m "a message" stays one arg).
     * Returns null on unterminated quotes.
     *
     * @return string[]|null
     */
    private function tokenize(string $input): ?array
    {
        $args = [];
        $current = '';
        $inToken = false;
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $inToken = true;
                $i++;
                while ($i < $len && $input[$i] !== $quote) {
                    // Inside double quotes a backslash escapes the next char.
                    if ($quote === '"' && $input[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                    }
                    $current .= $input[$i];
                    $i++;
                }
                if ($i >= $len) {
                    return null; // unterminated quote
                }
                continue;
            }

            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $input[$i + 1];
                $inToken = true;
                $i++;
                continue;
            }

            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                if ($inToken) {
                    $args[] = $current;
                    $current = '';
                    $inToken = false;
                }
                continue;
            }

            $current .= $ch;
            $inToken = true;
        }

        if ($inToken) {
            $args[] = $current;
        }

        return $args;
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
