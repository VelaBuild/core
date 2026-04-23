<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Contracts\AiImageProvider;

use VelaBuild\Core\Services\AiSettingsService;
class OpenAiImageService implements AiImageProvider
{
    private ?string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1/images/generations';

    public function __construct()
    {
        $this->apiKey = app(AiSettingsService::class)->getApiKey('openai');
    }

    /**
     * Generate an image via OpenAI's /v1/images/generations endpoint.
     *
     * Supported options:
     *   model   — 'gpt-image-1.5' (default) or 'gpt-image-2'. Pass-through, so
     *             snapshot pins like 'gpt-image-2-2026-04-21' also work.
     *   size    — '1024x1024' (default). gpt-image-2 also accepts any 16-pixel
     *             multiple up to 3840px with aspect ratio ≤3:1, or 'auto'.
     *   quality — 'low' | 'medium' | 'high' (default) | 'auto'.
     *   n       — number of images, 1–10.
     */
    public function generateImage(string $prompt, array $options = []): ?array
    {
        $model = $options['model'] ?? 'gpt-image-1.5';
        $size = $options['size'] ?? '1024x1024';
        $quality = $options['quality'] ?? 'high';
        $n = $options['n'] ?? 1;
        return $this->generateImageRaw($prompt, $size, $quality, $n, $model);
    }

    public function generateImageRaw(string $prompt, string $size = '1024x1024', string $quality = 'high', int $n = 1, string $model = 'gpt-image-1.5'): ?array
    {
        if (!$this->apiKey) {
            Log::warning('Vela: OpenAI API key not configured');
            return null;
        }

        try {
            $response = Http::timeout(120) // 2 minutes timeout
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => $n,
                    'size' => $size,
                    'quality' => $quality
                ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('OpenAI image generation successful', [
                    'prompt' => $prompt,
                    'model' => $model,
                    'size' => $size,
                    'quality' => $quality
                ]);
                return $data;
            } else {
                Log::error('OpenAI image generation failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'prompt' => $prompt,
                    'model' => $model,
                    'size' => $size,
                    'quality' => $quality
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('OpenAI image generation exception', [
                'message' => $e->getMessage(),
                'prompt' => $prompt,
                'model' => $model,
                'size' => $size,
                'quality' => $quality,
                'exception_type' => get_class($e)
            ]);
            return null;
        }
    }

    /**
     * Save a base64-encoded image to local storage
     *
     * @param string $base64Data The base64-encoded image data
     * @param string $filename The filename to save as
     * @param string $disk The storage disk to use
     * @return string|null Returns the saved file path or null on failure
     */
    public function saveBase64Image(string $base64Data, string $filename, string $disk = 'public'): ?string
    {
        try {
            $path = 'category-images/' . $filename;
            $imageData = base64_decode($base64Data);

            if ($imageData === false) {
                Log::error('Failed to decode base64 image data', ['filename' => $filename]);
                return null;
            }

            Storage::disk($disk)->put($path, $imageData);

            Log::info('Base64 image saved successfully', [
                'filename' => $filename,
                'path' => $path
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error('Base64 image save exception', [
                'message' => $e->getMessage(),
                'filename' => $filename
            ]);
            return null;
        }
    }

    /**
     * Download and save an image from URL to local storage
     *
     * @param string $imageUrl The URL of the image to download
     * @param string $filename The filename to save as
     * @param string $disk The storage disk to use
     * @return string|null Returns the saved file path or null on failure
     */
    public function downloadAndSaveImage(string $imageUrl, string $filename, string $disk = 'public'): ?string
    {
        try {
            $imageData = Http::get($imageUrl);

            if ($imageData->successful()) {
                $path = 'category-images/' . $filename;
                Storage::disk($disk)->put($path, $imageData->body());

                Log::info('Image downloaded and saved successfully', [
                    'url' => $imageUrl,
                    'path' => $path
                ]);

                return $path;
            } else {
                Log::error('Failed to download image', [
                    'url' => $imageUrl,
                    'status' => $imageData->status()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Image download exception', [
                'message' => $e->getMessage(),
                'url' => $imageUrl
            ]);
            return null;
        }
    }
}
