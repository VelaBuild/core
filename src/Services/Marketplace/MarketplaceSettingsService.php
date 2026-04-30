<?php

namespace VelaBuild\Core\Services\Marketplace;

use VelaBuild\Core\Models\VelaConfig;
use Illuminate\Support\Facades\Crypt;

class MarketplaceSettingsService
{
    private const PREFIX = 'marketplace_';

    /**
     * The marketplace URL is hard-coded — sites cannot point at a different
     * marketplace. Auth token + webhook secret remain per-site.
     */
    public const API_URL = 'https://marketplace.vela.build';

    private const ENV_MAP = [
        'auth_token'     => 'VELA_MARKETPLACE_TOKEN',
        'webhook_secret' => 'VELA_MARKETPLACE_WEBHOOK_SECRET',
    ];

    private const ENCRYPTED_KEYS = [
        'auth_token',
        'webhook_secret',
    ];

    /**
     * Get a setting value. Env takes precedence over DB.
     */
    public function get(string $key, $default = null)
    {
        // Env always wins
        if (isset(self::ENV_MAP[$key])) {
            $envVal = env(self::ENV_MAP[$key]);
            if ($envVal !== null && $envVal !== '') {
                return $envVal;
            }
        }

        // Fall back to DB
        $record = VelaConfig::where('key', self::PREFIX . $key)->first();
        if (!$record || $record->value === null || $record->value === '') {
            return $default;
        }

        if (in_array($key, self::ENCRYPTED_KEYS)) {
            try {
                return Crypt::decryptString($record->value);
            } catch (\Exception $e) {
                return $default;
            }
        }

        return $record->value;
    }

    /**
     * Save a setting to DB (encrypted if applicable).
     */
    public function set(string $key, ?string $value): void
    {
        $storeValue = $value;
        if (in_array($key, self::ENCRYPTED_KEYS) && $value !== null && $value !== '') {
            $storeValue = Crypt::encryptString($value);
        }

        VelaConfig::updateOrCreate(
            ['key' => self::PREFIX . $key],
            ['value' => $storeValue]
        );
    }

    /**
     * Check if a setting is locked by env.
     */
    public function isEnvLocked(string $key): bool
    {
        if (!isset(self::ENV_MAP[$key])) {
            return false;
        }
        $val = env(self::ENV_MAP[$key]);
        return $val !== null && $val !== '';
    }

    /**
     * Check if a key is configured (either env or DB).
     */
    public function hasKey(string $key): bool
    {
        $val = $this->get($key);
        return $val !== null && $val !== '';
    }

    /**
     * Get masked version for display (last 4 chars).
     */
    public function getMaskedValue(string $key): ?string
    {
        $val = $this->get($key);
        if (!$val || strlen($val) < 8) {
            return null;
        }
        return str_repeat('*', strlen($val) - 4) . substr($val, -4);
    }

    /**
     * Get the marketplace API URL. Hard-coded — there is one canonical
     * marketplace. Sites cannot override.
     */
    public function getApiUrl(): string
    {
        return self::API_URL;
    }

    /**
     * Get the marketplace auth token.
     */
    public function getAuthToken(): ?string
    {
        return $this->get('auth_token');
    }

    /**
     * Get the current site's domain from config.
     */
    public function getDomain(): string
    {
        return parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
    }

    /**
     * Marketplace is always reachable — the URL is hard-coded. Returns true
     * for callers that historically guarded UI on this. (Auth token presence
     * is a separate question; check hasKey('auth_token') for that.)
     */
    public function isConfigured(): bool
    {
        return true;
    }
}
