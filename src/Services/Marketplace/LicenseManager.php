<?php

namespace VelaBuild\Core\Services\Marketplace;

use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\PackageLicense;

class LicenseManager
{
    public function __construct(
        private readonly MarketplaceClient $client,
        private readonly LicenseCacheWriter $cacheWriter,
    ) {}

    /**
     * Validate all licenses against the marketplace API and rebuild the cache.
     */
    public function validateAll(): void
    {
        $licenses = PackageLicense::all();

        foreach ($licenses as $license) {
            $result = $this->client->validateLicense($license->license_key, $license->domain);

            $license->validation_status = isset($result['valid'])
                ? ($result['valid'] ? PackageLicense::VALIDATION_VALID : PackageLicense::VALIDATION_INVALID)
                : PackageLicense::VALIDATION_INVALID;

            if (isset($result['status'])) {
                $license->validation_status = $result['status'];
            }

            $license->last_validated_at = now();
            $license->save();
        }

        $this->cacheWriter->write();
    }

    /**
     * Check if the given license is valid for the given domain.
     */
    public function isLicenseValidForDomain(PackageLicense $license, string $currentDomain): bool
    {
        $domain = strtolower($currentDomain);

        if ($domain === strtolower((string) $license->domain)) {
            return true;
        }

        if ($license->dev_domain && $domain === strtolower($license->dev_domain)) {
            return true;
        }

        if ($domain === 'localhost') {
            return true;
        }

        if ($domain === '127.0.0.1') {
            return true;
        }

        if (str_ends_with($domain, '.test')) {
            return true;
        }

        if (str_ends_with($domain, '.local')) {
            return true;
        }

        return false;
    }

    /**
     * Check if the given license has expired.
     * Only yearly licenses can expire; free and onetime never expire.
     */
    public function isExpired(PackageLicense $license): bool
    {
        if ($license->type !== PackageLicense::TYPE_YEARLY) {
            return false;
        }

        if ($license->expires_at === null) {
            return false;
        }

        return $license->expires_at->isPast();
    }

    /**
     * Get the license status for the given Composer package name.
     * Reads from cache first, falls back to DB.
     *
     * @return string 'valid'|'expired'|'invalid'|'none'
     */
    public function getLicenseStatus(string $composerName): string
    {
        $cache = $this->cacheWriter->read();

        if (isset($cache[$composerName])) {
            $entry = $cache[$composerName];

            if ($entry['status'] === InstalledPackage::STATUS_SUSPENDED) {
                return 'invalid';
            }

            if (!($entry['valid'] ?? false)) {
                return 'invalid';
            }

            if (
                isset($entry['type']) && $entry['type'] === PackageLicense::TYPE_YEARLY
                && isset($entry['expires_at']) && $entry['expires_at'] !== null
                && \Carbon\Carbon::parse($entry['expires_at'])->isPast()
            ) {
                return 'expired';
            }

            return 'valid';
        }

        $package = InstalledPackage::where('composer_name', $composerName)->first();

        if (!$package) {
            return 'none';
        }

        $license = $package->license;

        if (!$license) {
            return 'none';
        }

        if ($package->status === InstalledPackage::STATUS_SUSPENDED) {
            return 'invalid';
        }

        if ($license->validation_status === PackageLicense::VALIDATION_INVALID) {
            return 'invalid';
        }

        if ($this->isExpired($license)) {
            return 'expired';
        }

        if ($license->validation_status === PackageLicense::VALIDATION_VALID) {
            return 'valid';
        }

        return 'invalid';
    }

    /**
     * Check if safe mode is active.
     * Safe mode disables marketplace plugin loading for troubleshooting.
     */
    public function isSafeMode(): bool
    {
        if (env('VELA_SAFE_MODE')) {
            return true;
        }

        return file_exists(storage_path('app/.vela-safe-mode'));
    }
}
