<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Models\AiActionLog;

class DownloadImageTool extends BaseTool
{
    private const MAX_SIZE = 10 * 1024 * 1024;
    private const TIMEOUT = 30;
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = $parameters['url'] ?? '';
        $filename = $parameters['filename'] ?? null;

        if (!$url) {
            return ['error' => 'url is required'];
        }

        $parts = parse_url($url);
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) {
            return ['error' => 'Only http(s) URLs allowed'];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withUserAgent('VelaBuild-AI-Helper/1.0')
                ->get($url);
        } catch (\Throwable $e) {
            return ['error' => 'Download failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['error' => 'HTTP ' . $response->status()];
        }

        $contentType = strtolower(explode(';', $response->header('content-type', ''))[0]);
        if (!in_array($contentType, self::ALLOWED_TYPES)) {
            return ['error' => "Not an image: {$contentType}"];
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_SIZE) {
            return ['error' => 'Image too large (max 10MB)'];
        }

        if (!$filename) {
            $pathParts = pathinfo(parse_url($url, PHP_URL_PATH) ?? '');
            $filename = ($pathParts['filename'] ?? 'downloaded') . '.' . ($pathParts['extension'] ?? 'jpg');
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename);
        $storagePath = 'public/ai-downloads/' . date('Y/m') . '/' . $filename;

        Storage::put($storagePath, $body);
        $publicUrl = Storage::url($storagePath);

        return [
            'success' => true,
            'url' => $publicUrl,
            'storage_path' => $storagePath,
            'filename' => $filename,
            'size' => strlen($body),
            'mime' => $contentType,
        ];
    }
}
