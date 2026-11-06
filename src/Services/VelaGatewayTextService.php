<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;

/**
 * Proxies text generation through the Vela AI Gateway.
 *
 * Unlike OpenAI/Claude/Gemini services, this one does NOT talk to a provider
 * directly — it signs a request and POSTs it to our gateway, which enforces
 * credits and forwards to the underlying provider using our keys.
 *
 * Configuration (env takes precedence, as per AiSettingsService pattern):
 *   VELA_GATEWAY_URL     — e.g. https://ai.vela.build   (no trailing slash)
 *   VELA_GATEWAY_SITE    — the site's slug (matches X-Vela-Site header)
 *   VELA_GATEWAY_SECRET  — the plaintext secret issued at site creation
 *   VELA_GATEWAY_MODEL   — chat model the owner picked (gpt-4o, claude-sonnet-4, gemini-2.5-flash...);
 *                          without it, config vela.ai.gateway.default_model (the plan's default)
 *
 * When VELA_GATEWAY_URL is set, AiProviderManager treats this as the ONLY
 * available text provider — a hosted user cannot bypass the gateway.
 * Signing lives in VelaGatewayClient.
 */
class VelaGatewayTextService implements AiTextProvider
{
    private string $defaultModel;

    public function __construct()
    {
        $this->defaultModel = (string) app(AiSettingsService::class)->get('vela_gateway_model', config('vela.ai.gateway.default_model') ?: 'gpt-4o-mini');
    }

    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        $response = $this->request([
            'model'       => $this->defaultModel,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ]);

        if (!$response) {
            return null;
        }

        return $response['choices'][0]['message']['content'] ?? null;
    }

    public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array
    {
        $body = [
            'model'      => $this->defaultModel,
            // The gateway speaks OpenAI's wire format, so Vela's unified image
            // block has to be translated here as well — sent as-is it is
            // dropped, and a screenshot the model was meant to look at never
            // arrives.
            'messages'   => $this->normalizeVisionMessages($messages),
            'max_tokens' => $maxTokens,
        ];
        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $response = $this->request($body);
        if (!$response) {
            return null;
        }

        // Normalise to the Vela-standard shape (same as OpenAiTextService).
        $message = $response['choices'][0]['message'] ?? [];
        $toolCalls = null;
        if (!empty($message['tool_calls'])) {
            $toolCalls = array_map(fn ($tc) => [
                'id'        => $tc['id'] ?? null,
                'name'      => $tc['function']['name'] ?? null,
                'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            ], $message['tool_calls']);
        }

        return [
            'content'    => $message['content'] ?? null,
            'tool_calls' => $toolCalls,
            'usage'      => [
                'input'  => $response['usage']['prompt_tokens']     ?? 0,
                'output' => $response['usage']['completion_tokens'] ?? 0,
            ],
        ];
    }

    public function supportsVision(): bool
    {
        // Whether vision is supported depends on the gateway-side model. Assume yes
        // for the default model family; the gateway will reject if not.
        return true;
    }

    /** Convert unified image blocks to the OpenAI image_url shape. */
    private function normalizeVisionMessages(array $messages): array
    {
        return array_map(function ($message) {
            if (!is_array($message['content'] ?? null)) {
                return $message;
            }

            $message['content'] = array_map(function ($block) {
                if (($block['type'] ?? '') === 'image') {
                    return [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => 'data:' . ($block['media_type'] ?? 'image/png') . ';base64,' . $block['source'],
                        ],
                    ];
                }
                return $block;
            }, $message['content']);

            return $message;
        }, $messages);
    }

    /**
     * POST a signed request to the gateway. Returns decoded JSON or null.
     */
    private function request(array $body): ?array
    {
        return app(VelaGatewayClient::class)->post('/v1/chat/completions', $body, 120);
    }
}
