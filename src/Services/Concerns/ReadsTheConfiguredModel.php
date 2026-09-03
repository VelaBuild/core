<?php

namespace VelaBuild\Core\Services\Concerns;

use VelaBuild\Core\Services\AiSettingsService;

/**
 * Which model this provider talks to, wherever the site has said so.
 *
 * Three places, in the order a site would expect: what is set in .env wins,
 * then what the owner chose in Settings → AI, then what this release of Vela
 * ships with. The first two both come from AiSettingsService, which is already
 * how API keys are resolved — so a model is configured the same way a key is,
 * and shows the same padlock when .env has fixed it.
 *
 * Before this, the model was config-only: the admin could see which provider a
 * site was using and never which model, which is the larger of the two
 * decisions.
 */
trait ReadsTheConfiguredModel
{
    private function configuredModel(string $provider): string
    {
        $chosen = trim((string) app(AiSettingsService::class)->get($provider . '_model', ''));

        return $chosen !== '' ? $chosen : self::shippedModel($provider);
    }

    /**
     * What this release of Vela uses when nobody has chosen.
     *
     * An empty value counts as no value. `AI_CHAT_OPENAI_MODEL=` with nothing
     * after it is a line people leave behind, and `env('X', 'gpt-4o')` hands
     * back the empty string rather than the default for it — which was sending
     * `"model": ""` to the provider and failing on the first call with an
     * error about the model, not about the empty line that caused it.
     */
    private static function shippedModel(string $provider): string
    {
        $shipped = trim((string) config('vela.ai.chat.' . $provider . '_model', ''));

        return $shipped !== '' ? $shipped : (AiSettingsService::FALLBACK_MODELS[$provider] ?? '');
    }
}
