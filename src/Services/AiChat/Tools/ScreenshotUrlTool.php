<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\BrowserRenderingService;

class ScreenshotUrlTool extends BaseTool
{
    /**
     * Above this the picture is downscaled before it is handed to the model.
     * Kept deliberately low: an attached image is re-sent on every following
     * turn of the tool loop, and at a few hundred KB each it would eat the
     * input budget and push the actual conversation out of the window.
     */
    private const MAX_ATTACH_BYTES = 300_000;

    /** Past this, even the downscaled picture is not worth the context. */
    private const HARD_ATTACH_LIMIT = 700_000;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = $parameters['url'] ?? '';
        if (!$url) {
            return ['error' => 'url is required'];
        }

        $renderer = app(BrowserRenderingService::class);
        if (!$renderer->isConfigured()) {
            return ['error' => 'Browser rendering not configured. Set CLOUDFLARE_BROWSER_RENDERING_URL in .env.'];
        }

        $width = $parameters['width'] ?? 1280;
        $height = $parameters['height'] ?? 800;
        $fullPage = $parameters['full_page'] ?? false;

        $base64 = $renderer->screenshot($url, [
            'width' => $width,
            'height' => $height,
            'full_page' => $fullPage,
        ]);

        if (!$base64) {
            return ['error' => 'Screenshot capture failed'];
        }

        $filename = 'screenshot-' . date('Ymd-His') . '.png';
        $storagePath = 'public/ai-screenshots/' . $filename;
        \Illuminate\Support\Facades\Storage::put($storagePath, base64_decode($base64));

        return [
            'success' => true,
            'url' => \Illuminate\Support\Facades\Storage::url($storagePath),
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
            // The picture itself travels back to the model alongside this
            // result; the job strips this key out and appends it as an image.
            '_images' => self::attachable($base64, 'Screenshot of ' . $url),
        ];
    }

    /**
     * Package a captured PNG so the chat job can show it to the model.
     *
     * @return array<int, array{base64:string, media_type:string, label:string}>
     */
    public static function attachable(string $base64, string $label): array
    {
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            return [];
        }

        if (strlen($binary) > self::MAX_ATTACH_BYTES) {
            $reduced = self::downscale($binary);
            // Better to send the outline alone than to blow the context
            // window on a picture the request can no longer carry.
            if ($reduced === null || strlen($reduced) > self::HARD_ATTACH_LIMIT) {
                return [];
            }
            return [['base64' => base64_encode($reduced), 'media_type' => 'image/jpeg', 'label' => $label]];
        }

        return [['base64' => $base64, 'media_type' => 'image/png', 'label' => $label]];
    }

    /** Shrink an oversized capture to a width the model can still read. */
    private static function downscale(string $binary, int $targetWidth = 900): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > $targetWidth) {
            $scaled = imagescale($image, $targetWidth);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
                $height = imagesy($image);
            }
        }

        // A tall full-page capture stays huge even at a readable width, so cap
        // the height too — the top of the page is the part being copied.
        $maxHeight = 4000;
        if ($height > $maxHeight) {
            $cropped = imagecrop($image, ['x' => 0, 'y' => 0, 'width' => imagesx($image), 'height' => $maxHeight]);
            if ($cropped !== false) {
                imagedestroy($image);
                $image = $cropped;
            }
        }

        ob_start();
        imagejpeg($image, null, 70);
        $out = (string) ob_get_clean();
        imagedestroy($image);

        return $out !== '' ? $out : null;
    }
}
