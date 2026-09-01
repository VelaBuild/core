<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScreenshotService
{
    /**
     * Colour of the strip a caller may place at the end of a document so a
     * full-page capture knows where the page stops. Chosen because nothing in
     * a real design uses it, and it survives JPEG-free PNG capture exactly.
     */
    public const END_MARKER_COLOUR = '#ff00ff';

    /**
     * The viewport height a vh unit is resolved against when a page is being
     * photographed whole. An ordinary laptop screen, not the tall window the
     * capture itself uses.
     */
    public const NOMINAL_VIEWPORT_HEIGHT = 900;

    /**
     * Colour of the pair of strips placed above and below one element so a
     * capture of the whole page can be cut down to just that element. A second
     * colour rather than END_MARKER_COLOUR because both are on the page at
     * once: the section is bounded by these, the document still ends with that.
     */
    public const SECTION_MARKER_COLOUR = '#00ffff';

    private int $width = 1920;
    private int $height = 1080;
    private int $timeout = 30;

    /**
     * Whether a capture can be taken right now, without fetching anything.
     */
    public function isAvailable(): bool
    {
        return $this->findChromeBinary() !== null
            || app(BrowserRenderingService::class)->isConfigured();
    }

    /**
     * Make sure a capture route exists, installing a browser if that is what
     * it takes, and say which route was settled on.
     *
     * The order is deliberate: a browser already on the machine, then the
     * cloud service if the operator has configured one, then a download. The
     * brief is a local browser by default and cloud as the no-setup option,
     * so a configured service is honoured before several hundred megabytes
     * are fetched, but nothing is ever sent off the machine by default.
     */
    public function ensureCaptureRoute(?\Closure $progress = null): string
    {
        if ($binary = $this->findChromeBinary()) {
            return 'Using the browser at ' . $binary;
        }

        if (app(BrowserRenderingService::class)->isConfigured()) {
            return 'Using the configured cloud rendering service for screenshots.';
        }

        $installer = app(BrowserInstaller::class);

        if (!$installer->supported()) {
            throw new \RuntimeException(
                'No browser is available for screenshots and none can be downloaded for this machine (' . PHP_OS_FAMILY . '/' . php_uname('m') . '). Install Google Chrome or Chromium, or set CLOUDFLARE_BROWSER_RENDERING_URL.'
            );
        }

        $binary = $installer->install($progress);

        return 'Using the browser Vela installed at ' . $binary;
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

        // Last, the copy this site fetched for itself. It comes last so a
        // browser the machine already had is always preferred to a download.
        return app(BrowserInstaller::class)->installedBinary();
    }

    /**
     * A usable browser, fetching one if this machine has none.
     *
     * Callers that can wait for a download should use this rather than
     * findChromeBinary(): an operator who has never installed Chrome should
     * not have to, and the alternative was a run that stopped with an
     * instruction to go and install software.
     */
    public function ensureChromeBinary(?\Closure $progress = null): string
    {
        if ($binary = $this->findChromeBinary()) {
            return $binary;
        }

        return app(BrowserInstaller::class)->install($progress);
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

        [$viewWidth, $viewHeight] = $viewport ?: [$this->width, $this->height];

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // No local browser, but a rendering service configured: use it rather
        // than refuse. This is the no-setup route — a host with nowhere to put
        // a Chrome, or an operator who would rather not have one.
        if (!$binary) {
            $cloud = app(BrowserRenderingService::class);

            if (!$cloud->isConfigured()) {
                throw new \RuntimeException(
                    'No browser is available for screenshots. Let Vela install one, install Google Chrome or Chromium, or set CLOUDFLARE_BROWSER_RENDERING_URL to use cloud screenshots.'
                );
            }

            return $this->captureViaCloud($cloud, $url, $outputPath, $maxWidth, $quality, $viewWidth, $viewHeight);
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
     * Take the shot through the rendering service instead of a local browser.
     *
     * The service returns the image itself, so all that is left is to write it
     * and put it through the same scaling and re-encoding a local capture gets
     * — a caller should not be able to tell which route produced the file.
     */
    private function captureViaCloud(
        BrowserRenderingService $cloud,
        string $url,
        string $outputPath,
        ?int $maxWidth,
        int $quality,
        int $viewWidth,
        int $viewHeight,
        bool $fullPage = false
    ): string {
        $encoded = $cloud->screenshot($url, [
            'width' => $viewWidth,
            'height' => $viewHeight,
            'full_page' => $fullPage,
            'format' => 'png',
            'timeout' => $this->timeout,
        ]);

        if (!$encoded) {
            throw new \RuntimeException('The cloud rendering service did not return a screenshot. See storage/logs for its reply.');
        }

        $raw = tempnam(sys_get_temp_dir(), 'vela-cloud-') . '.png';
        file_put_contents($raw, base64_decode($encoded));

        try {
            if (filesize($raw) < 1024) {
                throw new \RuntimeException('The cloud rendering service returned an empty screenshot.');
            }

            $wantsJpeg = in_array(strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);

            if ($wantsJpeg || $maxWidth) {
                $this->reencode($raw, $outputPath, $maxWidth, $quality);
            } else {
                copy($raw, $outputPath);
            }
        } finally {
            @unlink($raw);
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
     * Capture a live URL as a whole page rather than a single fold.
     *
     * captureFullPage() finds the foot of the document by the END_MARKER_COLOUR
     * strip, which a site being photographed knows nothing about — so the page
     * is fetched, the marker appended, and the copy captured from disk. A
     * <base> keeps the page's own relative URLs pointing back at the server.
     *
     * Falls back to a viewport capture if the page cannot be fetched, since a
     * fold of the site is still worth more to a caller than nothing.
     */
    public function captureLiveFullPage(string $url, string $outputPath, ?int $maxWidth = 1200, int $quality = 80, int $viewWidth = 1200): string
    {
        // The rendering service photographs a whole page itself, and cannot
        // reach the file:// copy the local route builds — so it is asked
        // directly rather than put through the marker trick below.
        if (!$this->findChromeBinary()) {
            $cloud = app(BrowserRenderingService::class);

            if ($cloud->isConfigured()) {
                return $this->captureViaCloud($cloud, $url, $outputPath, $maxWidth, $quality, $viewWidth, 1080, true);
            }
        }

        try {
            $response = Http::timeout($this->timeout)->get($url);
            $html = $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            $html = null;
        }

        if (!$html) {
            Log::warning('Full-page capture fell back to the viewport', ['url' => $url]);

            return $this->capture($url, $outputPath, $maxWidth, $quality, [$viewWidth, 1080]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vela-live-') . '.html';
        file_put_contents($tmp, $this->prepareLivePage($html, $url));

        try {
            return $this->captureFullPage('file://' . $tmp, $outputPath, $maxWidth, $quality, $viewWidth);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Photograph ONE element of a live page — the picture a per-section check
     * needs, since comparing a whole page cannot say which section changed.
     *
     * Chrome's --screenshot has no selector and no clip, so nothing here drives
     * the browser for a region. The page is fetched, a strip is laid above and
     * below the element, the whole page is captured as usual, and the picture
     * is cut between the two strips — the same trick that already finds the
     * foot of a document, applied twice.
     *
     * $handle is the value of an element's data-vela-block, which is what a
     * written section carries; a leading "." or "#" asks for a class or id
     * instead.
     *
     * @return string|null the path written, or null if the element is not on
     *                     the page or the strips could not be found in the
     *                     capture — a caller measuring fidelity must be able to
     *                     tell "it looks wrong" from "there was nothing to look
     *                     at", so this does not throw and does not guess.
     */
    public function captureLiveSection(string $url, string $handle, string $outputPath, ?int $maxWidth = 1200, int $quality = 80, int $viewWidth = 1200): ?string
    {
        try {
            $response = Http::timeout($this->timeout)->get($url);
            $html = $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            $html = null;
        }

        if (!$html) {
            return null;
        }

        $marked = $this->markElement($html, $handle);
        if ($marked === null) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vela-section-') . '.html';
        file_put_contents($tmp, $this->prepareLivePage($marked, $url));

        $raw = tempnam(sys_get_temp_dir(), 'vela-section-raw-') . '.png';

        $previousLimit = ini_get('memory_limit');
        $needed = (int) ceil(($viewWidth * 5000 * 4 * 3) / 1048576) + 128;
        if ($this->limitInMegabytes($previousLimit) < $needed) {
            ini_set('memory_limit', $needed . 'M');
        }

        try {
            $this->capture('file://' . $tmp, $raw, null, 100, [$viewWidth, 5000]);

            $image = imagecreatefrompng($raw);
            if (!$image) {
                return null;
            }

            try {
                $bounds = $this->findSectionMarkers($image);
                if ($bounds === null) {
                    return null;
                }

                [$top, $bottom] = $bounds;
                $cropped = imagecrop($image, [
                    'x' => 0,
                    'y' => $top,
                    'width' => imagesx($image),
                    'height' => $bottom - $top,
                ]);
                if (!$cropped) {
                    return null;
                }

                try {
                    $this->writeImage($cropped, $outputPath, $maxWidth, $quality);
                } finally {
                    imagedestroy($cropped);
                }
            } finally {
                imagedestroy($image);
            }
        } finally {
            @unlink($tmp);
            @unlink($raw);
            ini_set('memory_limit', $previousLimit);
        }

        return $outputPath;
    }

    /**
     * Lay a marker strip immediately above and below the element $handle names.
     *
     * Returns null when the page has no such element, so the caller reports
     * "not there" rather than photographing the whole page and calling it a
     * section.
     */
    public function markElement(string $html, string $handle): ?string
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $xpath = new \DOMXPath($doc);
        $quoted = $this->xpathLiteral(ltrim($handle, '.#'));
        $query = match ($handle[0] ?? '') {
            '.' => "//*[contains(concat(' ', normalize-space(@class), ' '), concat(' ', {$quoted}, ' '))]",
            '#' => "//*[@id={$quoted}]",
            default => "//*[@data-vela-block={$quoted}]",
        };

        $node = $xpath->query($query)?->item(0);
        if (!$node instanceof \DOMElement || !$node->parentNode) {
            return null;
        }

        $strip = '<div style="height:2px;background:' . self::SECTION_MARKER_COLOUR . ';margin:0;padding:0;width:100%;"></div>';

        $before = $doc->createDocumentFragment();
        $before->appendXML($strip);
        $after = $doc->createDocumentFragment();
        $after->appendXML($strip);

        $node->parentNode->insertBefore($before, $node);
        if ($node->nextSibling) {
            $node->parentNode->insertBefore($after, $node->nextSibling);
        } else {
            $node->parentNode->appendChild($after);
        }

        return $doc->saveHTML() ?: null;
    }

    /**
     * XPath has no escape character, so a value containing a quote has to be
     * assembled with concat() rather than quoted.
     */
    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        $parts = array_map(fn ($part) => "'" . $part . "'", explode("'", $value));

        return 'concat(' . implode(', "\'", ', $parts) . ')';
    }

    /**
     * The first and last rows carrying SECTION_MARKER_COLOUR, or null if the
     * pair is not there.
     *
     * A row is sampled across its width rather than at its centre: the strip is
     * a sibling of the element, and a section sitting in a narrow column has no
     * pixel of it under the middle of the page.
     */
    private function findSectionMarkers(\GdImage $image): ?array
    {
        [$mr, $mg, $mb] = sscanf(self::SECTION_MARKER_COLOUR, '#%02x%02x%02x');
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) ($width / 80));

        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x += $step) {
                $rgb = imagecolorat($image, $x, $y);
                if ((($rgb >> 16) & 0xFF) === $mr && (($rgb >> 8) & 0xFF) === $mg && ($rgb & 0xFF) === $mb) {
                    $rows[] = $y;
                    break;
                }
            }
        }

        if ($rows === []) {
            return null;
        }

        // Each strip is two pixels tall, so the rows come in two runs. The top
        // of the section is the foot of the first run; its bottom is the head
        // of the second.
        $top = $rows[0];
        $bottom = null;
        foreach ($rows as $y) {
            if ($y > $top + 4) {
                $bottom = $y;
                break;
            }
            $top = $y;
        }

        if ($bottom === null || $bottom <= $top + 1) {
            return null;
        }

        return [$top + 1, $bottom];
    }

    /**
     * Point the fetched copy back at its origin and mark where it ends.
     */
    private function prepareLivePage(string $html, string $url): string
    {
        $marker = '<div style="height:2px;background:' . self::END_MARKER_COLOUR . ';margin:0;padding:0;"></div>';

        // The window is opened far taller than any real screen so the whole
        // document fits, which turns every vh into a wild number: a 100vh hero
        // photographs three times the height a visitor would ever see it. Both
        // are resolved against an ordinary viewport instead.
        $html = preg_replace_callback(
            '/(min-height|height)\s*:\s*(\d+(?:\.\d+)?)vh/i',
            fn ($m) => $m[1] . ':' . (int) round(((float) $m[2] / 100) * self::NOMINAL_VIEWPORT_HEIGHT) . 'px',
            $html
        );

        if (!preg_match('/<base\s/i', $html)) {
            $base = '<base href="' . htmlspecialchars($url, ENT_QUOTES) . '">';
            $headStart = stripos($html, '<head>');
            if ($headStart !== false) {
                $at = $headStart + 6;
                $html = substr($html, 0, $at) . $base . substr($html, $at);
            }
        }

        $bodyEnd = strripos($html, '</body>');

        return $bodyEnd === false
            ? $html . $marker
            : substr($html, 0, $bodyEnd) . $marker . substr($html, $bodyEnd);
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
