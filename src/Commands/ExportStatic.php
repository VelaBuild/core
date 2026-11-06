<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * A browsable static copy of the published site: every pre-rendered page at
 * the URL it is served from (the same mapping StaticFrontController uses),
 * plus the public assets and uploads, as one zip. Host it anywhere that serves
 * files — no PHP, no database.
 *
 * Gated by the plan's `export` feature on hosted sites (always on self-hosted).
 */
class ExportStatic extends Command
{
    protected $signature = 'vela:export-static
                            {--output= : Zip path (default storage/app/exports/static-<time>.zip)}
                            {--base-url= : Rewrite the site\'s own absolute URLs to this base (default: root-relative)}';

    protected $description = 'Export the published site as a static zip (HTML + assets)';

    /** Public files a static host must not get. */
    private const SKIP_PUBLIC = ['/\.php$/i', '/(^|\/)\.ht/', '/^hot$/', '/(^|\/)\.(git|env)/'];

    public function handle(): int
    {
        if (!vela_feature('export')) {
            $this->error("Static export isn't included in this site's plan.");

            return self::FAILURE;
        }
        if (!class_exists(ZipArchive::class)) {
            $this->error('The PHP zip extension is required.');

            return self::FAILURE;
        }

        $static = config('vela.static.path', resource_path('static'));
        if (!is_dir($static)) {
            $this->error('Nothing has been published yet (no ' . $static . ').');

            return self::FAILURE;
        }

        $output = $this->option('output') ?: storage_path('app/exports/static-' . now()->format('Ymd-His') . '.zip');
        @mkdir(dirname($output), 0770, true);
        $appUrl = rtrim((string) config('app.url'), '/');
        $base = rtrim((string) ($this->option('base-url') ?? ''), '/');

        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Can't write {$output}.");

            return self::FAILURE;
        }

        $pages = 0;
        foreach ((new Finder())->files()->in($static)->name('*.html') as $file) {
            $target = self::urlPathFor($file->getRelativePathname());
            if ($target === null) {
                continue;
            }
            $html = (string) file_get_contents($file->getRealPath());
            if ($appUrl !== '') {
                $html = str_replace([$appUrl . '/', $appUrl], [$base . '/', $base === '' ? '/' : $base], $html);
            }
            $zip->addFromString($target, $html);
            $pages++;
        }

        $assets = 0;
        $public = public_path();
        foreach ((new Finder())->files()->in($public)->followLinks()->ignoreDotFiles(false) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            foreach (self::SKIP_PUBLIC as $re) {
                if (preg_match($re, $rel)) {
                    continue 2;
                }
            }
            if (str_ends_with($rel, '.css') && $appUrl !== '') {
                $zip->addFromString($rel, str_replace($appUrl, $base, (string) file_get_contents($file->getRealPath())));
            } else {
                $zip->addFile($file->getRealPath(), $rel);
            }
            $assets++;
        }

        $zip->close();
        $this->info("Exported {$pages} pages and {$assets} files to {$output}");
        $this->line($output);

        return self::SUCCESS;
    }

    /**
     * resources/static path → URL path in the export, mirroring
     * StaticFrontController::staticFileFor (home, posts, categories, pages, and
     * their translations/<locale>.html variants).
     */
    public static function urlPathFor(string $rel): ?string
    {
        $rel = str_replace('\\', '/', $rel);
        if (!preg_match('#^(home|posts|categories|pages)(?:/(.*?))?/?(index\.html|translations/([a-z]{2}(?:-[A-Za-z]{2,4})?)\.html)$#', $rel, $m)) {
            return null;
        }
        [$type, $path, $locale] = [$m[1], trim($m[2] ?? '', '/'), $m[4] ?? null];

        $urlPath = match ($type) {
            'home'  => '',
            'pages' => $path,
            default => trim($type . '/' . $path, '/'),
        };
        if ($type === 'pages' && $path === '') {
            return null;
        }
        $prefix = $locale ? $locale . '/' : '';

        return ltrim($prefix . ($urlPath === '' ? '' : $urlPath . '/') . 'index.html', '/');
    }
}
