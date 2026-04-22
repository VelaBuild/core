<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Project-level design system storage.
 *
 * Everything lives as plain files in `base_path('designsystem')`:
 *
 *   designsystem/
 *   ├── README.md             (explains structure; shipped with repo)
 *   ├── manifest.json         (auto-maintained index — list of files + metadata)
 *   ├── palette.json          (named color entries, free-form count)
 *   ├── fonts.json            (font choices with family + source + weights)
 *   └── <asset files>         (md / html / images — flat, no subdirectories)
 *
 * Why files, not DB: the design system is source material, not per-request
 * state. Git tracking makes it deploy-ready via pushgit.sh; AI tools browse
 * it read-only; there's no concurrency problem at admin-only write scale.
 *
 * Safety: every filename is validated against a strict regex + extension
 * allowlist before any write or read. Path traversal is rejected by
 * construction (no slashes allowed in names; the flat structure has no
 * subdirectories to traverse into).
 */
class DesignSystem
{
    private const FILENAME_REGEX  = '/^[a-zA-Z0-9][a-zA-Z0-9._\-]{0,127}$/';
    private const RESERVED_FILES  = ['manifest.json', 'palette.json', 'fonts.json', 'README.md'];

    private const ALLOWED_EXTS = [
        // Docs
        'md', 'html', 'htm', 'txt', 'json', 'css',
        // Images
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        // Fonts + misc
        'woff', 'woff2', 'ttf', 'otf',
        'pdf',
    ];

    private const MAX_FILE_BYTES   = 25 * 1024 * 1024;   // 25 MB per file
    private const MAX_TOTAL_BYTES  = 500 * 1024 * 1024;  // 500 MB total
    private const MAX_ZIP_DOWNLOAD = 100 * 1024 * 1024;  // 100 MB for URL imports

    public function path(string $sub = ''): string
    {
        return rtrim(base_path('designsystem/' . ltrim($sub, '/')), '/');
    }

