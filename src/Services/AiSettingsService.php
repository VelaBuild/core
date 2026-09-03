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
        'openai_image_model',
        'openai_model',
        'anthropic_model',
        'anthropic_workspace_id',
        'gemini_model',
        'vela_gateway_url',
        'vela_gateway_site',
        'vela_gateway_secret',
        'vela_gateway_model',
        'native_search',
    ];

    private const ENCRYPTED_KEYS = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
        'vela_gateway_secret',
    ];

    /**
     * What each provider falls back to when nothing anywhere names a model.
     *
     * Last resort only: .env, then the owner's choice, then config, then this.
     * It exists because an empty `AI_CHAT_OPENAI_MODEL=` line makes
     * env()'s own default unreachable, and a provider with no model sends
     * `"model": ""` and fails on its first call.
     */
    public const FALLBACK_MODELS = [
        'openai' => 'gpt-4o',
        'anthropic' => 'claude-sonnet-5',
        'gemini' => 'gemini-2.5-flash',
    ];

    /**
     * Model ids offered as suggestions, newest first.
     *
     * Suggestions, not a menu: the field takes anything, because a list baked
     * into a release stops being able to name the model that came out after
     * it — the mistake this file would otherwise repeat every year. Only ids
     * that have actually been seen to answer are listed.
     */
    public const MODEL_SUGGESTIONS = [
        'openai' => ['gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-4.1', 'gpt-4o'],
        'anthropic' => ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
        'gemini' => ['gemini-2.5-flash'],
    ];

    private const ENV_MAP = [
        'openai_api_key'      => 'OPENAI_API_KEY',
        'anthropic_api_key'   => 'ANTHROPIC_API_KEY',
        'gemini_api_key'      => 'GEMINI_API_KEY',
        'chat_provider'       => 'AI_TEXT_PROVIDER',
        'image_provider'      => 'AI_IMAGE_PROVIDER',
        'openai_image_model'  => 'OPENAI_IMAGE_MODEL',
        // The same names config/vela.php already reads, so a site that set
        // these in .env keeps working and the field shows as env-locked.
        'openai_model'        => 'AI_CHAT_OPENAI_MODEL',
        'anthropic_model'     => 'AI_CHAT_ANTHROPIC_MODEL',
        'anthropic_workspace_id' => 'ANTHROPIC_WORKSPACE_ID',
        'gemini_model'        => 'AI_CHAT_GEMINI_MODEL',
        'vela_gateway_url'    => 'VELA_GATEWAY_URL',
        'vela_gateway_site'   => 'VELA_GATEWAY_SITE',
        'vela_gateway_secret' => 'VELA_GATEWAY_SECRET',
        'vela_gateway_model'  => 'VELA_GATEWAY_MODEL',
        'native_search'       => 'AI_CHAT_NATIVE_SEARCH',
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
        $status['openai_image_model'] = $this->get('openai_image_model', 'gpt-image-1.5');
        $status['openai_image_model_locked'] = $this->isEnvLocked('openai_image_model');

        // The model each provider thinks with, as opposed to the one that
        // draws pictures. Left blank means "whatever this Vela ships with",
        // which is what the config default answers.
        foreach (['openai', 'anthropic', 'gemini'] as $p) {
            $status['providers'][$p]['model'] = (string) $this->get($p . '_model', '');
            $status['providers'][$p]['model_locked'] = $this->isEnvLocked($p . '_model');
            $shipped = trim((string) config('vela.ai.chat.' . $p . '_model', ''));
            $status['providers'][$p]['model_default'] = $shipped !== '' ? $shipped : (self::FALLBACK_MODELS[$p] ?? '');
            $status['providers'][$p]['model_suggestions'] = self::MODEL_SUGGESTIONS[$p] ?? [];
        }
        // Only Anthropic asks for this, and only for a key linked to an
        // identity rather than to a plain API key.
        $status['anthropic_workspace_id'] = (string) $this->get('anthropic_workspace_id', '');
        $status['anthropic_workspace_id_locked'] = $this->isEnvLocked('anthropic_workspace_id');
        $status['has_text_provider'] = $this->hasApiKey('openai') || $this->hasApiKey('anthropic') || $this->hasApiKey('gemini');
        $status['has_image_provider'] = $this->hasApiKey('openai') || $this->hasApiKey('gemini');

        // DB toggle overrides the static config default. Falls back to the
        // config value when nothing is explicitly stored.
        $stored = $this->get('native_search', null);
        $status['native_search'] = $stored === null
            ? (bool) config('vela.ai.chat.native_search', true)
            : ($stored === '1' || $stored === 1 || $stored === true);

        return $status;
    }
}
