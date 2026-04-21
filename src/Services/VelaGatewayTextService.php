<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 *   VELA_GATEWAY_MODEL   — default chat model (gpt-4o, claude-sonnet-4, gemini-2.5-flash...)
 *
 * When VELA_GATEWAY_URL is set, AiProviderManager treats this as the ONLY
 * available text provider — a hosted user cannot bypass the gateway.
 */
class VelaGatewayTextService implements AiTextProvider
{
    private string $baseUrl;
    private string $siteSlug;
    private string $secret;
    private string $defaultModel;

    public function __construct()
    {
        $settings = app(AiSettingsService::class);
        $this->baseUrl      = rtrim((string) $settings->get('vela_gateway_url', ''), '/');
        $this->siteSlug     = (string) $settings->get('vela_gateway_site', '');
        $this->secret       = (string) $settings->get('vela_gateway_secret', '');
        $this->defaultModel = (string) $settings->get('vela_gateway_model', 'gpt-4o');
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
            'messages'   => $messages,
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

    /**
     * POST a signed request to the gateway. Returns decoded JSON or null.
     */
    private function request(array $body): ?array
    {
        if ($this->baseUrl === '' || $this->siteSlug === '' || $this->secret === '') {
            Log::warning('Vela Gateway: not configured', [
                'has_url'    => $this->baseUrl !== '',
                'has_site'   => $this->siteSlug !== '',
                'has_secret' => $this->secret !== '',
            ]);
            return null;
        }

        $path = '/v1/chat/completions';
        $url  = $this->baseUrl . $path;
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $timestamp = (int) floor(microtime(true) * 1000);
        $nonce     = bin2hex(random_bytes(16));

        $stringToSign = implode("\n", [
            'POST',
            $path,
            (string) $timestamp,
            $nonce,
            hash('sha256', $payload),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->secret);

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type'     => 'application/json',
                    'X-Vela-Site'      => $this->siteSlug,
                    'X-Vela-Timestamp' => (string) $timestamp,
                    'X-Vela-Nonce'     => $nonce,
                    'X-Vela-Signature' => $signature,
                ])
                ->withBody($payload, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            Log::error('Vela Gateway request failed', ['message' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            Log::error('Vela Gateway non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }
}
