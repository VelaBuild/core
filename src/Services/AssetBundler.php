<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;
use MatthiasMullie\Minify\CSS as CssMin;
use MatthiasMullie\Minify\JS as JsMin;

/**
 * Combine + minify CSS/JS into hashed bundles that ship with the static cache.
 *
 * Build once (at static-cache regen time) via `vela:assets:build`, then each
 * template layout pulls in named bundles with `@velaAssets('public', 'template-x')`.
 * Bundle filenames include a content hash so they're immutably cacheable
 * (`Cache-Control: public, max-age=31536000, immutable` in a well-configured
 * web server).
 *
 * Bundles are defined in `config('vela.assets.bundles')`. Host apps can add
 * or override bundles in their own `config/vela.php` (Laravel config merges).
 */
class AssetBundler
{
    protected string $outputDir;
    protected string $publicPath;
    protected string $manifestPath;
    protected bool $enabled;
    protected bool $minify;
    protected ?array $manifestCache = null;

    public function __construct()
    {
        $this->outputDir = config('vela.assets.output_dir', public_path('vendor/vela/bundles'));
        $this->publicPath = config('vela.assets.public_path', '/vendor/vela/bundles');
        $this->manifestPath = config('vela.assets.manifest', $this->outputDir . '/manifest.json');
        $this->enabled = (bool) config('vela.assets.enabled', true);
        $this->minify = (bool) config('vela.assets.minify', true);
    }

    protected function bundles(): array
    {
        return config('vela.assets.bundles', []);
    }

    /**
     * Build one or all bundles; returns the manifest.
     * Each manifest entry: "bundle.css" => "bundle-abc123def456.css".
     */
    public function build(?string $only = null): array
    {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0775, true);
        }

        $allBundles = $this->bundles();
        $bundles = $only ? [$only => $allBundles[$only] ?? []] : $allBundles;

        // Preserve prior entries when building a single bundle.
        $manifest = ($only && is_file($this->manifestPath))
            ? (json_decode(file_get_contents($this->manifestPath), true) ?: [])
            : [];

        foreach ($bundles as $name => $def) {
            foreach (['css', 'js'] as $type) {
                $files = $def[$type] ?? [];
                $built = $this->buildBundle($name, $type, $files);
                $key = "{$name}.{$type}";
                if ($built !== null) {
                    $manifest[$key] = $built;
                } else {
                    // Bundle defined with no valid sources — remove stale entry.
                    unset($manifest[$key]);
                }
            }
        }

        ksort($manifest);
        file_put_contents($this->manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->manifestCache = $manifest;

        return $manifest;
    }

    protected function buildBundle(string $name, string $type, array $files): ?string
    {
        if (empty($files)) {
            return null;
        }

        $content = '';
        $found = 0;
        foreach ($files as $file) {
            $path = $this->resolvePath($file);
            if (!is_file($path)) {
                Log::warning("AssetBundler: source file missing: {$file} (resolved to {$path})");
                continue;
            }
            $content .= "/* === {$file} === */\n" . file_get_contents($path) . "\n";
            $found++;
        }

        if ($found === 0) {
            return null;
        }

        if ($this->minify) {
            $content = $type === 'css'
                ? (new CssMin())->add($content)->minify()
                : (new JsMin())->add($content)->minify();
        }

        $hash = substr(hash('sha256', $content), 0, 12);
        $filename = "{$name}-{$hash}.{$type}";
        $target = $this->outputDir . '/' . $filename;

        if (!is_file($target)) {
            $tmp = $target . '.tmp';
            file_put_contents($tmp, $content);
            @chmod($tmp, 0664);
            rename($tmp, $target);
        }

        $this->pruneOld($name, $type, $filename);

        return $filename;
    }

    /** Keep the 3 newest versions of each bundle for graceful rollback. */
    protected function pruneOld(string $name, string $type, string $keep): void
    {
        $pattern = $this->outputDir . "/{$name}-*." . $type;
        $files = glob($pattern) ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, 3) as $f) {
            if (basename($f) !== $keep) {
                @unlink($f);
            }
        }
    }

    protected function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }
        return base_path($file);
    }

    public function manifest(): array
    {
        if ($this->manifestCache !== null) {
            return $this->manifestCache;
        }
        if (is_file($this->manifestPath)) {
            return $this->manifestCache = json_decode(file_get_contents($this->manifestPath), true) ?: [];
        }
        return $this->manifestCache = [];
    }

    /**
     * Emit <link>/<script> tags for one or more bundles.
     * When bundling is disabled (dev), falls back to individual source files
     * with mtime cache-busting so local edits are visible without a rebuild.
     */
    public function tags(array $bundleNames): string
    {
        if (!$this->enabled) {
            return $this->rawTags($bundleNames);
        }

        $manifest = $this->manifest();
        $html = '';

        // CSS first (all bundles), then JS — keeps head/body ordering sane.
        foreach ($bundleNames as $bundle) {
            $key = "{$bundle}.css";
            if (isset($manifest[$key])) {
                $url = rtrim($this->publicPath, '/') . '/' . $manifest[$key];
                $html .= '<link rel="stylesheet" href="' . e($url) . '">' . "\n";
            }
        }

        foreach ($bundleNames as $bundle) {
            $key = "{$bundle}.js";
            if (isset($manifest[$key])) {
                $url = rtrim($this->publicPath, '/') . '/' . $manifest[$key];
                $html .= '<script src="' . e($url) . '" defer></script>' . "\n";
            }
        }

        return $html;
    }

    protected function rawTags(array $bundleNames): string
    {
        $allBundles = $this->bundles();
        $html = '';

        foreach ($bundleNames as $bundle) {
            $def = $allBundles[$bundle] ?? null;
            if (!$def) continue;
            foreach ($def['css'] ?? [] as $f) {
                $html .= '<link rel="stylesheet" href="' . e($this->sourceUrl($f)) . '">' . "\n";
            }
        }

        foreach ($bundleNames as $bundle) {
            $def = $allBundles[$bundle] ?? null;
            if (!$def) continue;
            foreach ($def['js'] ?? [] as $f) {
                $html .= '<script src="' . e($this->sourceUrl($f)) . '" defer></script>' . "\n";
            }
        }

        return $html;
    }

    protected function sourceUrl(string $file): string
    {
        $url = '/' . ltrim(preg_replace('#^public/#', '', $file), '/');
        $path = $this->resolvePath($file);
        if (is_file($path)) {
            $url .= '?v=' . filemtime($path);
        }
        return $url;
    }
}
