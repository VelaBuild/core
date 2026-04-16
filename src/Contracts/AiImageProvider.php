<?php
namespace VelaBuild\Core\Contracts;

interface AiImageProvider
{
    /**
     * Generate an image from a prompt.
     * @param array $options Provider-specific options (size, quality, aspect_ratio, etc.)
     * @return array|null Normalized response: ['data' => [['b64_json' => '...']]] or null on failure.
     */
    public function generateImage(string $prompt, array $options = []): ?array;

    /**
     * Save a base64-encoded image to storage.
     * @return string|null The saved file path relative to the disk, or null on failure.
     */
    public function saveBase64Image(string $base64Data, string $filename, string $disk = 'public'): ?string;
}
