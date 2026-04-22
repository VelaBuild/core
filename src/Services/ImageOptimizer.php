<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;

class ImageOptimizer
{
    protected string $cachePath;
    protected string $signingKey;

    public function __construct()
    {
        $this->cachePath = config('vela.images.cache_path', storage_path('app/image-cache'));
        // Prefer an explicit VELA_IMAGE_SIGNING_KEY so signed URLs baked into the
        // static cache on dev still validate on a production server with a
        // different APP_KEY. Falls back to APP_KEY for single-env installs.
        $secret = config('vela.images.signing_key') ?: config('app.key');
        $this->signingKey = hash_hmac('sha256', 'vela-image-signing', $secret);
    }

    public function generateUrl(string $src, int $width, int $height = 0, string $mode = 'fit'): string
    {
        $config = $this->buildConfig($src, $width, $height, $mode);
        return route('vela.image.webp', ['config' => $config]);
    }

    public function generateResizeUrl(string $src, int $width, int $height = 0, string $mode = 'fit'): string
    {
        $config = $this->buildConfig($src, $width, $height, $mode);
        return route('vela.image.resize', ['config' => $config]);
    }

    protected function buildConfig(string $src, int $width, int $height, string $mode): string
    {
        $payload = json_encode(['s' => $src, 'w' => $width, 'h' => $height, 'm' => $mode]);
        $base64 = strtr(base64_encode($payload), '+/', '-_');
        $hmac = substr(hash_hmac('sha256', $base64, $this->signingKey), 0, 8);
        return $base64 . $hmac;
    }

