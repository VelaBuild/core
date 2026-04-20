<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;

class PwaIconGenerator
{
    private const STANDARD_SIZES = [48, 72, 96, 128, 144, 192, 512];
    private const MASKABLE_SIZES = [192, 512];

    /**
     * Generate all PWA icon variants from a source image.
     *
     * @param string $sourcePath Absolute path to uploaded source image (512px+ square)
     * @return array{success: bool, generated: string[], errors: string[]}
     */
    public function generate(string $sourcePath): array
    {
        $generated = [];
        $errors = [];

        // Validate source exists
        if (!file_exists($sourcePath)) {
            return ['success' => false, 'generated' => [], 'errors' => ['Source image not found: ' . $sourcePath]];
        }

        // Validate dimensions
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) {
            return ['success' => false, 'generated' => [], 'errors' => ['Unable to read source image.']];
        }

        if ($imageInfo[0] < 512 || $imageInfo[1] < 512) {
            return ['success' => false, 'generated' => [], 'errors' => ['Source image must be at least 512x512 pixels.']];
        }

        // Clear old icons
        $this->clearIcons();

        // Ensure output directory exists
        $outputDir = $this->getOutputPath();
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Generate standard sizes
        foreach (self::STANDARD_SIZES as $size) {
            $outputPath = "{$outputDir}/icon-{$size}x{$size}.png";
            if ($this->generateIcon($sourcePath, $outputPath, $size)) {
                $generated[] = $outputPath;
            } else {
                $errors[] = "Failed to generate {$size}x{$size} icon.";
                Log::error('PWA icon generation failed', ['size' => $size, 'path' => $outputPath]);
            }
        }

        // Generate maskable sizes
        foreach (self::MASKABLE_SIZES as $size) {
            $outputPath = "{$outputDir}/icon-{$size}x{$size}-maskable.png";
            if ($this->generateMaskableIcon($sourcePath, $outputPath, $size)) {
                $generated[] = $outputPath;
            } else {
                $errors[] = "Failed to generate {$size}x{$size} maskable icon.";
                Log::error('PWA maskable icon generation failed', ['size' => $size, 'path' => $outputPath]);
            }
        }

        // Generate public/favicon.ico from the 48x48 icon. Browsers hit
        // /favicon.ico automatically — shipping an empty placeholder yields a
        // blank tab icon across the site.
        $faviconSource = "{$outputDir}/icon-48x48.png";
        $faviconTarget = public_path('favicon.ico');
        if (is_file($faviconSource) && @copy($faviconSource, $faviconTarget)) {
            @chmod($faviconTarget, 0664);
            $generated[] = $faviconTarget;
        }

        $success = empty($errors);

        if ($success) {
            Log::info('PWA icons generated successfully', ['count' => count($generated), 'source' => $sourcePath]);
        }

        return ['success' => $success, 'generated' => $generated, 'errors' => $errors];
    }

    /**
     * Generate a single icon at the given size using cover/crop mode.
     */
    private function generateIcon(string $sourcePath, string $outputPath, int $size): bool
    {
        try {
            if (class_exists('Intervention\Image\ImageManager')) {
                $manager = class_exists('Imagick')
                    ? \Intervention\Image\ImageManager::imagick()
                    : \Intervention\Image\ImageManager::gd();

                $image = $manager->read($sourcePath);
                $image->cover($size, $size);
                $image->toPng()->save($outputPath);

                return file_exists($outputPath);
            }

            // GD fallback
            $imageInfo = @getimagesize($sourcePath);
            if (!$imageInfo) {
                return false;
            }

            $src = @imagecreatefromstring(file_get_contents($sourcePath));
            if (!$src) {
                return false;
            }

            $origWidth = $imageInfo[0];
            $origHeight = $imageInfo[1];

            $dst = imagecreatetruecolor($size, $size);

            // Enable alpha for PNG
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
            imagealphablending($dst, true);

            // Cover crop: scale to fill, then center crop
            $scaleX = $size / $origWidth;
            $scaleY = $size / $origHeight;
            $scale = max($scaleX, $scaleY);
            $srcW = (int) ($size / $scale);
            $srcH = (int) ($size / $scale);
            $srcX = (int) (($origWidth - $srcW) / 2);
            $srcY = (int) (($origHeight - $srcH) / 2);

            imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $size, $size, $srcW, $srcH);
            imagedestroy($src);

            imagepng($dst, $outputPath);
            imagedestroy($dst);

            return file_exists($outputPath);
        } catch (\Throwable $e) {
            Log::error('PWA icon generation exception', ['size' => $size, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Generate a maskable icon with 10% safe-zone padding on each side.
     * The icon content occupies the inner 80% of the canvas.
     */
    private function generateMaskableIcon(string $sourcePath, string $outputPath, int $size, string $bgColor = '#ffffff'): bool
    {
        try {
            // Parse hex background color
            $hex = ltrim($bgColor, '#');
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            $innerSize = (int) round($size * 0.8);
            $offset = (int) round($size * 0.1);

            if (class_exists('Intervention\Image\ImageManager')) {
                $manager = class_exists('Imagick')
                    ? \Intervention\Image\ImageManager::imagick()
                    : \Intervention\Image\ImageManager::gd();

                // Create canvas with background color
                $canvas = $manager->create($size, $size, $bgColor);

                // Resize source image to inner size
                $icon = $manager->read($sourcePath);
                $icon->cover($innerSize, $innerSize);

                // Place centered on canvas
                $canvas->place($icon, 'top-left', $offset, $offset);
                $canvas->toPng()->save($outputPath);

                return file_exists($outputPath);
            }

            // GD fallback
            $src = @imagecreatefromstring(file_get_contents($sourcePath));
            if (!$src) {
                return false;
            }

            $origWidth = imagesx($src);
            $origHeight = imagesy($src);

            // Create canvas
            $dst = imagecreatetruecolor($size, $size);
            $bgFill = imagecolorallocate($dst, (int)$r, (int)$g, (int)$b);
            imagefill($dst, 0, 0, $bgFill);

            // Cover crop source to innerSize x innerSize
            $scaleX = $innerSize / $origWidth;
            $scaleY = $innerSize / $origHeight;
            $scale = max($scaleX, $scaleY);
            $srcW = (int) ($innerSize / $scale);
            $srcH = (int) ($innerSize / $scale);
            $srcX = (int) (($origWidth - $srcW) / 2);
            $srcY = (int) (($origHeight - $srcH) / 2);

            imagecopyresampled($dst, $src, $offset, $offset, $srcX, $srcY, $innerSize, $innerSize, $srcW, $srcH);
            imagedestroy($src);

            imagepng($dst, $outputPath);
            imagedestroy($dst);

            return file_exists($outputPath);
        } catch (\Throwable $e) {
            Log::error('PWA maskable icon generation exception', ['size' => $size, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Remove all generated icons from the output directory (keeps source.* files).
     */
    public function clearIcons(): void
    {
        $dir = $this->getOutputPath();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob("{$dir}/icon-*") as $file) {
            unlink($file);
        }
    }

    /**
     * Get the output directory path.
     */
    public function getOutputPath(): string
    {
        return storage_path('app/public/pwa-icons');
    }
}
