<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\VelaConfig;
use Illuminate\Support\Facades\Crypt;

class ToolSettingsService
{
    private const PREFIX = 'tool_';

    private const ENV_MAP = [
        'ga_measurement_id'       => 'GA_MEASUREMENT_ID',
        'ga_property_id'          => 'GA_PROPERTY_ID',
        'ga_service_account_key'  => 'GA_SERVICE_ACCOUNT_KEY',
        'gsc_site_url'            => 'GSC_SITE_URL',
        'pagespeed_api_key'       => 'PAGESPEED_API_KEY',
        'cf_api_token'            => 'CF_API_TOKEN',
        'cf_zone_id'              => 'CF_ZONE_ID',
        'repostra_webhook_secret' => 'REPOSTRA_WEBHOOK_SECRET',
        'google_places_api_key'   => 'GOOGLE_PLACES_API_KEY',
        'google_place_id'         => 'GOOGLE_PLACE_ID',
    ];

    private const ENCRYPTED_KEYS = [
        'ga_service_account_key',
        'pagespeed_api_key',
        'cf_api_token',
        'repostra_webhook_secret',
        'google_places_api_key',
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
}
