<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

/**
 * Installs, updates or removes a marketplace package in the background.
 *
 * Composer can take minutes. Run inside the admin's request, a gateway
 * timeout cut it off mid-way while it carried on unseen, and the admin, met
 * with an error, tried again. Now the admin sees "Installing…" at once and
 * the Installed Packages page follows the job until it ends.
 *
 * The license key is never in the job payload: it is read from the
 * package's license record when the job runs.
 */
class RunPackageOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INSTALL = 'install';
    public const UPDATE = 'update';
    public const REMOVE = 'remove';

    private const LOG_LIMIT = 20000;

    /** Composer and the Artisan steps after it; never retried automatically. */
    public $timeout = 1200;
    public $tries = 1;

    public function __construct(
        public int $packageId,
        public string $operation,
        public ?string $restoreStatus = null,
    ) {}

    /**
     * Mark the package as busy and start the operation: on a queue worker
     * when there is one, otherwise after the response has been sent, so the
     * admin's request never waits on Composer either way.
     */
    public static function start(InstalledPackage $package, string $operation): void
    {
        $restore = $package->isBusy() || $package->status === InstalledPackage::STATUS_FAILED
            ? InstalledPackage::STATUS_ACTIVE
            : $package->status;

        $package->update([
            'status' => match ($operation) {
                self::INSTALL => InstalledPackage::STATUS_INSTALLING,
                self::UPDATE  => InstalledPackage::STATUS_UPDATING,
                self::REMOVE  => InstalledPackage::STATUS_REMOVING,
            },
            'operation_error' => null,
            'operation_started_at' => now(),
            'operation_finished_at' => null,
        ]);

        $job = new self($package->id, $operation, $restore);

        if (config('queue.default') === 'sync') {
            dispatch($job)->afterResponse();
        } else {
            dispatch($job);
        }
    }

    public function handle(
        PackageInstaller $installer,
        LicenseCacheWriter $cacheWriter,
        MarketplaceClient $client,
        MarketplaceSettingsService $settings,
    ): void {
        // When run after the response, this is still a web worker.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $package = InstalledPackage::with('license')->find($this->packageId);
        if (!$package) {
            return;
        }

        $result = match ($this->operation) {
            self::INSTALL => $installer->install($package->composer_name, $package->license?->license_key ?: null),
            self::UPDATE  => $installer->update($package->composer_name),
            self::REMOVE  => $installer->remove($package->composer_name),
        };

        $log = mb_substr((string) ($result['output'] ?? ''), -self::LOG_LIMIT);

        if (!$result['success']) {
            $this->finish($package, [
                // A failed install leaves nothing behind (the installer
                // restores composer.json/lock); a failed update or removal
                // leaves the previous, working package in place.
                'status' => $this->operation === self::INSTALL
                    ? InstalledPackage::STATUS_FAILED
                    : ($this->restoreStatus ?: InstalledPackage::STATUS_ACTIVE),
                'operation_error' => ucfirst($this->operation) . ' failed: ' . ($result['error'] ?: 'unknown error'),
                'operation_log' => $log,
            ]);
            $cacheWriter->write();
            return;
        }

        if ($this->operation === self::REMOVE) {
            $package->license?->delete();
            $package->delete();
            $cacheWriter->write();
            return;
        }

        $this->finish($package, [
            'status' => $this->operation === self::INSTALL
                ? InstalledPackage::STATUS_ACTIVE
                : ($this->restoreStatus ?: InstalledPackage::STATUS_ACTIVE),
            'installed_at' => $this->operation === self::INSTALL ? now() : $package->installed_at,
            'operation_log' => $log,
        ]);
        $cacheWriter->write();

        if ($this->operation === self::INSTALL) {
            $client->registerSite(
                (string) ($package->license?->license_key ?? ''),
                $settings->getDomain(),
                route('vela.webhook.marketplace')
            );
            $installer->ensureGitignoreHasAuthJson();
        }
    }

    /**
     * The worker died or timed out: never leave the package stuck as busy.
     */
    public function failed(?\Throwable $e): void
    {
        $package = InstalledPackage::find($this->packageId);
        if (!$package) {
            return;
        }

        Log::error('Marketplace package operation crashed', [
            'package' => $package->composer_name,
            'operation' => $this->operation,
            'error' => $e?->getMessage(),
        ]);

        $this->finish($package, [
            'status' => $this->operation === self::INSTALL
                ? InstalledPackage::STATUS_FAILED
                : ($this->restoreStatus ?: InstalledPackage::STATUS_ACTIVE),
            'operation_error' => ucfirst($this->operation) . ' stopped: ' . ($e?->getMessage() ?: 'the job did not finish'),
        ]);
    }

    private function finish(InstalledPackage $package, array $attributes): void
    {
        $package->update($attributes + ['operation_finished_at' => now()]);
    }
}
