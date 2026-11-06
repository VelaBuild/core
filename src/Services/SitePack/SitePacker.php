<?php

namespace VelaBuild\Core\Services\SitePack;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Packs this site for Vela Hosted: one .tar.gz with
 *
 *   vela-manifest.json   what the site is (URL, key, versions, tables, migrations)
 *   data/<table>.jsonl   every row, one JSON object per line
 *   app/…                the site's own files (themes, uploads, custom code)
 *
 * Rows rather than an SQL dump, so it works the same from MySQL, MariaDB,
 * PostgreSQL or SQLite and needs no mysqldump on the customer's machine. The
 * host rebuilds the schema by running the same migrations, then loads the rows
 * (DataImporter). vendor/ and the published Vela assets are never packed: the
 * host supplies its own build of the platform.
 */
class SitePacker
{
    public const FORMAT = 'vela-site/1';

    /** Packages every Vela starter requires; anything else in composer.json is the site's own. */
    public const BASE_REQUIRES = ['php', 'guzzlehttp/guzzle', 'laravel/framework', 'laravel/sanctum', 'laravel/tinker', 'velabuild/core'];

    /** Runtime state, not content. */
    public const SKIP_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'password_resets', 'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'pulse_aggregates', 'pulse_entries', 'pulse_values'];

    /** Runtime-state tables, with or without Vela's `vela_` prefix. */
    public static function skipTable(string $table): bool
    {
        return in_array($table, self::SKIP_TABLES, true)
            || (str_starts_with($table, 'vela_') && in_array(substr($table, 5), self::SKIP_TABLES, true));
    }

    /** Paths under the site root that are never packed (prefix match on whole segments). */
    public const SKIP_PATHS = ['vendor', 'node_modules', '.git', '.idea', '.vscode', '.fleet', '.claude', '.autocoder',
        'bootstrap/cache', 'storage/framework', 'storage/logs', 'storage/.cache', 'storage/tmp', 'storage/app/exports',
        'storage/app/vela-browser', 'storage/app/image-cache', 'storage/app/marketplace-backup', 'storage/app/vela-import',
        'public/vendor/vela', 'public/storage', 'public/hot', 'auth.json', '.phpunit.cache', '.phpunit.result.cache'];

    public function __construct(private ?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/');
    }

    /** Problems that stop a site moving to Vela Hosted, as sentences. */
    public function blockers(): array
    {
        $own = $this->ownPackages();

        return $own ? ['This site uses Composer packages Vela Hosted does not install for you: ' . implode(', ', $own)
            . '. Remove them (composer remove), pack again, then add them from the Marketplace once the site is hosted.'] : [];
    }

    /** @return string[] */
    public function ownPackages(): array
    {
        $composer = json_decode((string) @file_get_contents($this->basePath . '/composer.json'), true);
        $requires = array_keys((array) ($composer['require'] ?? []));

        return array_values(array_filter($requires, fn ($name) => !in_array($name, self::BASE_REQUIRES, true)
            && !str_starts_with($name, 'ext-')));
    }

    /**
     * @param  callable(string): void|null  $progress
     * @return array{path: string, manifest: array, files: int, bytes: int}
     */
    public function pack(string $output, ?callable $progress = null): array
    {
        $progress ??= fn () => null;
        $output = $this->absolute($output);
        $tmp = $output . '.part';
        $tar = new TarGzWriter($tmp);
        try {
            $progress('Reading the database');
            [$tables, $migrations] = $this->packData($tar);

            $progress('Collecting files');
            $files = 0;
            $tar->addDirectory('app');
            foreach ($this->files($output, $tmp) as $relative) {
                $tar->addFile('app/' . $relative, $this->basePath . '/' . $relative);
                $files++;
            }

            $manifest = [
                'format'       => self::FORMAT,
                'created_at'   => gmdate('c'),
                'site_name'    => (string) config('app.name'),
                'app_url'      => rtrim((string) config('app.url'), '/'),
                'app_key'      => (string) config('app.key'),
                'locale'       => (string) config('app.locale'),
                'core_version' => $this->version('velabuild/core'),
                'laravel'      => app()->version(),
                'php'          => PHP_VERSION,
                'db_driver'    => DB::connection()->getDriverName(),
                'requires'     => $this->ownPackages(),
                'tables'       => $tables,
                'migrations'   => $migrations,
                'files'        => $files,
            ];
            $tar->addString('vela-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $stats = $tar->close();
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        rename($tmp, $output);
        unset($manifest['app_key']);

        return ['path' => $output, 'manifest' => $manifest, 'files' => $files, 'bytes' => (int) filesize($output)] + $stats;
    }

    /** @return array{0: array<string, int>, 1: string[]} rows per table, migrations that ran */
    private function packData(TarGzWriter $tar): array
    {
        $schema = Schema::connection(null);
        $listing = method_exists($schema, 'getCurrentSchemaName')
            ? $schema->getTableListing($schema->getCurrentSchemaName(), false)
            : $schema->getTableListing();
        $prefix = DB::connection()->getTablePrefix();
        $tables = [];
        $scratch = tempnam(sys_get_temp_dir(), 'vela-pack');
        try {
            foreach ($listing as $full) {
                $table = $prefix !== '' && str_starts_with($full, $prefix) ? substr($full, strlen($prefix)) : $full;
                if (self::skipTable($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)) {
                    continue;
                }
                $out = fopen($scratch, 'wb');
                $rows = 0;
                foreach (DB::table($table)->cursor() as $row) {
                    fwrite($out, json_encode(array_map([$this, 'encode'], (array) $row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                    $rows++;
                }
                fclose($out);
                $tar->addFile("data/{$table}.jsonl", $scratch);
                $tables[$table] = $rows;
            }
        } finally {
            @unlink($scratch);
        }
        $migrations = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('id')->pluck('migration')->all()
            : [];

        return [$tables, $migrations];
    }

    /** JSON can't carry bytes that aren't UTF-8 (binary columns): those go as base64. */
    private function encode(mixed $value): mixed
    {
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['$b64' => base64_encode($value)];
        }
        if (is_resource($value)) {
            return ['$b64' => base64_encode((string) stream_get_contents($value))];
        }

        return $value;
    }

    /** @return \Generator<string> paths relative to the site root */
    private function files(string ...$exclude): \Generator
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($exclude) {
                    $relative = substr($file->getPathname(), strlen($this->basePath) + 1);
                    if ($file->isLink() || $this->skipped($relative) || in_array($file->getPathname(), $exclude, true)) {
                        return false;
                    }

                    return $file->isDir() || $file->isFile();
                }
            )
        );
        foreach ($it as $file) {
            yield str_replace('\\', '/', substr($file->getPathname(), strlen($this->basePath) + 1));
        }
    }

    private function skipped(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        $name = basename($relative);
        if ($name === '.env' || str_starts_with($name, '.env.') && $name !== '.env.example'
            || preg_match('/\.(sqlite|sqlite3|tar\.gz)$/', $name)) {
            return true;
        }
        foreach (self::SKIP_PATHS as $skip) {
            if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                return true;
            }
        }

        return false;
    }

    private function version(string $package): string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)
            ? (string) InstalledVersions::getPrettyVersion($package)
            : 'dev';
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) ? $path : getcwd() . '/' . $path;
    }
}
