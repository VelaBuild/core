<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\VelaConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class McpSettingsService
{
    private const PREFIX = 'mcp_';

    private const ENV_MAP = [
        'enabled'  => 'MCP_ENABLED',
        'api_key'  => 'MCP_API_KEY',
    ];

    private const ENCRYPTED_KEYS = [
        'api_key',
    ];

    /**
     * Get a setting value. Env takes precedence over DB.
     */
    public function get(string $key, $default = null)
    {
        if (isset(self::ENV_MAP[$key])) {
            $envVal = env(self::ENV_MAP[$key]);
            if ($envVal !== null && $envVal !== '') {
                return $envVal;
            }
        }

        try {
            $record = VelaConfig::where('key', self::PREFIX . $key)->first();
        } catch (\Exception $e) {
            return $default;
        }

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
     * Whether the MCP server is enabled.
     */
    public function isEnabled(): bool
    {
        $val = $this->get('enabled', '0');
        return $val === '1' || $val === 'true' || $val === true;
    }

    /**
     * Whether an API key is configured (env or DB).
     */
    public function hasApiKey(): bool
    {
        $key = $this->get('api_key');
        return $key !== null && $key !== '';
    }

    /**
     * Validate a bearer token against the configured key.
     */
    public function validateToken(string $token): bool
    {
        $apiKey = $this->get('api_key') ?? '';

        if ($apiKey === '' || $token === '') {
            return false;
        }

        return hash_equals($apiKey, $token);
    }

    /**
     * Get masked version of the API key for display.
     */
    public function getMaskedKey(): ?string
    {
        $val = $this->get('api_key');
        if (!$val || strlen($val) < 8) {
            return null;
        }
        return str_repeat('*', strlen($val) - 4) . substr($val, -4);
    }

    /**
     * Generate a new random API key.
     */
    public function generateApiKey(): string
    {
        return 'vela_' . Str::random(40);
    }

    /**
     * Get status for the admin UI.
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'enabled_locked' => $this->isEnvLocked('enabled'),
            'has_api_key' => $this->hasApiKey(),
            'masked_key' => $this->getMaskedKey(),
            'api_key_locked' => $this->isEnvLocked('api_key'),
        ];
    }
}
