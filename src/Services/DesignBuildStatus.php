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
            'lines' => [],
            'error' => null,
        ]);
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

    public function read(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : null;
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
