<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;

class ScreenshotService
{
    /**
     * Colour of the strip a caller may place at the end of a document so a
     * full-page capture knows where the page stops. Chosen because nothing in
     * a real design uses it, and it survives JPEG-free PNG capture exactly.
     */
    public const END_MARKER_COLOUR = '#ff00ff';

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
    public function capture(string $url, string $outputPath, ?int $maxWidth = null, int $quality = 82, ?array $viewport = null): string
    {
        $binary = $this->findChromeBinary();
        if (!$binary) {
            throw new \RuntimeException('Chrome/Chromium not found');
        }

        [$viewWidth, $viewHeight] = $viewport ?: [$this->width, $this->height];

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
            $viewWidth,
            $viewHeight,
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
                $this->reencode($capturePath, $outputPath, $maxWidth, $quality);
            } finally {
                if ($capturePath !== $outputPath) {
                    @unlink($capturePath);
                }
            }
        }

        return $outputPath;
    }

    /**
     * Capture a whole page, however tall it is.
     *
     * Chrome's --screenshot only ever photographs the viewport, so the window
     * is opened taller than any realistic page and the unused space is cut off
     * afterwards. The cut is found by looking for the END_MARKER_COLOUR strip
     * the caller placed at the end of the document; without one, the capture is
     * returned at full window height.
     */
    public function captureFullPage(string $url, string $outputPath, ?int $maxWidth = null, int $quality = 80, ?int $viewWidth = null, int $maxHeight = 5000): string
    {
        // Capturing at the width we are going to save at avoids a downscale of
        // a very tall canvas, which is where the memory goes.
        $viewWidth = $viewWidth ?: ($maxWidth ?: 1200);

        // gd holds 4 bytes a pixel, so even 1200x5000 is ~24 MB, and the crop
        // is briefly a second copy. The default 128 MB limit does not survive
        // that alongside a booted framework, and the failure is a fatal error
        // rather than an exception a caller could handle.
        $previousLimit = ini_get('memory_limit');
        $needed = (int) ceil(($viewWidth * $maxHeight * 4 * 3) / 1048576) + 128;
        if ($this->limitInMegabytes($previousLimit) < $needed) {
            ini_set('memory_limit', $needed . 'M');
        }

        $raw = tempnam(sys_get_temp_dir(), 'vela-full-') . '.png';

        try {
            $this->capture($url, $raw, null, 100, [$viewWidth, $maxHeight]);

            // Trim, scale and encode in a single pass. A 1440x6000 canvas is
            // ~35 MB in gd, and holding two of them at once exhausts the
            // default CLI memory limit.
            $image = imagecreatefrompng($raw);
            if (!$image) {
                throw new \RuntimeException('Could not read the captured page');
            }

            try {
                $cut = $this->findEndMarker($image);
                if ($cut !== null) {
                    $trimmed = imagecrop($image, ['x' => 0, 'y' => 0, 'width' => imagesx($image), 'height' => $cut]);
                    if ($trimmed) {
                        imagedestroy($image);
                        $image = $trimmed;
                    }
                }

                $this->writeImage($image, $outputPath, $maxWidth, $quality);
            } finally {
                imagedestroy($image);
            }
        } finally {
            @unlink($raw);
            ini_set('memory_limit', $previousLimit);
        }

        return $outputPath;
    }

    /**
     * Read a php.ini memory value ("256M", "1G", "-1") as whole megabytes.
     */
    private function limitInMegabytes(string|false $limit): int
    {
        if ($limit === false || $limit === '') {
            return 0;
        }
        if ((int) $limit === -1) {
            return PHP_INT_MAX;
        }

        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1048576),
        };
    }

    /**
     * Row where the end-of-document marker sits, or null if there is none.
     */
    private function findEndMarker(\GdImage $image): ?int
    {
        [$mr, $mg, $mb] = sscanf(self::END_MARKER_COLOUR, '#%02x%02x%02x');
        $x = (int) (imagesx($image) / 2);

        for ($y = 0; $y < imagesy($image); $y++) {
            $rgb = imagecolorat($image, $x, $y);
            if ((($rgb >> 16) & 0xFF) === $mr && (($rgb >> 8) & 0xFF) === $mg && ($rgb & 0xFF) === $mb) {
                return $y > 10 ? $y : null;
            }
        }

        return null;
    }

    /**
     * Re-encode the captured PNG, optionally scaled, into the target format.
     */
    private function reencode(string $source, string $target, ?int $maxWidth, int $quality): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The gd extension is required to write ' . pathinfo($target, PATHINFO_EXTENSION) . ' screenshots');
        }

        $image = imagecreatefrompng($source);
        if (!$image) {
            throw new \RuntimeException('Could not read the captured screenshot');
        }

        try {
            $this->writeImage($image, $target, $maxWidth, $quality);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Scale if asked, then write in the format the target's extension names.
     *
     * The caller keeps ownership of $image; anything created here is released
     * before returning.
     */
    private function writeImage(\GdImage $image, string $target, ?int $maxWidth, int $quality): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The gd extension is required to write ' . pathinfo($target, PATHINFO_EXTENSION) . ' screenshots');
        }

        $working = $image;
        $ownWorking = false;

        try {
            if ($maxWidth && imagesx($working) > $maxWidth) {
                $height = (int) round(imagesy($working) * ($maxWidth / imagesx($working)));
                $resized = imagescale($working, $maxWidth, $height);
                if ($resized) {
                    $working = $resized;
                    $ownWorking = true;
                }
            }

            $asJpeg = in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);

            if ($asJpeg) {
                // JPEG has no alpha; without a ground, transparent pixels come
                // out black rather than as the page's own background.
                $flattened = imagecreatetruecolor(imagesx($working), imagesy($working));
                imagefill($flattened, 0, 0, imagecolorallocate($flattened, 255, 255, 255));
                imagecopy($flattened, $working, 0, 0, 0, 0, imagesx($working), imagesy($working));
                if ($ownWorking) {
                    imagedestroy($working);
                }
                $working = $flattened;
                $ownWorking = true;

                $ok = imagejpeg($working, $target, $quality);
            } else {
                $ok = imagepng($working, $target);
            }

            if (!$ok) {
                throw new \RuntimeException('Could not write ' . $target);
            }
        } finally {
            if ($ownWorking && $working !== $image) {
                imagedestroy($working);
            }
        }
    }
}
