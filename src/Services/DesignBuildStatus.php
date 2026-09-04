<?php

namespace VelaBuild\Core\Services;

/**
 * The running commentary of a design build, written where a browser can read it.
 *
 * A build takes minutes and runs in a process of its own, so the admin page
 * that started it has no other way to know how it is getting on. The command
 * writes every line it prints here; the page reads the file back.
 */
class DesignBuildStatus
{
    private string $path;

    public function __construct(string $designPath)
    {
        $this->path = rtrim($designPath, '/') . '/output/status.json';
    }

    public function file(): string
    {
        return $this->path;
    }

    public function start(int $maxLoops): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->put([
            'state' => 'running',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'max_loops' => $maxLoops,
            'phase' => 'checks',
            'phase_done' => 0,
            'phase_total' => 1,
            'lines' => [],
            'error' => null,
        ]);
    }

    /**
     * The phases of a build, in order, and what share of the wait each is.
     *
     * A bar has to be honest or it is worse than no bar, and "how far through
     * is it" has no exact answer here: a build is a conversation with a model
     * that may take four tool calls or forty. So the phases are weighted by
     * how long they actually take — measured across the runs on the test rig,
     * not guessed — and progress WITHIN a phase is reported by whatever is
     * countable there: the tool call being made, the QA round being run.
     *
     * The bar can therefore reach a phase early and sit in it. That is the
     * truth of the thing; what it must never do is go backwards or claim to
     * be finished while work is left, and the weights are what guarantee it.
     */
    public const PHASES = [
        'checks' => ['weight' => 4, 'label' => 'Checking what it needs'],
        'reading' => ['weight' => 11, 'label' => 'Reading your design'],
        'building' => ['weight' => 40, 'label' => 'Building the page'],
        'qa' => ['weight' => 43, 'label' => 'Comparing it with your design'],
        'finishing' => ['weight' => 2, 'label' => 'Finishing off'],
    ];

    /**
     * Say which phase the build is in, and how far into it.
     *
     * $done/$total is whatever this phase can count. Pass nothing for a phase
     * that cannot count anything — it then reads as "started, not finished",
     * which is all that is known.
     */
    public function stage(string $phase, int $done = 0, int $total = 1): void
    {
        $status = $this->read();

        if (!$status || !isset(self::PHASES[$phase])) {
            return;
        }

        $this->put(array_merge($status, [
            'phase' => $phase,
            'phase_done' => max(0, $done),
            'phase_total' => max(1, $total),
        ]));
    }

    /**
     * How far through, as a percentage, from a phase and its own count.
     *
     * @param  array<string, mixed> $status
     */
    public static function percentOf(array $status): int
    {
        if (($status['state'] ?? '') === 'done') {
            return 100;
        }

        $phase = (string) ($status['phase'] ?? 'checks');
        $before = 0;
        $weight = 0;

        foreach (self::PHASES as $name => $spec) {
            if ($name === $phase) {
                $weight = $spec['weight'];
                break;
            }

            $before += $spec['weight'];
        }

        $total = max(1, (int) ($status['phase_total'] ?? 1));
        $done = min($total, max(0, (int) ($status['phase_done'] ?? 0)));

        // Never the full weight of a phase still being worked in: a bar that
        // shows the next phase's number before that phase has begun is the
        // one that later appears to go backwards.
        $within = $total > 0 ? min(0.97, $done / $total) : 0;

        return (int) min(99, round($before + $weight * $within));
    }

    /** What the person watching should be told is happening. */
    public static function labelOf(array $status): string
    {
        if (($status['state'] ?? '') === 'done') {
            return 'Finished';
        }

        if (($status['state'] ?? '') === 'failed') {
            return 'Stopped';
        }

        $phase = (string) ($status['phase'] ?? 'checks');
        $label = self::PHASES[$phase]['label'] ?? 'Working';

        // The QA rounds are the one phase whose count means something to the
        // person watching — they chose the number.
        if ($phase === 'qa' && ($status['phase_total'] ?? 0) > 0) {
            $label .= ' — round ' . min(
                (int) $status['phase_total'],
                (int) ($status['phase_done'] ?? 0) + 1
            ) . ' of ' . (int) $status['phase_total'];
        }

        return $label;
    }

    public function line(string $message): void
    {
        $status = $this->read();
        if (!$status) {
            return;
        }

        $status['lines'][] = ['at' => now()->toIso8601String(), 'text' => $message];

        // A run that thrashes could otherwise grow this file without limit,
        // and the page only ever shows the recent end of it anyway.
        if (count($status['lines']) > 400) {
            $status['lines'] = array_slice($status['lines'], -400);
        }

        $this->put($status);
    }

    public function finish(bool $succeeded, ?string $error = null): void
    {
        $status = $this->read() ?: [];

        $this->put(array_merge($status, [
            'state' => $succeeded ? 'done' : 'failed',
            'finished_at' => now()->toIso8601String(),
            'error' => $error,
        ]));
    }

    public function read(bool $withProgress = false): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (!is_array($decoded)) {
            return null;
        }

        // Worked out on the way out rather than stored: the percentage is a
        // reading of the file, not a fact in it, and computing it here means
        // a run started before this existed still reports something sensible.
        if ($withProgress) {
            $decoded['percent'] = self::percentOf($decoded);
            $decoded['phase_label'] = self::labelOf($decoded);
        }

        return $decoded;
    }

    /**
     * True while a build is under way.
     *
     * A run whose process died without ever finishing would otherwise leave
     * the page waiting for ever, so a status nothing has touched for a while
     * is treated as over.
     */
    public function isRunning(): bool
    {
        $status = $this->read();

        if (!$status || ($status['state'] ?? null) !== 'running') {
            return false;
        }

        return (time() - filemtime($this->path)) < 600;
    }

    private function put(array $status): void
    {
        // Written whole and moved into place: the page polls this file
        // constantly and must never read a half-written one.
        $temporary = $this->path . '.tmp';
        file_put_contents($temporary, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($temporary, $this->path);
    }
}
