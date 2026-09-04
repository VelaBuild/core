<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;

/**
 * Starts a design build in a process of its own.
 *
 * It cannot run inside the web server that started it: a build photographs
 * the very site it is building, so on a server handling one request at a
 * time it would sit waiting for a page only it could serve. A separate
 * process also means no queue worker to set up — which is the point, for an
 * operator who would never have started one.
 */
class DesignBuildRunner
{
    public function designPath(): string
    {
        return storage_path('app/design');
    }

    public function status(): DesignBuildStatus
    {
        return new DesignBuildStatus($this->designPath());
    }

    /**
     * Whether this machine will let us start one at all.
     */
    public function canRunDetached(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * Begin a build. Returns immediately; follow it through status().
     */
    public function start(string $url, int $maxLoops, bool $generateImages = true, bool $sectionsOnly = false): void
    {
        if ($this->status()->isRunning()) {
            throw new \RuntimeException('A build is already running.');
        }

        if (!$this->canRunDetached()) {
            throw new \RuntimeException(
                'This server does not allow Vela to start a background process, so a build cannot be run from here. Run "php artisan vela:design-to-site" from a terminal instead.'
            );
        }

        // Started before the process is, so the page has something to show
        // between the button being pressed and the command getting going.
        $this->status()->start($maxLoops);

        $command = sprintf(
            '%s %s vela:design-to-site --url=%s --design-path=%s --max-loops=%d --force%s%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($url),
            escapeshellarg($this->designPath()),
            $maxLoops,
            $generateImages ? '' : ' --no-images',
            $sectionsOnly ? ' --sections-only' : ''
        );

        $this->spawn($command);
    }

    /**
     * Run a command detached, so it outlives the request that asked for it.
     */
    private function spawn(string $command): void
    {
        $detached = PHP_OS_FAMILY === 'Windows'
            ? 'start /B ' . $command
            : $command . ' > /dev/null 2>&1 &';

        Log::info('Vela: starting a design build', ['command' => $command]);

        $handle = proc_open($detached, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, base_path());

        if (is_resource($handle)) {
            proc_close($handle);
        }
    }

    /**
     * The design files a build would work from.
     */
    public function designFiles(): array
    {
        $path = $this->designPath();

        if (!is_dir($path)) {
            return [];
        }

        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'md', 'txt'];
        $files = [];

        foreach (scandir($path) as $file) {
            if ($file === '.' || $file === '..' || !is_file($path . '/' . $file)) {
                continue;
            }

            if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $allowed, true)) {
                continue;
            }

            $isImage = !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['md', 'txt'], true);

            $files[] = [
                'name' => $file,
                'size' => filesize($path . '/' . $file),
                'is_image' => $isImage,
                // Uploads add to this folder rather than replacing it, so a
                // second design sits quietly beside the first and both are
                // sent. Saying what each one will be taken for is what makes
                // that visible before the button is pressed.
                'role' => $isImage
                    ? app(DesignBuilderService::class)->roleFor($file)
                    : 'brief',
            ];
        }

        return $files;
    }

    /**
     * Captures and reports from the most recent build, newest loop first.
     */
    public function results(): array
    {
        $output = $this->designPath() . '/output';

        if (!is_dir($output)) {
            return [];
        }

        $loops = [];

        foreach (glob($output . '/loop_*_screenshot.png') ?: [] as $shot) {
            if (!preg_match('/loop_(\d+)_screenshot/', basename($shot), $matches)) {
                continue;
            }

            $loop = (int) $matches[1];
            $report = $output . '/loop_' . $loop . '_report.md';

            $loops[$loop] = [
                'loop' => $loop,
                'screenshot' => basename($shot),
                'report' => file_exists($report) ? file_get_contents($report) : null,
            ];
        }

        krsort($loops);

        return array_values($loops);
    }
}
