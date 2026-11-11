<?php

namespace VelaBuild\Core\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use VelaBuild\Core\Support\TextDiff;

/**
 * Which of a site's template overrides are older than the plugin's template.
 *
 * A plugin's views can be overridden by the site: a copy at
 * resources/views/vendor/{namespace}/... is used instead of the plugin's own
 * file. That copy never changes when the plugin does, so a plugin update that
 * fixes or extends a template does not reach a site that overrode it.
 *
 * As WooCommerce does, a plugin states each template's version in its header:
 *
 *     {{-- @version 1.2.0 --}}
 *
 * raising it whenever the template changes in a way an override should
 * follow. An override with a lower version, or none at all, is outdated. It is
 * reported, never changed: the site's copy is the site's own code.
 *
 * Works for every package that registers views with loadViewsFrom(); only
 * templates whose plugin file carries a version are checked.
 */
class TemplateVersions
{
    public const OUTDATED = 'outdated';

    public const CURRENT = 'current';

    private const CACHE_KEY = 'vela:template-versions:outdated';

    private const CACHE_SECONDS = 300;

    /** Only the start of a file is read for its header. */
    private const HEADER_BYTES = 2048;

    /**
     * The version a template states in its header, or null.
     */
    public static function versionOf(string $contents): ?string
    {
        return preg_match('/@version\s+v?(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.\-]+)?)/', substr($contents, 0, self::HEADER_BYTES), $m)
            ? $m[1]
            : null;
    }

    /**
     * Every override of a versioned plugin template.
     *
     * @return array<int, array{view: string, namespace: string, package: ?string, path: string, site_version: ?string, plugin_version: string, status: string}>
     *         path is relative to the site's root
     */
    public function report(): array
    {
        $report = [];

        foreach ($this->overrides() as $override) {
            $pluginVersion = self::versionOf($this->head($override['plugin_file']));
            if ($pluginVersion === null) {
                continue;
            }

            $siteVersion = self::versionOf($this->head($override['site_file']));
            $outdated = $siteVersion === null || version_compare($siteVersion, $pluginVersion, '<');

            $report[] = [
                'view'           => $override['view'],
                'namespace'      => $override['namespace'],
                'package'        => $this->packageFor($override['plugin_file']),
                'path'           => $this->relative($override['site_file']),
                'site_version'   => $siteVersion,
                'plugin_version' => $pluginVersion,
                'status'         => $outdated ? self::OUTDATED : self::CURRENT,
            ];
        }

        return $report;
    }

    /** @return array<int, array> the outdated entries of report() */
    public function outdated(): array
    {
        return array_values(array_filter($this->report(), fn ($t) => $t['status'] === self::OUTDATED));
    }

    /** count(outdated()), remembered for a few minutes: the admin asks on every page. */
    public function outdatedCount(): int
    {
        try {
            return (int) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => count($this->outdated()));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The site's override against the plugin's current template, as a unified diff.
     *
     * @param string $path an override's path relative to the site's root, as report() gives it
     *
     * @throws \InvalidArgumentException for anything that is not a versioned override
     */
    public function diff(string $path): string
    {
        foreach ($this->overrides() as $override) {
            if ($this->relative($override['site_file']) !== $path) {
                continue;
            }

            return TextDiff::unified(
                (string) file_get_contents($override['site_file']),
                (string) file_get_contents($override['plugin_file']),
                'site/' . $path,
                'plugin/' . $override['view'],
            );
        }

        throw new \InvalidArgumentException('Not a template override: ' . $path);
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // No cache to forget from.
        }
    }

    /**
     * Each file in a site's override folder that a package has a template for.
     *
     * @return array<int, array{view: string, namespace: string, site_file: string, plugin_file: string}>
     */
    private function overrides(): array
    {
        $overrides = [];
        $siteRoots = array_map(fn ($p) => rtrim((string) $p, '/'), (array) config('view.paths', [resource_path('views')]));

        foreach (app('view')->getFinder()->getHints() as $namespace => $paths) {
            $pluginPaths = array_values(array_filter(
                array_map(fn ($p) => rtrim((string) $p, '/'), (array) $paths),
                fn ($p) => !$this->isOverrideFolder($p, $siteRoots),
            ));
            if ($pluginPaths === []) {
                continue;
            }

            foreach ($siteRoots as $root) {
                $folder = $root . '/vendor/' . $namespace;
                if (!is_dir($folder)) {
                    continue;
                }

                foreach (File::allFiles($folder) as $file) {
                    $relative = str_replace('\\', '/', $file->getRelativePathname());
                    if (!str_ends_with($relative, '.blade.php')) {
                        continue;
                    }

                    foreach ($pluginPaths as $pluginPath) {
                        if (is_file($pluginPath . '/' . $relative)) {
                            $overrides[] = [
                                'view'        => $namespace . '::' . str_replace('/', '.', substr($relative, 0, -strlen('.blade.php'))),
                                'namespace'   => $namespace,
                                'site_file'   => $file->getPathname(),
                                'plugin_file' => $pluginPath . '/' . $relative,
                            ];
                            break;
                        }
                    }
                }
            }
        }

        usort($overrides, fn ($a, $b) => strcmp($a['view'], $b['view']));

        return $overrides;
    }

    private function isOverrideFolder(string $path, array $siteRoots): bool
    {
        foreach ($siteRoots as $root) {
            if (str_starts_with($path . '/', $root . '/vendor/')) {
                return true;
            }
        }

        return false;
    }

    private function head(string $file): string
    {
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return '';
        }
        $head = (string) fread($handle, self::HEADER_BYTES);
        fclose($handle);

        return $head;
    }

    /** The Composer package a plugin file belongs to, if it can be told. */
    private function packageFor(string $file): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        // The deepest install folder wins: the site itself is a package too,
        // and its folder holds vendor/.
        $real = realpath($file) ?: $file;
        $best = null;
        $bestLength = 0;
        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $root = InstalledVersions::getInstallPath($name);
            if (!$root) {
                continue;
            }
            $root = rtrim(realpath($root) ?: $root, '/') . '/';
            if (strlen($root) > $bestLength && str_starts_with($real, $root)) {
                $best = $name;
                $bestLength = strlen($root);
            }
        }

        return $best;
    }

    private function relative(string $file): string
    {
        $base = rtrim(base_path(), '/') . '/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
