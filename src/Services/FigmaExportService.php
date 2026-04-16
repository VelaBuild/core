<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FigmaExportService
{
    public function parseFileKey(string $url): ?string
    {
        if (preg_match('#figma\.com/(?:file|design)/([a-zA-Z0-9]+)#', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function export(string $figmaUrl, string $outputDir): int
    {
        $token = config('vela.ai.figma.access_token') ?: env('FIGMA_ACCESS_TOKEN');
        $fileKey = $this->parseFileKey($figmaUrl);

        if (!$fileKey) {
            throw new \RuntimeException('Invalid Figma URL');
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Get file structure
        $response = Http::timeout(30)
            ->withHeaders(['X-Figma-Token' => $token])
            ->get("https://api.figma.com/v1/files/{$fileKey}");

        if (!$response->successful()) {
            throw new \RuntimeException('Figma API error: ' . $response->body());
        }

        $data = $response->json();

        // Extract top-level frame node IDs
        $nodeIds = [];
        $nodeNames = [];
        foreach ($data['document']['children'] ?? [] as $page) {
            foreach ($page['children'] ?? [] as $node) {
                if (($node['type'] ?? '') === 'FRAME') {
                    $nodeIds[] = $node['id'];
                    $nodeNames[$node['id']] = $node['name'] ?? $node['id'];
                }
            }
        }

        if (empty($nodeIds)) {
            Log::warning('No frames found in Figma file', ['file_key' => $fileKey]);
            return 0;
        }

        // Request image exports
        $ids = implode(',', $nodeIds);
        $imageResponse = Http::timeout(60)
            ->withHeaders(['X-Figma-Token' => $token])
            ->get("https://api.figma.com/v1/images/{$fileKey}?ids={$ids}&format=png&scale=2");

        if (!$imageResponse->successful()) {
            throw new \RuntimeException('Figma image export error: ' . $imageResponse->body());
        }

        $images = $imageResponse->json()['images'] ?? [];
        $count = 0;

        // Download each exported image
        foreach ($images as $nodeId => $imageUrl) {
            if (!$imageUrl) {
                continue;
            }
            try {
                $imageData = Http::timeout(60)->get($imageUrl)->body();
                $filename = Str::slug($nodeNames[$nodeId] ?? $nodeId) . '.png';
                file_put_contents($outputDir . '/' . $filename, $imageData);
                $count++;
            } catch (\Exception $e) {
                Log::warning('Failed to download Figma frame', ['node_id' => $nodeId, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
