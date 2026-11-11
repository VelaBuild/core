<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use VelaBuild\Core\Support\TextDiff;

/**
 * Keeps the public files plugins publish (their CSS, JS, images) in step with
 * the installed plugin, without losing a change the site made to one.
 *
 * Plugins list those files under the `laravel-assets` publish tag. Publishing
 * them without --force left every file the site already had untouched, so a
 * plugin update never reached the site; with --force, a file the site had
 * edited would be lost. Instead, every file copied is remembered by its hash:
 *
 * - the site's copy still matches what was copied last time → nobody changed
 *   it, so the plugin's new version replaces it;
 * - it does not match → the site changed it: it is kept, and reported;
 * - there is no record (published before this existed) → the site's copy is
 *   backed up to storage/app/vela-plugin-assets/backups, then replaced.
 *
 * Only destinations inside public/ are handled. Templates a site overrides
 * live in resources/views and are never copied here (see TemplateVersions).
 */
class PluginAssetSync
{
    public const TAG = 'laravel-assets';

    private const CACHE_KEY = 'vela:plugin-assets:modified';

    private const CACHE_SECONDS = 300;

    private string $statePath;

    private ?string $backupStamp = null;

    public function __construct(?string $statePath = null)
    {
        $this->statePath = rtrim($statePath ?? storage_path('app/vela-plugin-assets'), '/');
    }

    /**
     * Every file published under the tag into public/, as site path => plugin path.
     *
     * @return array<string, string> keys relative to public/
     */
    public function files(): array
    {
        $public = rtrim(public_path(), '/') . '/';
        $files = [];

        foreach (ServiceProvider::pathsToPublish(null, self::TAG) as $from => $to) {
            $to = rtrim($to, '/');
            if (!str_starts_with($to . '/', $public)) {
                continue;
            }
            $prefix = substr($to, strlen($public));

            if (is_file($from)) {
                $files[$prefix] = $from;
            } elseif (is_dir($from)) {
                foreach (File::allFiles($from, true) as $file) {
                    $files[$prefix . '/' . str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Bring the site's copies up to date, keeping any the site changed.
     *
     * @return array{updated: string[], kept: string[], backed_up: string[], backup: ?string}
     */
    public function sync(): array
    {
        $state = $this->state();
        $done = ['updated' => [], 'kept' => [], 'backed_up' => [], 'backup' => null];
        $this->backupStamp = null;

        foreach ($this->files() as $path => $source) {
            $site = public_path($path);
            $wanted = sha1_file($source);

            if (is_file($site)) {
                $have = sha1_file($site);

                if ($have === $wanted) {
                    $state[$path] = $wanted;
                    continue;
                }

                if (isset($state[$path]) && $state[$path] !== $have) {
                    $done['kept'][] = $path;
                    continue;
                }

                if (!isset($state[$path])) {
                    $this->backup($path, $site);
                    $done['backed_up'][] = $path;
                }
            }

            File::ensureDirectoryExists(dirname($site));
            File::copy($source, $site);
            $state[$path] = $wanted;
            $done['updated'][] = $path;
        }

        $this->saveState($state);
        self::forget();

        if ($this->backupStamp !== null) {
            $done['backup'] = $this->statePath . '/backups/' . $this->backupStamp;
        }

        return $done;
    }

    /**
     * The files the site changed and the plugin has a different version of.
     *
     * @return string[] paths relative to public/
     */
    public function modified(): array
    {
        $state = $this->state();
        $modified = [];

        foreach ($this->files() as $path => $source) {
            $site = public_path($path);
            if (!isset($state[$path]) || !is_file($site)) {
                continue;
            }
            $have = sha1_file($site);
            if ($have !== $state[$path] && $have !== sha1_file($source)) {
                $modified[] = $path;
            }
        }

        return $modified;
    }

    /** modified(), remembered for a few minutes: the admin asks on every page. */
    public function modifiedCount(): int
    {
        try {
            return (int) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => count($this->modified()));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * What the site's copy has that the plugin's does not, as a unified diff.
     *
     * @throws \InvalidArgumentException for a path the plugins do not publish
     */
    public function diff(string $path): string
    {
        $files = $this->files();
        if (!isset($files[$path])) {
            throw new \InvalidArgumentException('Not a published plugin file: ' . $path);
        }

        $site = public_path($path);

        return TextDiff::unified(
            is_file($site) ? (string) file_get_contents($site) : '',
            (string) file_get_contents($files[$path]),
            'site/public/' . $path,
            'plugin/public/' . $path,
        );
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // No cache yet (during installation): nothing remembered to forget.
        }
    }

    /** @return array<string, string> */
    private function state(): array
    {
        $state = json_decode((string) @file_get_contents($this->statePath . '/state.json'), true);

        return is_array($state) ? array_filter($state, 'is_string') : [];
    }

    private function saveState(array $state): void
    {
        ksort($state);
        File::ensureDirectoryExists($this->statePath);
        $tmp = $this->statePath . '/.state.json.' . getmypid();
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, $this->statePath . '/state.json');
    }

    private function backup(string $path, string $site): void
    {
        $this->backupStamp ??= date('Ymd-His');
        $dest = $this->statePath . '/backups/' . $this->backupStamp . '/' . $path;
        File::ensureDirectoryExists(dirname($dest));
        File::copy($site, $dest);
    }
}
