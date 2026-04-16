<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Contracts\AiImageProvider;

use VelaBuild\Core\Services\AiSettingsService;
class GeminiImageService implements AiImageProvider
{
    private ?string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent';

    public function __construct()
    {
        $this->apiKey = app(AiSettingsService::class)->getApiKey('gemini');
    }

    /**
     * Generate an image using Gemini's gemini-2.5-flash-image model
     *
     * @param string $prompt The text prompt for image generation
     * @param string $aspectRatio The aspect ratio of the image (e.g., "1:1", "16:9")
     * @return array|null Returns the generated image data or null on failure
     */
    public function generateImage(string $prompt, array $options = []): ?array
    {
        $aspectRatio = $options['aspect_ratio'] ?? '1:1';
        return $this->generateImageRaw($prompt, $aspectRatio);
    }

    public function generateImageRaw(string $prompt, string $aspectRatio = '1:1'): ?array
    {
        if (!$this->apiKey) {
            Log::warning('Vela: Gemini API key not configured');
            return null;
        }

        try {
            $response = Http::timeout(180) // 3 minutes timeout
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'imageConfig' => [
                            'aspectRatio' => $aspectRatio
                        ]
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();

                $b64Data = null;
                if (isset($data['candidates'][0]['content']['parts'])) {
                    foreach ($data['candidates'][0]['content']['parts'] as $part) {
                        if (isset($part['inlineData']['data'])) {
                            $b64Data = $part['inlineData']['data'];
                            break;
                        }
                    }
                }

                Log::info('Gemini image generation successful', [
                    'prompt' => $prompt,
                    'model' => 'gemini-2.5-flash-image',
                    'aspectRatio' => $aspectRatio
                ]);

                if (!$b64Data) {
                    Log::error('Gemini response missing image data parts');
                }

                // Return normalized format to match OpenAI's expected structure in the job
                return [
                    'data' => [
                        [
                            'b64_json' => $b64Data
                        ]
                    ]
                ];
            } else {
                Log::error('Gemini image generation failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'prompt' => $prompt,
                    'model' => 'gemini-2.5-flash-image'
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Gemini image generation exception', [
                'message' => $e->getMessage(),
                'prompt' => $prompt,
                'model' => 'gemini-2.5-flash-image',
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
}