    public function verifyAndDecode(string $config): ?array
    {
        if (strlen($config) <= 8) {
            return null;
        }

        $signature = substr($config, -8);
        $base64 = substr($config, 0, -8);

        $expectedHmac = substr(hash_hmac('sha256', $base64, $this->signingKey), 0, 8);
        if (!hash_equals($expectedHmac, $signature)) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($base64, '-_', '+/')), true);
        if (!is_array($payload)) {
            return null;
        }

        // Validate src doesn't contain path traversal
        if (isset($payload['s']) && str_contains($payload['s'], '..')) {
            return null;
        }

        // Validate dimensions within configured max
        $maxWidth = config('vela.images.max_width', 2000);
        $maxHeight = config('vela.images.max_height', 2000);
        $width = (int) ($payload['w'] ?? 0);
        $height = (int) ($payload['h'] ?? 0);

        if ($width > $maxWidth || $height > $maxHeight) {
            return null;
        }

        return $payload;
    }

    public function process(array $config, bool $webp = true): array
    {
        $width = (int) ($config['w'] ?? 0);
        $height = (int) ($config['h'] ?? 0);
        $mode = $config['m'] ?? 'fit';
        $src = $config['s'] ?? '';
        $quality = config('vela.images.quality', 85);

        // Resolve and validate source path
        $sourcePath = base_path($src);
        $realSource = realpath($sourcePath);

        if ($realSource === false) {
            return ['error' => 'not_found'];
        }

        // Validate source is within allowed paths
        $allowedPaths = config('vela.images.allowed_source_paths', ['storage/app/public', 'public']);
        $allowed = false;
        foreach ($allowedPaths as $allowedRelPath) {
            $allowedReal = realpath(base_path($allowedRelPath));
            if ($allowedReal !== false && strpos($realSource, $allowedReal . DIRECTORY_SEPARATOR) === 0) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return ['error' => 'not_allowed'];
        }

        // Determine output extension and mime type
        if ($webp && $this->supportsWebp()) {
            $ext = 'webp';
            $mime = 'image/webp';
        } else {
            $imageInfo = @getimagesize($realSource);
            $origMime = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';
            $ext = match ($origMime) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $mime = $origMime;
            $webp = false;
        }

        // Compute cache key — include source mtime so a changed source file
        // invalidates the cache instead of serving a stale optimized copy.
        $sourceMtime = @filemtime($realSource) ?: 0;
        $cacheKey = md5(json_encode($config) . ($webp ? 'webp' : 'orig') . $sourceMtime);
        $cachFile = $this->cachePath . '/' . $cacheKey . '.' . $ext;

        // Return cached file if it exists
        if (file_exists($cachFile)) {
            return ['path' => $cachFile, 'mime' => $mime];
        }

        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        // Use lock file to prevent thundering herd
        $lockFile = $this->cachePath . '/' . $cacheKey . '.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            return ['error' => 'lock_failed'];
        }

        flock($lock, LOCK_EX);

        try {
            // Double-check after acquiring lock
            if (file_exists($cachFile)) {
                return ['path' => $cachFile, 'mime' => $mime];
            }

            if (class_exists('Intervention\Image\ImageManager')) {
                $this->processWithIntervention($realSource, $cachFile, $width, $height, $mode, $webp, $quality);
            } else {
                $this->processWithGd($realSource, $cachFile, $width, $height, $mode, $webp, $quality);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
        }

        if (!file_exists($cachFile)) {
            return ['error' => 'processing_failed'];
        }

        return ['path' => $cachFile, 'mime' => $mime];
    }

    private function processWithIntervention(string $sourcePath, string $outPath, int $width, int $height, string $mode, bool $webp, int $quality): void
    {
        $manager = class_exists('Imagick')
            ? \Intervention\Image\ImageManager::imagick()
            : \Intervention\Image\ImageManager::gd();

        $image = $manager->read($sourcePath);

        if ($mode === 'crop') {
            if ($width > 0 && $height > 0) {
                $image->cover($width, $height);
            } elseif ($width > 0) {
                $image->scaleDown($width);
            } elseif ($height > 0) {
                $image->scaleDown(null, $height);
            }
        } else {
            if ($width > 0 && $height > 0) {
                $image->scaleDown($width, $height);
            } elseif ($width > 0) {
                $image->scaleDown($width);
            } elseif ($height > 0) {
                $image->scaleDown(null, $height);
            }
        }

        if ($webp) {
            $image->toWebp($quality)->save($outPath);
        } else {
            $image->encodeByMediaType()->save($outPath);
        }
    }

    private function processWithGd(string $sourcePath, string $outPath, int $width, int $height, string $mode, bool $webp, int $quality): void
    {
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) {
            return;
        }

        $origWidth = $imageInfo[0];
        $origHeight = $imageInfo[1];
        $origMime = $imageInfo['mime'];

        $src = match ($origMime) {
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => @imagecreatefromjpeg($sourcePath),
        };

        if (!$src) {
            return;
        }

        // Calculate target dimensions
        [$targetWidth, $targetHeight] = $this->calculateDimensions(
            $origWidth, $origHeight, $width, $height, $mode
        );

        if ($mode === 'crop' && $width > 0 && $height > 0) {
            $dst = imagecreatetruecolor($width, $height);
            $this->preserveTransparency($dst, $origMime);

            // Calculate crop source coordinates
            $scaleX = $width / $origWidth;
            $scaleY = $height / $origHeight;
            $scale = max($scaleX, $scaleY);
            $srcW = (int) ($width / $scale);
            $srcH = (int) ($height / $scale);
            $srcX = (int) (($origWidth - $srcW) / 2);
            $srcY = (int) (($origHeight - $srcH) / 2);

            imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $width, $height, $srcW, $srcH);
            $targetWidth = $width;
            $targetHeight = $height;
        } else {
            $dst = imagecreatetruecolor($targetWidth, $targetHeight);
            $this->preserveTransparency($dst, $origMime);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);
        }

        imagedestroy($src);

        if ($webp && $this->supportsWebp()) {
            imagewebp($dst, $outPath, $quality);
        } else {
            match ($origMime) {
                'image/png' => imagepng($dst, $outPath),
                'image/gif' => imagegif($dst, $outPath),
                default => imagejpeg($dst, $outPath, $quality),
            };
        }

        imagedestroy($dst);
    }

    private function calculateDimensions(int $origW, int $origH, int $targetW, int $targetH, string $mode): array
    {
        if ($targetW <= 0 && $targetH <= 0) {
            return [$origW, $origH];
        }

        if ($targetW <= 0) {
            $ratio = $targetH / $origH;
            return [(int) ($origW * $ratio), $targetH];
        }

        if ($targetH <= 0) {
            $ratio = $targetW / $origW;
            return [$targetW, (int) ($origH * $ratio)];
        }

        // Both specified — scale down keeping aspect ratio (fit mode)
        $ratioW = $targetW / $origW;
        $ratioH = $targetH / $origH;
        $ratio = min($ratioW, $ratioH);

        return [(int) ($origW * $ratio), (int) ($origH * $ratio)];
    }

    private function preserveTransparency($image, string $mime): void
    {
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
            imagealphablending($image, true);
        }
    }

    private function supportsWebp(): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }
        $info = gd_info();
        return !empty($info['WebP Support']);
    }
}
