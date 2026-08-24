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
        // An explicit path wins: a machine may have several browsers, or one
        // somewhere this list would never think to look.
        $configured = env('VELA_CHROME_BINARY');
        if ($configured && is_executable($configured)) {
            return $configured;
        }

        $binaries = ['chromium-browser', 'chromium', 'google-chrome-stable', 'google-chrome'];
        foreach ($binaries as $binary) {
            $output = [];
            exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                return trim($output[0] ?? $binary);
            }
        }

        // macOS ships browsers as app bundles, which are not on PATH under any
        // of the names above — so a Mac would always report Chrome as missing.
        $bundles = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        ];
        foreach ($bundles as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Capture $url into $outputPath.
     *
     * Chrome always writes PNG, whatever the filename says. A .jpg or .jpeg
     * target is therefore captured to a temporary PNG and re-encoded, so the
     * file is the format its extension claims — a PNG called .jpg is served
     * with the wrong Content-Type and defeats any tooling that trusts the name.
     *
     * $maxWidth scales the result down; a preview thumbnail does not need the
     * full capture width, and the file is several times smaller for it.
     */
    public function capture(string $url, string $outputPath, ?int $maxWidth = null, int $quality = 82): string
    {
        $binary = $this->findChromeBinary();
        if (!$binary) {
            throw new \RuntimeException('Chrome/Chromium not found');
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        $wantsJpeg = in_array($extension, ['jpg', 'jpeg'], true);
        $capturePath = $wantsJpeg
            ? tempnam(sys_get_temp_dir(), 'vela-shot-') . '.png'
            : $outputPath;

        // --virtual-time-budget lets the page finish loading and painting before
        // the shot is taken. Without it Chrome captures whatever is on screen the
        // moment the document is ready, which on an image-heavy page is a set of
        // empty boxes.
        $cmd = sprintf(
            '%s --headless --disable-gpu --screenshot=%s --window-size=%d,%d --no-sandbox --hide-scrollbars --virtual-time-budget=%d --timeout=%d %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($capturePath),
            $this->width,
            $this->height,
            5000,
            $this->timeout * 1000,
            escapeshellarg($url)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($capturePath)) {
            $outputStr = implode("\n", $output);
            Log::error('Screenshot capture failed', ['cmd' => $cmd, 'output' => $outputStr, 'exit_code' => $exitCode]);
            throw new \RuntimeException('Screenshot capture failed: ' . $outputStr);
        }

        if (filesize($capturePath) < 1024) {
            Log::warning('Screenshot appears blank', ['path' => $capturePath, 'size' => filesize($capturePath)]);
        }

        if ($wantsJpeg || $maxWidth) {
            try {
                $this->reencode($capturePath, $outputPath, $wantsJpeg, $maxWidth, $quality);
            } finally {
                if ($capturePath !== $outputPath) {
                    @unlink($capturePath);
                }
            }
        }

        return $outputPath;
    }

    /**
     * Re-encode the captured PNG, optionally scaled, into the target format.
     */
    private function reencode(string $source, string $target, bool $asJpeg, ?int $maxWidth, int $quality): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The gd extension is required to write ' . pathinfo($target, PATHINFO_EXTENSION) . ' screenshots');
        }

        $image = imagecreatefrompng($source);
        if (!$image) {
            throw new \RuntimeException('Could not read the captured screenshot');
        }

        try {
            if ($maxWidth && imagesx($image) > $maxWidth) {
                $height = (int) round(imagesy($image) * ($maxWidth / imagesx($image)));
                $resized = imagescale($image, $maxWidth, $height);
                if ($resized) {
                    imagedestroy($image);
                    $image = $resized;
                }
            }

            if ($asJpeg) {
                // JPEG has no alpha; without a ground, transparent pixels come
                // out black rather than as the page's own background.
                $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
                imagefill($flattened, 0, 0, imagecolorallocate($flattened, 255, 255, 255));
                imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                imagedestroy($image);
                $image = $flattened;

                $ok = imagejpeg($image, $target, $quality);
            } else {
                $ok = imagepng($image, $target);
            }

            if (!$ok) {
                throw new \RuntimeException('Could not write ' . $target);
            }
        } finally {
            imagedestroy($image);
        }
    }
}
