<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Contracts\AiImageProvider;

/**
 * Image generation through the Vela AI Gateway (POST /v1/images/generations).
 * Our provider keys stay in the gateway; the site's credits pay for it.
 *
 *   VELA_GATEWAY_IMAGE_MODEL — default gemini-2.5-flash-image (allowed on every
 *   plan); gpt-image-1.5 / gpt-image-2 on paid plans.
 *
 * When the gateway is configured via env, AiProviderManager uses this as the
 * ONLY image provider, exactly as for text.
 */
class VelaGatewayImageService implements AiImageProvider
{
    public const DEFAULT_MODEL = 'gemini-2.5-flash-image';

    private string $model;

    public function __construct(private ?VelaGatewayClient $client = null)
    {
        $this->client ??= app(VelaGatewayClient::class);
        $this->model = (string) app(AiSettingsService::class)->get('vela_gateway_image_model', self::DEFAULT_MODEL);
    }

    public function generateImage(string $prompt, array $options = []): ?array
    {
        $body = array_filter([
            'model'        => $options['model'] ?? $this->model,
            'prompt'       => $prompt,
            'n'            => 1,
            'size'         => $options['size'] ?? null,
            'quality'      => $options['quality'] ?? null,
            'aspect_ratio' => $options['aspect_ratio'] ?? null,
        ], fn ($v) => $v !== null);

        $response = $this->client->post('/v1/images/generations', $body, 180);
        if (!$response) {
            Log::warning('Vela Gateway image generation failed', [
                'status' => $this->client->lastStatus,
                'error'  => $this->client->lastError,
            ]);
            return null;
        }

        $first = $response['data'][0] ?? [];
        $b64 = $first['b64_json'] ?? null;
        if (!$b64 && !empty($first['url'])) {
            // URL-style answers are fetched here so callers always get base64.
            $download = Http::timeout(60)->get($first['url']);
            $b64 = $download->successful() ? base64_encode($download->body()) : null;
        }
        if (!$b64) {
            Log::error('Vela Gateway image response had no image data');
            return null;
        }

        return ['data' => [['b64_json' => $b64]]];
    }

    public function saveBase64Image(string $base64Data, string $filename, string $disk = 'public'): ?string
    {
        $imageData = base64_decode($base64Data, true);
        if ($imageData === false) {
            Log::error('Failed to decode base64 image data', ['filename' => $filename]);
            return null;
        }

        try {
            $path = 'category-images/' . $filename;
            Storage::disk($disk)->put($path, $imageData);
            return $path;
        } catch (\Throwable $e) {
            Log::error('Base64 image save exception', ['message' => $e->getMessage(), 'filename' => $filename]);
            return null;
        }
    }
}