    public function ensureStructure(): void
    {
        $dir = $this->path();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $seed = [
            'README.md'     => $this->defaultReadme(),
            'palette.json'  => json_encode($this->defaultPalette(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'fonts.json'    => json_encode($this->defaultFonts(),   JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
        foreach ($seed as $name => $content) {
            $file = $this->path($name);
            if (!file_exists($file)) {
                file_put_contents($file, $content);
            }
        }

        $this->rebuildManifest();
    }

    // ── File listing / read / write / delete ───────────────────────────────

    /**
     * Non-reserved files in the design system, with metadata.
     * @return array<int, array{name: string, ext: string, bytes: int, updated_at: string, is_text: bool}>
     */
    public function files(): array
    {
        $dir = $this->path();
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (in_array($entry, self::RESERVED_FILES, true)) continue;
            $full = $dir . '/' . $entry;
            if (!is_file($full)) continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $out[] = [
                'name'       => $entry,
                'ext'        => $ext,
                'bytes'      => filesize($full) ?: 0,
                'updated_at' => date('c', filemtime($full) ?: time()),
                'is_text'    => in_array($ext, ['md', 'html', 'htm', 'txt', 'json', 'css', 'svg'], true),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public function read(string $name): string
    {
        $path = $this->resolveForRead($name);
        return (string) file_get_contents($path);
    }

    public function readStream(string $name)
    {
        $path = $this->resolveForRead($name);
        return fopen($path, 'rb');
    }

    public function mime(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'md', 'txt'  => 'text/plain; charset=UTF-8',
            'html', 'htm'=> 'text/html; charset=UTF-8',
            'css'        => 'text/css; charset=UTF-8',
            'json'       => 'application/json; charset=UTF-8',
            'svg'        => 'image/svg+xml',
            'png'        => 'image/png',
            'jpg', 'jpeg'=> 'image/jpeg',
            'gif'        => 'image/gif',
            'webp'       => 'image/webp',
            'woff'       => 'font/woff',
            'woff2'      => 'font/woff2',
            'ttf'        => 'font/ttf',
            'otf'        => 'font/otf',
            'pdf'        => 'application/pdf',
            default      => 'application/octet-stream',
        };
    }

    /**
     * Write a user-supplied file. Replaces if it already exists.
     */
    public function write(string $name, string $contents): void
    {
        $this->validateName($name);
        if (strlen($contents) > self::MAX_FILE_BYTES) {
            throw new RuntimeException("File exceeds " . self::MAX_FILE_BYTES . " bytes: {$name}");
        }
        $this->ensureTotalUnderLimit(strlen($contents));

        $this->ensureStructure();
        file_put_contents($this->path($name), $contents);
        $this->rebuildManifest();
    }

    /**
     * Move an uploaded file into the design system. $src is an absolute path
     * (usually from Request::file()->path()).
     */
    public function adoptUpload(string $src, string $targetName): void
    {
        $this->validateName($targetName);
        if (!is_file($src)) {
            throw new RuntimeException("Upload source missing");
        }
        $size = filesize($src) ?: 0;
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException("File exceeds " . self::MAX_FILE_BYTES . " bytes");
        }
        $this->ensureTotalUnderLimit($size);

        $this->ensureStructure();
        if (!rename($src, $this->path($targetName))) {
            // rename fails across filesystems — fall back to copy.
            copy($src, $this->path($targetName));
        }
        $this->rebuildManifest();
    }

    public function delete(string $name): bool
    {
        if (in_array($name, self::RESERVED_FILES, true)) {
            throw new RuntimeException("cannot delete reserved file: {$name}");
        }
        $path = $this->resolveForRead($name);
        $ok = @unlink($path);
        if ($ok) {
            $this->rebuildManifest();
        }
        return $ok;
    }

    // ── Palette ────────────────────────────────────────────────────────────

    /**
     * Read-only. Does NOT call ensureStructure() — that would recurse
     * through rebuildManifest() → palette() → ensureStructure() → boom.
     * Reads pretend the file may not exist and return defaults in that case.
     *
     * @return array{name: string, entries: array<int, array{name:string, slug:string, hex:string, description:?string}>}
     */
    public function palette(): array
    {
        $raw = @file_get_contents($this->path('palette.json'));
        $parsed = $raw ? json_decode($raw, true) : null;
        if (!is_array($parsed)) {
            $parsed = $this->defaultPalette();
        }
        return [
            'name'    => (string) ($parsed['name'] ?? 'Default'),
            'entries' => array_values(array_map(fn ($e) => [
                'name'        => (string) ($e['name'] ?? ''),
                'slug'        => Str::slug((string) ($e['slug'] ?? ($e['name'] ?? ''))),
                'hex'         => $this->normaliseHex((string) ($e['hex'] ?? '#000000')),
                'description' => $e['description'] ?? null,
            ], (array) ($parsed['entries'] ?? []))),
        ];
    }

    public function setPalette(array $palette): void
    {
        $clean = [
            'name' => (string) ($palette['name'] ?? 'Default'),
            'entries' => [],
        ];
        foreach ((array) ($palette['entries'] ?? []) as $e) {
            $name = trim((string) ($e['name'] ?? ''));
            if ($name === '') continue;
            $clean['entries'][] = [
                'name'        => $name,
                'slug'        => Str::slug((string) ($e['slug'] ?? $name)),
                'hex'         => $this->normaliseHex((string) ($e['hex'] ?? '#000000')),
                'description' => !empty($e['description']) ? (string) $e['description'] : null,
            ];
        }
        $this->ensureStructure();
        file_put_contents(
            $this->path('palette.json'),
            json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $this->rebuildManifest();
    }

    // ── Fonts ──────────────────────────────────────────────────────────────

    /**
     * Read-only. Same reasoning as {@see palette()} — no ensureStructure.
     * @return array{entries: array<int, array>}
     */
    public function fonts(): array
    {
        $raw = @file_get_contents($this->path('fonts.json'));
        $parsed = $raw ? json_decode($raw, true) : null;
        if (!is_array($parsed)) {
            $parsed = $this->defaultFonts();
        }
        return [
            'entries' => array_values(array_map(fn ($e) => [
                'role'       => (string) ($e['role'] ?? ''),
                'family'     => (string) ($e['family'] ?? ''),
                'source_url' => (string) ($e['source_url'] ?? ''),
                'weights'    => array_values(array_filter((array) ($e['weights'] ?? []), 'is_numeric')),
                'fallback'   => (string) ($e['fallback'] ?? 'sans-serif'),
            ], (array) ($parsed['entries'] ?? []))),
        ];
    }

    public function setFonts(array $fonts): void
    {
        $clean = ['entries' => []];
        foreach ((array) ($fonts['entries'] ?? []) as $e) {
            $family = trim((string) ($e['family'] ?? ''));
            if ($family === '') continue;
            $clean['entries'][] = [
                'role'       => (string) ($e['role'] ?? ''),
                'family'     => $family,
                'source_url' => (string) ($e['source_url'] ?? ''),
                'weights'    => array_values(array_filter((array) ($e['weights'] ?? []), 'is_numeric')),
                'fallback'   => (string) ($e['fallback'] ?? 'sans-serif'),
            ];
        }
        $this->ensureStructure();
        file_put_contents(
            $this->path('fonts.json'),
            json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $this->rebuildManifest();
    }

    // ── ZIP import ─────────────────────────────────────────────────────────

    /**
     * Extract a ZIP into /designsystem/. Every entry inside goes through the
     * same filename + extension + size validation as a manual upload.
     * Entries inside nested directories are flattened (basename kept).
     *
     * @return array{imported: array<int, string>, skipped: array<int, array{name: string, reason: string}>}
     */
    public function importZip(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP ext-zip not installed — cannot extract ZIP imports.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open ZIP: {$zipPath}");
        }

        $imported = [];
        $skipped  = [];
        $this->ensureStructure();

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false || str_ends_with($entry, '/')) {
                    continue; // directory entry
                }
                $base = basename($entry);
                if ($base === '' || str_starts_with($base, '.')) {
                    $skipped[] = ['name' => $entry, 'reason' => 'dotfile or empty'];
                    continue;
                }

                try {
                    $this->validateName($base);
                } catch (\Throwable $e) {
                    $skipped[] = ['name' => $entry, 'reason' => $e->getMessage()];
                    continue;
                }

                $stat = $zip->statIndex($i);
                if (($stat['size'] ?? 0) > self::MAX_FILE_BYTES) {
                    $skipped[] = ['name' => $entry, 'reason' => 'too large (>25MB)'];
                    continue;
                }

                $stream = $zip->getStream($entry);
                if (!$stream) {
                    $skipped[] = ['name' => $entry, 'reason' => 'unreadable'];
                    continue;
                }
                $contents = stream_get_contents($stream);
                fclose($stream);

                try {
                    $this->ensureTotalUnderLimit(strlen((string) $contents));
                } catch (\Throwable $e) {
                    $skipped[] = ['name' => $entry, 'reason' => $e->getMessage()];
                    continue;
                }

                file_put_contents($this->path($base), $contents);
                $imported[] = $base;
            }
        } finally {
            $zip->close();
        }

        $this->rebuildManifest();
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Download a ZIP from a URL and import it. Subject to a size cap to
     * avoid unbounded fetches.
     *
     * @return array{imported: array<int, string>, skipped: array<int, array>}
     */
    public function importZipFromUrl(string $url): array
    {
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new RuntimeException('Only http/https URLs accepted.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vela-ds-');
        try {
            $resp = Http::timeout(60)->sink($tmp)->get($url);
            if (!$resp->successful()) {
                throw new RuntimeException("Download failed: HTTP {$resp->status()}");
            }
            if (filesize($tmp) > self::MAX_ZIP_DOWNLOAD) {
                throw new RuntimeException('Downloaded file exceeds 100 MB.');
            }
            return $this->importZip($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ── Manifest ───────────────────────────────────────────────────────────

    public function rebuildManifest(): array
    {
        $manifest = [
            'generated_at' => date('c'),
            'files'        => $this->files(),
            'palette'      => $this->palette(),
            'fonts'        => $this->fonts(),
        ];
        // Best-effort — if the file can't be written, the in-memory result
        // is still accurate and callers won't notice.
        @file_put_contents(
            $this->path('manifest.json'),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        return $manifest;
    }

    public function manifest(): array
    {
        $raw = @file_get_contents($this->path('manifest.json'));
        $parsed = $raw ? json_decode($raw, true) : null;
        return is_array($parsed) ? $parsed : $this->rebuildManifest();
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->files() as $f) {
            $total += (int) $f['bytes'];
        }
        return $total;
    }

    // ── Internal ───────────────────────────────────────────────────────────

    private function validateName(string $name): void
    {
        if (in_array($name, self::RESERVED_FILES, true)) {
            throw new RuntimeException("reserved filename: {$name}");
        }
        if (!preg_match(self::FILENAME_REGEX, $name)) {
            throw new RuntimeException("invalid filename: {$name} (use letters, digits, _, -, .)");
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTS, true)) {
            throw new RuntimeException("extension not allowed: .{$ext}. Allowed: " . implode(', ', self::ALLOWED_EXTS));
        }
    }

    private function resolveForRead(string $name): string
    {
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw new RuntimeException("invalid filename");
        }
        $path = $this->path($name);
        if (!is_file($path)) {
            throw new RuntimeException("file not found: {$name}");
        }
        return $path;
    }

    private function ensureTotalUnderLimit(int $incomingBytes): void
    {
        if ($this->totalBytes() + $incomingBytes > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('Design system total size would exceed ' . self::MAX_TOTAL_BYTES . ' bytes.');
        }
    }

    private function normaliseHex(string $hex): string
    {
        $hex = trim($hex);
        if (!str_starts_with($hex, '#')) {
            $hex = '#' . $hex;
        }
        if (!preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $hex)) {
            return '#000000';
        }
        return strtolower($hex);
    }

    private function defaultPalette(): array
    {
        return [
            'name' => 'Default',
            'entries' => [
                ['name' => 'Brand',   'slug' => 'brand',   'hex' => '#4f46e5', 'description' => 'Primary brand colour / CTAs'],
                ['name' => 'Ink',     'slug' => 'ink',     'hex' => '#0f172a', 'description' => 'Headings and strong text'],
                ['name' => 'Body',    'slug' => 'body',    'hex' => '#334155', 'description' => 'Body copy'],
                ['name' => 'Muted',   'slug' => 'muted',   'hex' => '#64748b', 'description' => 'Secondary text'],
                ['name' => 'Surface', 'slug' => 'surface', 'hex' => '#ffffff', 'description' => 'Card / panel backgrounds'],
                ['name' => 'Bg',      'slug' => 'bg',      'hex' => '#f8fafc', 'description' => 'Page background'],
                ['name' => 'Border',  'slug' => 'border',  'hex' => '#e5e7eb', 'description' => 'Borders and dividers'],
                ['name' => 'Accent',  'slug' => 'accent',  'hex' => '#f59e0b', 'description' => 'Warm accent / highlights'],
            ],
        ];
    }

    private function defaultFonts(): array
    {
        return [
            'entries' => [
                [
                    'role'       => 'display',
                    'family'     => 'Fraunces',
                    'source_url' => 'https://fonts.bunny.net/css2?family=Fraunces:wght@300..700&display=swap',
                    'weights'    => [300, 400, 500, 600, 700],
                    'fallback'   => 'serif',
                ],
                [
                    'role'       => 'body',
                    'family'     => 'Geist',
                    'source_url' => 'https://fonts.bunny.net/css2?family=Geist:wght@300..700&display=swap',
                    'weights'    => [300, 400, 500, 600, 700],
                    'fallback'   => 'sans-serif',
                ],
                [
                    'role'       => 'mono',
                    'family'     => 'Geist Mono',
                    'source_url' => 'https://fonts.bunny.net/css2?family=Geist+Mono:wght@400..600&display=swap',
                    'weights'    => [400, 500, 600],
                    'fallback'   => 'monospace',
                ],
            ],
        ];
    }

    private function defaultReadme(): string
    {
        return <<<'MD'
# Design System

This folder holds the project's design system — asset files (markdown docs,
HTML fragments, images), a named colour palette, and font choices.

## Structure

```
designsystem/
├── manifest.json   # auto-generated index of everything in this folder
├── palette.json    # named colour entries — exposed in the admin block editor
├── fonts.json      # font families + sources + weights
└── <asset files>   # md / html / images — flat structure, no subdirectories
```

## Editing

Use `/admin/settings/design-system` in the Vela admin to add, remove, or
replace files; edit the colour palette; and change font choices. You can
also upload a ZIP or import one from a URL (e.g. a Claude-generated design).

## Direct file edits

Editing files in this folder directly is fine — just run
`php artisan vela:design-system:refresh` afterwards (or visit the admin
page) to regenerate `manifest.json`.

## Used by

- **Admin block editor** — colour inputs offer the palette as presets;
  font selectors list the configured font families.
- **AI chatbot** — can browse this folder via `design_system_*` tools
  instead of receiving the full content on every request.
- **Deploys** — committed to git so `pushgit.sh` ships it alongside the
  rest of the site.

Do not place secrets, private documentation, or build artefacts here —
this folder is served from the admin UI and readable by the AI chatbot.
MD;
    }
}
