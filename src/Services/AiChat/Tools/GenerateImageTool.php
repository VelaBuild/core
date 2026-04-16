<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\AiProviderManager;

class GenerateImageTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $prompt = $parameters['prompt'] ?? null;

        if (!$prompt) {
            return ['error' => 'prompt parameter is required'];
        }

        $aiManager = app(AiProviderManager::class);

        if (!$aiManager->hasImageProvider()) {
            return ['error' => 'No image provider configured.'];
        }

        $imageService = $aiManager->resolveImageProvider();

        $response = $imageService->generateImage($prompt, [
            'aspect_ratio' => '1:1',
            'size'         => '1024x1024',
            'quality'      => 'high',
        ]);

        if (!$response || !isset($response['data'][0]['b64_json']) || empty($response['data'][0]['b64_json'])) {
            return ['error' => 'Failed to generate image.'];
        }

        $imageData = base64_decode($response['data'][0]['b64_json']);
        if ($imageData === false) {
            return ['error' => 'Failed to decode image data.'];
        }

        $timestamp = now()->format('Y-m-d-H-i-s');
        $filename = "ai-generated-{$timestamp}.png";
        $relativePath = "images/{$filename}";
        $fullPath = public_path($relativePath);

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpPath = $fullPath . '.tmp';
        file_put_contents($tmpPath, $imageData);
        rename($tmpPath, $fullPath);

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => ['file_path' => $fullPath],
            ]);
        }

        return [
            'success' => true,
            'path'    => $fullPath,
            'url'     => asset($relativePath),
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state || !isset($state['file_path'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        if (file_exists($state['file_path'])) {
            unlink($state['file_path']);
        }
    }
}
