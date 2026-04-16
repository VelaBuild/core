<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;

class ScreenshotService
{
    private int $width = 1920;
    private int $height = 1080;
    private int $timeout = 30;

    public function isAvailable(): bool
    {
        return $this->findChromeBinary() !== null;
    }

    public function findChromeBinary(): ?string
    {
        $binaries = ['chromium-browser', 'chromium', 'google-chrome-stable', 'google-chrome'];
        foreach ($binaries as $binary) {
            exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                return trim($output[0] ?? $binary);
            }
            $output = []; // reset for next iteration
        }
        return null;
    }

    public function capture(string $url, string $outputPath): string
    {
        $binary = $this->findChromeBinary();
        if (!$binary) {
            throw new \RuntimeException('Chrome/Chromium not found');
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $cmd = sprintf(
            '%s --headless --disable-gpu --screenshot=%s --window-size=%d,%d --no-sandbox --timeout=%d %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($outputPath),
            $this->width,
            $this->height,
            $this->timeout * 1000,
            escapeshellarg($url)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            $outputStr = implode("\n", $output);
            Log::error('Screenshot capture failed', ['cmd' => $cmd, 'output' => $outputStr, 'exit_code' => $exitCode]);
            throw new \RuntimeException('Screenshot capture failed: ' . $outputStr);
        }

        if (filesize($outputPath) < 1024) {
            Log::warning('Screenshot appears blank', ['path' => $outputPath, 'size' => filesize($outputPath)]);
        }

        return $outputPath;
    }
}
