<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\VelaConfig;
use Illuminate\Support\Facades\Crypt;

class AiSettingsService
{
    private const PREFIX = 'ai_';

    // All known setting keys
    private const KEYS = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
        'chat_provider',
        'image_provider',
        'vela_gateway_url',
        'vela_gateway_site',
        'vela_gateway_secret',
        'vela_gateway_model',
    ];

    private const ENCRYPTED_KEYS = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
        'vela_gateway_secret',
    ];

    private const ENV_MAP = [
        'openai_api_key'      => 'OPENAI_API_KEY',
        'anthropic_api_key'   => 'ANTHROPIC_API_KEY',
        'gemini_api_key'      => 'GEMINI_API_KEY',
        'chat_provider'       => 'AI_TEXT_PROVIDER',
        'image_provider'      => 'AI_IMAGE_PROVIDER',
        'vela_gateway_url'    => 'VELA_GATEWAY_URL',
        'vela_gateway_site'   => 'VELA_GATEWAY_SITE',
        'vela_gateway_secret' => 'VELA_GATEWAY_SECRET',
        'vela_gateway_model'  => 'VELA_GATEWAY_MODEL',
    ];

    /**
     * Get a setting value. Env takes precedence over DB.
     */
    public function get(string $key, $default = null)
    {
        $envMap = self::ENV_MAP;

        // Env always wins
        if (isset($envMap[$key])) {
            $envVal = env($envMap[$key]);
            if ($envVal !== null && $envVal !== '') {
                return $envVal;
            }
        }

        // Fall back to DB (gracefully handle missing table during install)
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
     * Save a setting to DB (encrypted if it's a key).
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
     * Check if a setting is locked by env (not user-configurable).
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
     * Get the API key for a specific provider (env or DB).
     * For 'vela_gateway', "has api key" means all three (url/site/secret) are present.
     */
    public function getApiKey(string $provider): ?string
    {
        $keyMap = [
            'openai' => 'openai_api_key',
            'anthropic' => 'anthropic_api_key',
            'gemini' => 'gemini_api_key',
            'vela_gateway' => 'vela_gateway_secret',
        ];

        return isset($keyMap[$provider]) ? $this->get($keyMap[$provider]) : null;
    }

    /**
     * True when all three pieces (url, site slug, secret) needed to call the gateway are set.
     */
    public function isGatewayConfigured(): bool
    {
        return (string) $this->get('vela_gateway_url', '')    !== ''
            && (string) $this->get('vela_gateway_site', '')   !== ''
            && (string) $this->get('vela_gateway_secret', '') !== '';
    }

    /**
     * Check if an API key is configured for a provider.
     * For 'vela_gateway', all three config pieces must be present.
     */
    public function hasApiKey(string $provider): bool
    {
        if ($provider === 'vela_gateway') {
            return $this->isGatewayConfigured();
        }

        $key = $this->getApiKey($provider);
        return $key !== null && $key !== '';
    }

    /**
     * Get masked version of a key for display (shows last 4 chars).
     */
    public function getMaskedKey(string $provider): ?string
    {
        $key = $this->getApiKey($provider);
        if (!$key || strlen($key) < 8) {
            return null;
        }
        return str_repeat('*', strlen($key) - 4) . substr($key, -4);
    }

    /**
     * Get the status of all AI settings for the admin UI.
     */
    public function getStatus(): array
    {
        $providers = ['openai', 'anthropic', 'gemini'];
        $status = [];

        foreach ($providers as $p) {
            $status['providers'][$p] = [
                'has_key' => $this->hasApiKey($p),
                'masked_key' => $this->getMaskedKey($p),
                'env_locked' => $this->isEnvLocked($p . '_api_key'),
            ];
        }

        $status['chat_provider'] = $this->get('chat_provider', 'auto');
        $status['chat_provider_locked'] = $this->isEnvLocked('chat_provider');
        $status['image_provider'] = $this->get('image_provider', 'auto');
        $status['image_provider_locked'] = $this->isEnvLocked('image_provider');
        $status['has_text_provider'] = $this->hasApiKey('openai') || $this->hasApiKey('anthropic') || $this->hasApiKey('gemini');
        $status['has_image_provider'] = $this->hasApiKey('openai') || $this->hasApiKey('gemini');

        return $status;
    }
}
