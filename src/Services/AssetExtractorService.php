<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;

class AssetExtractorService
{
    public function isAvailable(): bool
    {
        exec('which convert 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    public function extractAll(string $designPath): array
    {
        $files = glob($designPath . '/*.{psd,ai}', GLOB_BRACE) ?: [];
        if (empty($files)) {
            return [];
        }

        if (!$this->isAvailable()) {
            $skipped = array_map('basename', $files);
            Log::warning('ImageMagick not installed, skipping PSD/AI extraction', ['files' => $skipped]);
            return ['skipped' => $skipped, 'reason' => 'ImageMagick not installed'];
        }

        $extracted = [];
        foreach ($files as $file) {
            if (filesize($file) > 50 * 1024 * 1024) {
                Log::warning('Skipping large design file (>50MB)', ['file' => basename($file)]);
                continue;
            }
            $result = $this->extractPsd($file, $designPath);
            $extracted = array_merge($extracted, $result);
        }

        return $extracted;
    }

    public function extractPsd(string $filePath, string $outputDir): array
    {
        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $compositePath = $outputDir . '/' . $name . '_composite.png';

        $cmd = sprintf('convert %s[0] %s 2>&1', escapeshellarg($filePath), escapeshellarg($compositePath));
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0 && file_exists($compositePath)) {
            return [$compositePath];
        }

        Log::warning('Failed to extract PSD/AI composite', [
            'file' => basename($filePath),
            'output' => implode("\n", $output),
        ]);
        return [];
    }
}
