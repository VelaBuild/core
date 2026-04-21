<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Contracts\AiImageProvider;

class AiProviderManager
{
    private array $textProviderMap = [
        'vela_gateway' => VelaGatewayTextService::class,
        'openai' => OpenAiTextService::class,
        'anthropic' => ClaudeTextService::class,
        'gemini' => GeminiTextService::class,
    ];

    private array $imageProviderMap = [
        'gemini' => GeminiImageService::class,
        'openai' => OpenAiImageService::class,
    ];

    private function settings(): AiSettingsService
    {
        return app(AiSettingsService::class);
    }

    /**
     * True when the Vela AI Gateway is configured via env. In this mode, the
     * gateway is the ONLY available text provider — hosted sites cannot fall
     * back to direct provider keys.
     */
    private function gatewayLocked(): bool
    {
        return $this->settings()->isGatewayConfigured()
            && $this->settings()->isEnvLocked('vela_gateway_url');
    }

    public function resolveTextProvider(?string $provider = null): AiTextProvider
    {
        // Gateway lockdown: ignore all other providers if env is pointing at the gateway.
        if ($this->gatewayLocked()) {
            return app(VelaGatewayTextService::class);
        }

        if ($provider && $this->settings()->hasApiKey($provider) && isset($this->textProviderMap[$provider])) {
            return app($this->textProviderMap[$provider]);
        }

        $default = $this->settings()->get('chat_provider', 'auto');
        if ($default !== 'auto' && $this->settings()->hasApiKey($default) && isset($this->textProviderMap[$default])) {
            return app($this->textProviderMap[$default]);
        }

        foreach (['vela_gateway', 'openai', 'anthropic', 'gemini'] as $name) {
            if ($this->settings()->hasApiKey($name) && isset($this->textProviderMap[$name])) {
                return app($this->textProviderMap[$name]);
            }
        }

        throw new \RuntimeException('No AI text provider configured. Add an API key in AI Settings.');
    }

    public function resolveImageProvider(?string $provider = null): AiImageProvider
    {
        if ($provider && $this->settings()->hasApiKey($provider) && isset($this->imageProviderMap[$provider])) {
            return app($this->imageProviderMap[$provider]);
        }

        $default = $this->settings()->get('image_provider', 'auto');
        if ($default !== 'auto' && $this->settings()->hasApiKey($default) && isset($this->imageProviderMap[$default])) {
            return app($this->imageProviderMap[$default]);
        }

        foreach (['gemini', 'openai'] as $name) {
            if ($this->settings()->hasApiKey($name) && isset($this->imageProviderMap[$name])) {
                return app($this->imageProviderMap[$name]);
            }
        }

        throw new \RuntimeException('No AI image provider configured. Add an API key in AI Settings.');
    }

    public function hasTextProvider(): bool
    {
        foreach (array_keys($this->textProviderMap) as $name) {
            if ($this->settings()->hasApiKey($name)) {
                return true;
            }
        }
        return false;
    }

    public function hasImageProvider(): bool
    {
        foreach (array_keys($this->imageProviderMap) as $name) {
            if ($this->settings()->hasApiKey($name)) {
                return true;
            }
        }
        return false;
    }

    public function availableProviders(string $capability): array
    {
        $map = $capability === 'text' ? $this->textProviderMap : $this->imageProviderMap;
        return array_values(array_filter(array_keys($map), fn($name) => $this->settings()->hasApiKey($name)));
    }
}
