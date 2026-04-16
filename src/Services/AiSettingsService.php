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
    ];

    private const ENCRYPTED_KEYS = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
    ];

    /**
     * Get a setting value. Env takes precedence over DB.
     */
    public function get(string $key, $default = null)
    {
        // Map setting keys to env vars
        $envMap = [
            'openai_api_key' => 'OPENAI_API_KEY',
            'anthropic_api_key' => 'ANTHROPIC_API_KEY',
            'gemini_api_key' => 'GEMINI_API_KEY',
            'chat_provider' => 'AI_TEXT_PROVIDER',
            'image_provider' => 'AI_IMAGE_PROVIDER',
        ];

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
        $envMap = [
            'openai_api_key' => 'OPENAI_API_KEY',
            'anthropic_api_key' => 'ANTHROPIC_API_KEY',
            'gemini_api_key' => 'GEMINI_API_KEY',
            'chat_provider' => 'AI_TEXT_PROVIDER',
            'image_provider' => 'AI_IMAGE_PROVIDER',
        ];

        if (!isset($envMap[$key])) {
            return false;
        }

        $val = env($envMap[$key]);
        return $val !== null && $val !== '';
    }

    /**
     * Get the API key for a specific provider (env or DB).
     */
    public function getApiKey(string $provider): ?string
    {
        $keyMap = [
            'openai' => 'openai_api_key',
            'anthropic' => 'anthropic_api_key',
            'gemini' => 'gemini_api_key',
        ];

        return isset($keyMap[$provider]) ? $this->get($keyMap[$provider]) : null;
    }

    /**
     * Check if an API key is configured for a provider.
     */
    public function hasApiKey(string $provider): bool
    {
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
