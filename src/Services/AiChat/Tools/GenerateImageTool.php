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

        if ($error = $this->refuseLogo((string) $prompt)) {
            return $error;
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

    /**
     * Refuse to draw a mark that stands for somebody.
     *
     * Shown a design with a strip of customer logos across it, a build asked
     * for "Logo of Intel", "Logo of Amazon", "Logo of Slack", "Logo of IBM" —
     * and put the results on the page. The strip in a design is a placeholder
     * showing where a site's own partners go; reproduced, it is a set of
     * approximated trademarks announcing relationships that do not exist, on
     * somebody's live site. Wrong however good the drawing, and the drawings
     * were poor.
     *
     * A site's OWN logo is different, and is asked for as one — "a logo for
     * my bakery" — so the refusal names a person's own mark as the exception
     * rather than blocking it.
     */
    private function refuseLogo(string $prompt): ?array
    {
        $text = mb_strtolower($prompt);

        if (!preg_match('/\b(logo|wordmark|brand ?mark|trademark|emblem)\b/u', $text)) {
            return null;
        }

        // "a logo for my bakery", "our logo": the site's own, which it may have.
        if (preg_match('/\b(my|our|the site\'?s|this site\'?s)\b/u', $text)) {
            return null;
        }

        return [
            'error' => 'This looks like a request to draw a logo. A logo stands for somebody: drawn by a model it '
                . 'comes out as an approximation of a real company\'s trademark, and putting it on a site says that '
                . 'company is involved when it is not. A strip of logos in a design is a placeholder showing where '
                . 'the site\'s own customers or partners will go — set it out with their names as text and tell the '
                . 'owner to upload the real marks, or leave the strip out. To make a mark for THIS site, say so: '
                . '"a logo for my bakery, a wheat sheaf in one colour".',
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
