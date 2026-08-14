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

    /** Ceiling on one batch — a page's worth of pictures, not a whole site. */
    private const MAX_BATCH = 20;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        // Copying a page means pulling every picture on it. One call per image
        // meant twenty tool calls for one page, and the model would give up
        // after three and leave the rest pointing at the other site's server.
        $urls = $parameters['urls'] ?? null;
        if (is_string($urls) && $urls !== '') {
            $decoded = json_decode($urls, true);
            $urls = is_array($decoded) ? $decoded : array_map('trim', explode(',', $urls));
        }
        if (is_array($urls) && $urls !== []) {
            return $this->downloadMany($urls);
        }

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

    /**
     * Download a set of images, reporting each outcome separately.
     *
     * One failure must not sink the batch: a hotlink-protected logo among
     * fifteen good photographs is a gap to fill, not a reason to leave the
     * page pointing at someone else's server.
     */
    private function downloadMany(array $urls): array
    {
        $saved = [];
        $failed = [];
        $seen = [];

        foreach ($urls as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            if (count($saved) + count($failed) >= self::MAX_BATCH) {
                $failed[] = ['url' => $url, 'error' => 'Skipped — batch limit of ' . self::MAX_BATCH . ' reached. Call again with the rest.'];
                continue;
            }

            $result = $this->execute(['url' => $url]);
            if (!empty($result['success'])) {
                $saved[] = ['source' => $url, 'url' => $result['url'], 'filename' => $result['filename']];
            } else {
                $failed[] = ['url' => $url, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }

        return array_filter([
            'success'      => $saved !== [],
            'saved'        => $saved,
            'saved_count'  => count($saved),
            'failed'       => $failed ?: null,
            'note'         => $failed !== []
                ? count($failed) . ' image(s) could not be downloaded — use the ones that saved and leave the rest out rather than linking to the source site.'
                : null,
        ], fn ($v) => $v !== null);
    }
}
