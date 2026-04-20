<?php

namespace VelaBuild\Core\Services\Marketplace;

use Illuminate\Support\Facades\File;
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
     * @return array{success: bool, output: string, error: string}
     */
    public function install(string $composerName): array
    {
        $this->validateComposerName($composerName);

        $lock = $this->acquireLock();
        if (!$lock) {
            return ['success' => false, 'output' => '', 'error' => 'Another package operation is in progress. Please try again.'];
        }

        try {
            $this->backup();
            $this->ensureRepositoryRegistered();
            $this->ensureAuthJsonConfigured();

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

            return ['success' => true, 'output' => $output, 'error' => ''];
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

            return ['success' => true, 'output' => $output, 'error' => ''];
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

            return ['success' => true, 'output' => $output, 'error' => ''];
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

        $apiUrl = $this->settings->getApiUrl();

        foreach ($content['repositories'] as $repo) {
            if (isset($repo['url']) && $repo['url'] === $apiUrl) {
                return;
            }
        }

        $content['repositories'][] = [
            'type' => 'composer',
            'url'  => $apiUrl,
        ];

        $this->atomicWriteJson($composerJsonPath, $content);
    }

    /**
     * Ensure HTTP basic auth credentials for the marketplace are in auth.json.
     */
    public function ensureAuthJsonConfigured(): void
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
        $authToken = $this->settings->getAuthToken() ?? '';

        $content['http-basic'][$host] = [
            'username' => $domain,
            'password' => $authToken,
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
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($this->lockFile, 'c');
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
