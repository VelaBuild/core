<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a headless browser for sites that have none.
 *
 * Screenshots need Chrome, and telling an operator to go and install it is
 * where a non-technical one stops. Google publishes Chrome for Testing —
 * versioned, unsigned, no installer, no administrator rights — so the copy
 * lands in the site's own storage directory and nothing outside it changes.
 */
class BrowserInstaller
{
    private const VERSIONS_URL = 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json';

    /**
     * Where the executable sits inside each platform's archive.
     */
    private const BINARIES = [
        'linux64'   => 'chrome-linux64/chrome',
        'mac-arm64' => 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
        'mac-x64'   => 'chrome-mac-x64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
        'win64'     => 'chrome-win64/chrome.exe',
    ];

    public function directory(): string
    {
        return storage_path('app/vela-browser');
    }

    /**
     * The browser this installer has already put in place, if any.
     */
    public function installedBinary(): ?string
    {
        $platform = $this->platform();
        if (!$platform) {
            return null;
        }

        $path = $this->directory() . '/' . self::BINARIES[$platform];

        return is_executable($path) ? $path : null;
    }

    /**
     * Whether this machine is one we know how to fetch a browser for.
     */
    public function supported(): bool
    {
        return $this->platform() !== null;
    }

    public function platform(): ?string
    {
        $arm = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true);

        return match (PHP_OS_FAMILY) {
            'Darwin'  => $arm ? 'mac-arm64' : 'mac-x64',
            'Linux'   => $arm ? null : 'linux64',
            'Windows' => 'win64',
            default   => null,
        };
    }

    /**
     * Download and unpack a browser, returning the path to its executable.
     *
     * Already installed, this is a no-op — the download is a few hundred
     * megabytes and nobody should pay for it twice.
     */
    public function install(?\Closure $progress = null): string
    {
        $say = $progress ?: fn () => null;

        if ($existing = $this->installedBinary()) {
            return $existing;
        }

        $platform = $this->platform();
        if (!$platform) {
            throw new \RuntimeException(
                'No browser download is published for this machine (' . PHP_OS_FAMILY . '/' . php_uname('m') . '). Install Google Chrome or Chromium by hand, or configure cloud screenshots.'
            );
        }

        [$version, $url] = $this->downloadFor($platform);
        $say("Downloading Chrome {$version} for {$platform} (a few hundred MB, once only)...");

        $archive = tempnam(sys_get_temp_dir(), 'vela-chrome-') . '.zip';

        try {
            $this->fetch($url, $archive);

            $say('Unpacking...');
            $this->unpack($archive, $this->directory());
        } finally {
            @unlink($archive);
        }

        $binary = $this->directory() . '/' . self::BINARIES[$platform];

        // Zip archives carry a permission bit that not every extractor honours,
        // and a browser that cannot be executed is no better than none.
        if (file_exists($binary) && !is_executable($binary)) {
            @chmod($binary, 0755);
        }

        if (!is_executable($binary)) {
            throw new \RuntimeException('The browser was downloaded but could not be made runnable at ' . $binary);
        }

        $say('Chrome installed at ' . $binary);

        return $binary;
    }

    /**
     * The stable version and its archive URL for this platform.
     */
    private function downloadFor(string $platform): array
    {
        try {
            $response = Http::timeout(30)->get(self::VERSIONS_URL);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach the browser download service: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            throw new \RuntimeException('The browser download service replied with HTTP ' . $response->status() . '.');
        }

        $stable = $response->json('channels.Stable');
        $version = $stable['version'] ?? null;

        foreach ($stable['downloads']['chrome'] ?? [] as $download) {
            if (($download['platform'] ?? null) === $platform) {
                return [$version, $download['url']];
            }
        }

        throw new \RuntimeException('No stable Chrome build is published for ' . $platform . '.');
    }

    private function fetch(string $url, string $target): void
    {
        // Streamed to disk: the archive is far larger than any sensible
        // memory limit would let a string hold.
        $response = Http::timeout(900)->sink($target)->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException('The browser download failed with HTTP ' . $response->status() . '.');
        }

        if (!file_exists($target) || filesize($target) < 1048576) {
            throw new \RuntimeException('The browser download arrived empty or truncated.');
        }
    }

    /**
     * Extract the archive, keeping the executable bits where possible.
     *
     * PHP's ZipArchive discards permissions, which on macOS leaves an .app
     * bundle full of files nothing can run. The unzip binary keeps them, so it
     * is preferred where present and ZipArchive is the fallback.
     */
    private function unpack(string $archive, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $unzip = trim((string) shell_exec('command -v unzip 2>/dev/null'));

        if ($unzip !== '') {
            $command = sprintf('%s -q -o %s -d %s 2>&1', escapeshellarg($unzip), escapeshellarg($archive), escapeshellarg($destination));
            exec($command, $output, $exitCode);

            if ($exitCode === 0) {
                return;
            }

            Log::warning('unzip failed, falling back to ZipArchive', ['output' => implode("\n", $output)]);
        }

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('The downloaded browser archive could not be opened.');
        }

        $zip->extractTo($destination);
        $zip->close();

        $this->restoreExecutableBits($destination);
    }

    /**
     * Make the pieces a browser actually launches runnable again.
     */
    private function restoreExecutableBits(string $destination): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Everything a bundle keeps under MacOS/ or Helpers/ is a program,
            // as are the loose binaries a Linux build ships beside chrome.
            $path = $file->getPathname();
            $isProgram = str_contains($path, '/MacOS/')
                || str_contains($path, '/Helpers/')
                || $file->getExtension() === ''
                && in_array($file->getFilename(), ['chrome', 'chrome_crashpad_handler', 'chrome_sandbox'], true);

            if ($isProgram) {
                @chmod($path, 0755);
            }
        }
    }
}
