<?php

namespace VelaBuild\Core\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a site's copy of the package's public files in step with the package.
 *
 * A site updates Vela with `composer update velabuild/core`, which replaces
 * the PHP and views at once but not what the site serves from
 * public/vendor/vela: those are copies, made by `vendor:publish`, and the
 * public stylesheet is a bundle built from them by `vela:assets:build`. The
 * starter's README named the first step and not the second, and nothing ran
 * either. A site updated that way served new views against old JavaScript and
 * an old bundle — a carousel view loading a script that was not there, a
 * gallery layout with no CSS behind it — until someone knew to publish.
 *
 * So the site does it itself: on a web request, if the package's public files
 * are not the ones last copied, copy them, rebuild the bundles, and give the
 * site's own themes any `--vela-*` declarations they lack. Nothing in
 * public/vendor/vela belongs to the site, so replacing it loses nothing.
 */
class AssetSync
{
    /** How long to leave a failed sync before trying again, in seconds. */
    private const RETRY_AFTER = 600;

    public function __construct(private AssetBundler $bundler, private ThemeAuthor $author)
    {
    }

    private function source(): string
    {
        return realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
    }

    private function target(): string
    {
        return public_path('vendor/vela');
    }

    private function stampPath(): string
    {
        // Per environment: two rigs can share one public folder and still
        // build their bundles separately.
        return $this->target() . '/.synced-' . preg_replace('/[^a-z0-9_-]/i', '', (string) app()->environment());
    }

    /**
     * What the package's public files are now.
     *
     * The installed commit, which changes with every release, plus the newest
     * modification time among the files, which is what changes when the
     * package is a local checkout being edited and the commit stays put.
     */
    public function fingerprint(): string
    {
        $reference = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('velabuild/core')
            ? (string) InstalledVersions::getReference('velabuild/core')
            : '';

        $newest = 0;
        foreach (File::allFiles($this->source()) as $file) {
            $newest = max($newest, $file->getMTime());
        }

        return sha1($reference . ':' . $newest);
    }

    public function isCurrent(): bool
    {
        $stamp = $this->stampPath();

        return is_file($stamp) && trim((string) @file_get_contents($stamp)) === $this->fingerprint();
    }

    /**
     * Bring the site's copy up to date if it is not. Returns what was done, or
     * null when there was nothing to do (or another request is doing it).
     *
     * @return array{copied: bool, bundles: int, themes: string[]}|null
     */
    public function syncIfStale(bool $force = false): ?array
    {
        if (!$force && $this->isCurrent()) {
            return null;
        }

        $fingerprint = $this->fingerprint();

        // A site whose web user cannot write public/ would otherwise try, and
        // fail, on every request. It waits, and says so once in the log.
        if (!$force && $this->cache(fn () => Cache::get('vela:asset-sync:failed')) === $fingerprint) {
            return null;
        }

        // Two first requests after an update should not both copy. The lock
        // is a courtesy, not a requirement: during installation `composer
        // install` runs this before there is a database, and a cache kept in
        // one cannot lock anything yet.
        $lock = $this->cache(fn () => Cache::lock('vela:asset-sync', 120));
        $held = $lock ? $this->cache(fn () => $lock->get()) : null;
        if ($held === false) {
            return null; // another request is already doing it
        }
        if ($held === null) {
            $lock = null; // no cache to lock with; go ahead unlocked
        }

        try {
            File::ensureDirectoryExists($this->target());
            File::copyDirectory($this->source(), $this->target());

            $manifest = $this->bundler->build();
            $themes = $this->repairThemes();

            file_put_contents($this->stampPath(), $fingerprint);
            $this->cache(fn () => Cache::forget('vela:asset-sync:failed'));

            return ['copied' => true, 'bundles' => count($manifest), 'themes' => $themes];
        } catch (\Throwable $e) {
            $this->cache(fn () => Cache::put('vela:asset-sync:failed', $fingerprint, self::RETRY_AFTER));
            Log::warning('Vela could not update its public files after a package update: ' . $e->getMessage()
                . ' Run `php artisan vela:update` as a user that can write ' . $this->target() . '.');

            if ($force) {
                throw $e;
            }

            return null;
        } finally {
            if ($lock) {
                $this->cache(fn () => $lock->release());
            }
        }
    }

    /** A cache call, or null when there is no working cache to call. */
    private function cache(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Give each theme the site owns the `--vela-*` declarations it lacks.
     *
     * @return string[] the themes that were changed
     */
    public function repairThemes(): array
    {
        $changed = [];

        foreach (glob(resource_path('views/templates/*/layout.blade.php')) ?: [] as $layout) {
            $contents = (string) file_get_contents($layout);
            $repaired = $this->author->withContract($contents);

            if ($repaired !== $contents && is_writable($layout)) {
                file_put_contents($layout, $repaired);
                $changed[] = basename(dirname($layout));
            }
        }

        return $changed;
    }
}
