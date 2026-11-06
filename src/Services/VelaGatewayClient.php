<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Signed HTTP client for the Vela AI Gateway — the one place that knows the
 * signing scheme, shared by the text, image, search and rendering paths.
 *
 *   X-Vela-Site, X-Vela-Timestamp (unix ms), X-Vela-Nonce (32 hex),
 *   X-Vela-Signature = hex(HMAC-SHA256(secret,
 *       METHOD \n PATH \n TIMESTAMP \n NONCE \n hex(SHA256(body))))
 *
 * Configuration (env wins over DB, as everywhere in AiSettingsService):
 *   VELA_GATEWAY_URL, VELA_GATEWAY_SITE, VELA_GATEWAY_SECRET.
 */
class VelaGatewayClient
{
    private string $baseUrl;
    private string $siteSlug;
    private string $secret;

    /** HTTP status of the last call (0 = not sent / transport error). */
    public int $lastStatus = 0;

    /** Gateway error code of the last failed call, e.g. insufficient_credits. */
    public ?string $lastError = null;

    public function __construct()
    {
        $settings = app(AiSettingsService::class);
        $this->baseUrl  = rtrim((string) $settings->get('vela_gateway_url', ''), '/');
        $this->siteSlug = (string) $settings->get('vela_gateway_site', '');
        $this->secret   = (string) $settings->get('vela_gateway_secret', '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->siteSlug !== '' && $this->secret !== '';
    }

    /**
     * POST a signed JSON body. Returns the decoded JSON on 2xx, null otherwise
     * (see $lastStatus / $lastError for why).
     */
    public function post(string $path, array $body, int $timeout = 120): ?array
    {
        $this->lastStatus = 0;
        $this->lastError = null;

        if (!$this->isConfigured()) {
            Log::warning('Vela Gateway: not configured', [
                'has_url'    => $this->baseUrl !== '',
                'has_site'   => $this->siteSlug !== '',
                'has_secret' => $this->secret !== '',
            ]);
            $this->lastError = 'not_configured';
            return null;
        }

        $path = '/' . ltrim($path, '/');
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($this->signatureHeaders('POST', $path, $payload))
                ->withBody($payload, 'application/json')
                ->post($this->baseUrl . $path);
        } catch (\Throwable $e) {
            Log::error('Vela Gateway request failed', ['path' => $path, 'message' => $e->getMessage()]);
            $this->lastError = 'transport_error';
            return null;
        }

        $this->lastStatus = $response->status();
        if (!$response->successful()) {
            $this->lastError = (string) ($response->json('error.code') ?? 'http_' . $response->status());
            Log::error('Vela Gateway non-2xx', ['path' => $path, 'status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    /** @return array<string, string> */
    public function signatureHeaders(string $method, string $path, string $payload): array
    {
        $timestamp = (string) (int) floor(microtime(true) * 1000);
        $nonce     = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $payload),
        ]), $this->secret);

        return [
            'Content-Type'     => 'application/json',
            'X-Vela-Site'      => $this->siteSlug,
            'X-Vela-Timestamp' => $timestamp,
            'X-Vela-Nonce'     => $nonce,
            'X-Vela-Signature' => $signature,
        ];
    }
}
