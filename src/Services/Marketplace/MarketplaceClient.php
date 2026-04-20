<?php

namespace VelaBuild\Core\Services\Marketplace;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketplaceClient
{
    public function __construct(
        private MarketplaceSettingsService $settings
    ) {}

    /**
     * Fetch the plugin catalog from the marketplace API.
     */
    public function getCatalog(array $filters = []): array
    {
        $cacheKey = 'vela_marketplace_catalog_' . md5(serialize($filters));

        return Cache::remember($cacheKey, 3600, function () use ($filters) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'X-Marketplace-Domain' => $this->settings->getDomain(),
                    ])
                    ->withToken($this->settings->getAuthToken())
                    ->get($this->settings->getApiUrl() . '/api/catalog', $filters);

                if ($response->successful()) {
                    return $response->json() ?? [];
                }

                return [];
            } catch (ConnectionException $e) {
                return [];
            }
        });
    }

    /**
     * Fetch a single plugin by slug.
     */
    public function getPlugin(string $slug): ?array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Marketplace-Domain' => $this->settings->getDomain(),
                ])
                ->withToken($this->settings->getAuthToken())
                ->get($this->settings->getApiUrl() . '/api/catalog/' . $slug);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Validate a license key for a given domain.
     */
    public function validateLicense(string $licenseKey, string $domain): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Marketplace-Domain' => $this->settings->getDomain(),
                ])
                ->withToken($this->settings->getAuthToken())
                ->post($this->settings->getApiUrl() . '/api/licenses/validate', [
                    'license_key' => $licenseKey,
                    'domain'      => $domain,
                ]);

            if ($response->successful()) {
                return $response->json() ?? ['valid' => false];
            }

            return ['valid' => false];
        } catch (ConnectionException $e) {
            return ['valid' => false];
        }
    }

    /**
     * Exchange a short-lived checkout token for full license details.
     */
    public function exchangeToken(string $token): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Marketplace-Domain' => $this->settings->getDomain(),
                ])
                ->withToken($this->settings->getAuthToken())
                ->post($this->settings->getApiUrl() . '/api/licenses/exchange', [
                    'token' => $token,
                ]);

            if ($response->successful()) {
                return $response->json() ?? ['error' => 'Exchange failed'];
            }

            return ['error' => 'Exchange failed'];
        } catch (ConnectionException $e) {
            return ['error' => 'Exchange failed'];
        }
    }

    /**
     * Register this site for push update webhooks.
     */
    public function registerSite(string $licenseKey, string $domain, string $webhookUrl): bool
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Marketplace-Domain' => $this->settings->getDomain(),
                ])
                ->withToken($this->settings->getAuthToken())
                ->post($this->settings->getApiUrl() . '/api/sites/register', [
                    'license_key' => $licenseKey,
                    'domain'      => $domain,
                    'webhook_url' => $webhookUrl,
                ]);

            return $response->successful();
        } catch (ConnectionException $e) {
            return false;
        }
    }
}
