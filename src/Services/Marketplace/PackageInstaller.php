<?php

namespace VelaBuild\Core\Services\Marketplace;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class PackageInstaller
{
    private string $lockFile;
    private string $basePath;

    /** @var resource|false */
    private $lockHandle = false;

    public function __construct(private MarketplaceSettingsService $settings)
    {
        $this->lockFile = storage_path('app/.marketplace-lock');
        $this->basePath = base_path();
    }

    /**
     * Install a Composer package.
     *
     * @param  string|null  $licenseKey  the marketplace serves downloads only to a
     *                                   site that signs in with one of its license keys
     * @return array{success: bool, output: string, error: string}
     */
    public function install(string $composerName, ?string $licenseKey = null): array
    {
        $this->validateComposerName($composerName);

        $lock = $this->acquireLock();
        if (!$lock) {
            return ['success' => false, 'output' => '', 'error' => 'Another package operation is in progress. Please try again.'];
        }

        try {
            $this->backup();
            $this->ensureRepositoryRegistered();
            $this->ensureAuthJsonConfigured($licenseKey);

            $process = new Process(
                ['composer', 'require', $composerName, '--no-interaction', '--no-scripts'],
                $this->basePath,
                ['COMPOSER_MEMORY_LIMIT' => '-1'],
                null,
                300
            );

            $process->run();

            $output = $process->getOutput() . $process->getErrorOutput();

            if (!$process->isSuccessful()) {
                $this->restore();
                return ['success' => false, 'output' => $output, 'error' => 'Composer require failed.'];
            }

            $steps = $this->afterComposer(migrate: true);

            return ['success' => true, 'output' => $output . $steps, 'error' => '', 'warning' => $this->stepsWarning()];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Remove a Composer package.
     *
     * @return array{success: bool, output: string, error: string}
     */
    public function remove(string $composerName): array
    {
        $this->validateComposerName($composerName);

        $lock = $this->acquireLock();
        if (!$lock) {
            return ['success' => false, 'output' => '', 'error' => 'Another package operation is in progress. Please try again.'];
        }

        try {
            $this->backup();

            $process = new Process(
                ['composer', 'remove', $composerName, '--no-interaction', '--no-scripts'],
                $this->basePath,
                ['COMPOSER_MEMORY_LIMIT' => '-1'],
                null,
                300
            );

            $process->run();

            $output = $process->getOutput() . $process->getErrorOutput();

            if (!$process->isSuccessful()) {
                $this->restore();
                return ['success' => false, 'output' => $output, 'error' => 'Composer remove failed.'];
            }

            $steps = $this->afterComposer(migrate: false);

            return ['success' => true, 'output' => $output . $steps, 'error' => '', 'warning' => $this->stepsWarning()];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Update a Composer package (or all marketplace packages if null).
     *
     * @return array{success: bool, output: string, error: string}
     */
    public function update(?string $composerName = null): array
    {
        if ($composerName !== null) {
            $this->validateComposerName($composerName);
        }

        $lock = $this->acquireLock();
        if (!$lock) {
            return ['success' => false, 'output' => '', 'error' => 'Another package operation is in progress. Please try again.'];
        }

        try {
            $this->backup();

            $command = ['composer', 'update', '--no-interaction', '--no-scripts'];
            if ($composerName !== null) {
                array_splice($command, 2, 0, [$composerName]);
            }

            $process = new Process(
                $command,
                $this->basePath,
                ['COMPOSER_MEMORY_LIMIT' => '-1'],
                null,
                300
            );

            $process->run();

            $output = $process->getOutput() . $process->getErrorOutput();

            if (!$process->isSuccessful()) {
                $this->restore();
                return ['success' => false, 'output' => $output, 'error' => 'Composer update failed.'];
            }

            $steps = $this->afterComposer(migrate: true);

            return ['success' => true, 'output' => $output . $steps, 'error' => '', 'warning' => $this->stepsWarning()];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Ensure the marketplace Composer repository is registered in composer.json.
     */
    public function ensureRepositoryRegistered(): void
    {
        $composerJsonPath = $this->basePath . '/composer.json';
        $content = file_exists($composerJsonPath)
            ? json_decode(file_get_contents($composerJsonPath), true)
            : [];

        if (!isset($content['repositories'])) {
            $content['repositories'] = [];
        }

        $repositoryUrl = $this->repositoryUrl();
        $apiUrl = rtrim($this->settings->getApiUrl(), '/');
        $changed = false;
        $found = false;

        foreach ($content['repositories'] as $i => $repo) {
            $url = isset($repo['url']) ? rtrim($repo['url'], '/') : null;

            if ($url === $repositoryUrl) {
                $found = true;
            } elseif ($url === $apiUrl) {
                // Earlier releases registered the bare marketplace address, where
                // composer finds no packages.json (404). Point it at the API.
                $content['repositories'][$i]['url'] = $repositoryUrl;
                $found = $changed = true;
            }
        }

        if (!$found) {
            $content['repositories'][] = [
                'type' => 'composer',
                'url'  => $repositoryUrl,
            ];
            $changed = true;
        }

        if ($changed) {
            $this->atomicWriteJson($composerJsonPath, $content);
        }
    }

    /**
     * Packages in composer.lock that were downloaded from the marketplace.
     *
     * Lets the admin see a plugin Composer still has but the database lost
     * track of: it can't be removed from the packages list, and it breaks
     * hosted upgrades, which rebuild vendor/ without it.
     *
     * @return array<string, string>  composer name => version
     */
    public function marketplacePackagesInLock(): array
    {
        $lock = json_decode((string) @file_get_contents($this->basePath . '/composer.lock'), true);
        if (!is_array($lock)) {
            return [];
        }

        $repo = $this->repositoryUrl() . '/packages/';
        $found = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            $url = (string) ($pkg['dist']['url'] ?? '');
            if (isset($pkg['name']) && str_starts_with($url, $repo)) {
                $found[$pkg['name']] = (string) ($pkg['version'] ?? '');
            }
        }
        ksort($found);
        return $found;
    }

    /**
     * Where the marketplace serves its Composer repository (packages.json).
     */
    public function repositoryUrl(): string
    {
        return rtrim($this->settings->getApiUrl(), '/') . '/api';
    }

    /**
     * Ensure HTTP basic auth credentials for the marketplace are in auth.json.
     *
     * The marketplace signs a site in as "<site domain>:<license key>", and then
     * serves every package that buyer has licensed. A new key replaces the stored
     * one; without one the stored key is kept, so a later install or update does
     * not sign out what an earlier purchase signed in.
     */
    public function ensureAuthJsonConfigured(?string $licenseKey = null): void
    {
        $authJsonPath = $this->basePath . '/auth.json';
        $content = file_exists($authJsonPath)
            ? json_decode(file_get_contents($authJsonPath), true) ?? []
            : [];

        if (!isset($content['http-basic'])) {
            $content['http-basic'] = [];
        }

        $apiUrl    = $this->settings->getApiUrl();
        $host      = parse_url($apiUrl, PHP_URL_HOST) ?? 'marketplace.vela.build';
        $domain    = $this->settings->getDomain();
        $stored    = (string) ($content['http-basic'][$host]['password'] ?? '');
        $password  = $licenseKey ?: ($stored ?: (string) ($this->settings->getAuthToken() ?? ''));

        $content['http-basic'][$host] = [
            'username' => $domain,
            'password' => $password,
        ];

        $this->atomicWriteJson($authJsonPath, $content);
    }

    /**
     * Remove the marketplace entry from auth.json if no marketplace packages remain.
     */
    public function cleanupAuthJson(string $composerName): void
    {
        $this->validateComposerName($composerName);

        // Check if any marketplace packages still remain installed
        $remaining = \VelaBuild\Core\Models\InstalledPackage::withoutTrashed()
            ->where('composer_name', '!=', $composerName)
            ->count();

        if ($remaining > 0) {
            return;
        }

        $authJsonPath = $this->basePath . '/auth.json';
        if (!file_exists($authJsonPath)) {
            return;
        }

        $content = json_decode(file_get_contents($authJsonPath), true) ?? [];

        $apiUrl = $this->settings->getApiUrl();
        $host   = parse_url($apiUrl, PHP_URL_HOST) ?? 'marketplace.vela.build';

        if (isset($content['http-basic'][$host])) {
            unset($content['http-basic'][$host]);
            $this->atomicWriteJson($authJsonPath, $content);
        }
    }

    /**
     * Ensure auth.json is listed in .gitignore.
     */
    public function ensureGitignoreHasAuthJson(): void
    {
        $gitignorePath = $this->basePath . '/.gitignore';

        if (!file_exists($gitignorePath)) {
            file_put_contents($gitignorePath, "auth.json\n");
            return;
        }

        $contents = file_get_contents($gitignorePath);

        // Check if auth.json is already listed (on its own line)
        if (preg_match('/^auth\.json$/m', $contents)) {
            return;
        }

        file_put_contents($gitignorePath, $contents . "\nauth.json\n");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate a Composer package name to prevent command injection.
     *
     * @throws \InvalidArgumentException
     */
    private function validateComposerName(string $composerName): void
    {
        if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/', $composerName)) {
            throw new \InvalidArgumentException("Invalid Composer package name: {$composerName}");
        }
    }

    /**
     * Acquire a file-based lock (with up to 30-second timeout).
     */
    private function acquireLock(): bool
    {
        $dir = dirname($this->lockFile);

        // Both calls are silenced and their results checked, because the
        // failure they report is one this method already has an answer for:
        // it returns false and the caller says the install could not start.
        // Unsilenced, a directory that cannot be created raises a warning,
        // Laravel turns that into an ErrorException, and a lock that could
        // not be taken came back as a 500 rather than as a refusal.
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $handle = @fopen($this->lockFile, 'c');
        if ($handle === false) {
            return false;
        }

        $start = time();
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() - $start >= 30) {
                fclose($handle);
                return false;
            }
            usleep(500000); // 0.5 s
        }

        $this->lockHandle = $handle;
        return true;
    }

    /**
     * Release the file lock.
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== false) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = false;
        }
    }

    /**
     * Back up composer.json and composer.lock to the marketplace-backup directory.
     */
    private function backup(): void
    {
        $backupDir = storage_path('app/marketplace-backup');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('YmdHis');

        foreach (['composer.json', 'composer.lock'] as $file) {
            $src = $this->basePath . '/' . $file;
            if (file_exists($src)) {
                copy($src, $backupDir . '/' . $file . '.' . $timestamp);
            }
        }
    }

    /**
     * Restore the most recent backup of composer.json and composer.lock, then run
     * composer install to restore vendor state.
     */
    private function restore(): void
    {
        $backupDir = storage_path('app/marketplace-backup');

        foreach (['composer.json', 'composer.lock'] as $file) {
            $pattern = $backupDir . '/' . $file . '.*';
            $backups = glob($pattern);
            if (!empty($backups)) {
                // Sort descending to get the most recent backup
                rsort($backups);
                copy($backups[0], $this->basePath . '/' . $file);
            }
        }

        // Restore vendor state
        $process = new Process(
            ['composer', 'install', '--no-interaction', '--no-scripts'],
            $this->basePath,
            ['COMPOSER_MEMORY_LIMIT' => '-1'],
            null,
            300
        );
        $process->run();
    }

    /**
     * The Artisan steps a package change needs, which `--no-scripts` skips.
     *
     * Composer runs with --no-scripts so a package's own scripts never run
     * here; but that also skipped Laravel's package:discover. A plugin
     * installed from the admin was on disk yet never registered: its routes
     * answered 404 and its tables were never created.
     *
     * Public files (CSS, JS) are copied by vela:plugin-assets, not
     * `vendor:publish`: without --force a plugin update never reached the
     * site, and with it a file the site had changed was lost. It replaces
     * only files the site has not changed (see PluginAssetSync). Template
     * overrides are never copied; outdated ones are reported instead.
     *
     * @return array<int, array<int, string>>
     */
    public function afterComposerCommands(bool $migrate): array
    {
        return array_values(array_filter([
            ['package:discover'],
            $migrate ? ['migrate', '--force'] : null,
            ['vela:plugin-assets'],
            ['config:clear'],
            ['route:clear'],
            ['view:clear'],
        ]));
    }

    /**
     * Run them in a fresh PHP process: this one still has the old package
     * list loaded. A failing step is reported, not fatal — the package is
     * installed, and the admin can rerun the step.
     */
    protected function afterComposer(bool $migrate): string
    {
        $this->failedSteps = [];
        $php = $this->phpBinary();
        $log = '';
        foreach ($this->afterComposerCommands($migrate) as $args) {
            $process = new Process(
                array_merge([$php, 'artisan'], $args, ['--no-interaction']),
                $this->basePath,
                null,
                null,
                300
            );
            $process->run();
            $log .= "\n$ php artisan " . implode(' ', $args) . "\n" . $process->getOutput() . $process->getErrorOutput();
            if (!$process->isSuccessful()) {
                $this->failedSteps[] = implode(' ', $args);
                \Illuminate\Support\Facades\Log::warning('Marketplace post-install step failed', [
                    'command' => implode(' ', $args),
                    'output'  => trim($process->getErrorOutput() ?: $process->getOutput()),
                ]);
            }
        }
        return $log;
    }

    /**
     * The PHP command-line binary.
     *
     * Not PHP_BINARY: when the job runs after the response it is still the
     * web server's PHP, where PHP_BINARY is php-fpm (or the server itself),
     * which cannot run artisan. Every step after Composer failed, so a
     * plugin was installed but never discovered and its pages answered 404.
     */
    protected function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    /** @var array<int, string> steps after Composer that failed, from the last run */
    protected array $failedSteps = [];

    private function stepsWarning(): ?string
    {
        return $this->failedSteps
            ? 'Installed, but these setup steps failed: ' . implode(', ', $this->failedSteps) . '. Use Repair to run them again.'
            : null;
    }

    /**
     * Atomically write JSON to a file (write to .tmp, then rename).
     */
    private function atomicWriteJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $tmp  = $path . '.tmp';
        file_put_contents($tmp, $json);
        rename($tmp, $path);
    }
}
