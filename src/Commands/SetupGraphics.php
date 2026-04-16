<?php

namespace VelaBuild\Core\Commands;

use VelaBuild\Core\Services\AiProviderManager;
use Illuminate\Console\Command;

class SetupGraphics extends Command
{
    protected $signature = 'vela:setup-graphics
                            {--force : Overwrite existing images}
                            {--only= : Generate only "logo" or "hero"}
                            {--dry-run : Show prompts without generating}';

    protected $description = 'Generate site logo and hero image using Gemini AI';

    private AiProviderManager $aiManager;

    public function __construct(AiProviderManager $aiManager)
    {
        parent::__construct();
        $this->aiManager = $aiManager;
    }

    public function handle(): int
    {
        if (!$this->aiManager->hasImageProvider()) {
            $this->error('No AI image provider configured. Please set GEMINI_API_KEY or OPENAI_API_KEY in your .env file.');
            return 1;
        }

        $imageService = $this->aiManager->resolveImageProvider();

        $imagesPath = public_path('images');
        if (!is_writable($imagesPath)) {
            $this->error("Directory is not writable: {$imagesPath}");
            return 1;
        }

        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $only = $this->option('only');

        if ($only && !in_array($only, ['logo', 'hero'])) {
            $this->error('Invalid --only value. Must be "logo" or "hero".');
            return 1;
        }

        $images = [
            'logo' => [
                'prompt' => 'A clean, minimal abstract icon suitable for a website logo. Simple geometric shape, modern silhouette style. No text, no words, no letters. Solid white icon on transparent or dark blue background. Simple, modern, professional. SVG-like quality.',
                'aspectRatio' => '1:1',
                'filename' => 'logo.png',
            ],
            'hero' => [
                'prompt' => 'Professional wide-angle photography of a beautiful landscape. Cinematic shot with natural lighting and vibrant colors. Photorealistic, high resolution, suitable as website hero background behind dark gradient overlay with white text. No text, no watermarks.',
                'aspectRatio' => '16:9',
                'filename' => 'hero.png',
            ],
        ];

        if ($only) {
            $images = [$only => $images[$only]];
        }

        $hasErrors = false;
        foreach ($images as $type => $config) {
            $this->info("Processing {$type} image...");

            $targetPath = public_path("images/{$config['filename']}");

            if ($dryRun) {
                $this->line("  Prompt: {$config['prompt']}");
                $this->line("  Aspect Ratio: {$config['aspectRatio']}");
                $this->info('  Dry run — no image generated.');
                continue;
            }

            if (file_exists($targetPath) && !$force) {
                $this->warn("  {$config['filename']} already exists. Use --force to overwrite.");
                continue;
            }

            if (file_exists($targetPath)) {
                $pathinfo = pathinfo($config['filename']);
                $backupPath = public_path("images/{$pathinfo['filename']}.backup.{$pathinfo['extension']}");
                copy($targetPath, $backupPath);
                $this->line("  Backed up existing file to {$pathinfo['filename']}.backup.{$pathinfo['extension']}");
            }

            if (!$this->generateGraphic($imageService, $type, $config['prompt'], $config['aspectRatio'], $config['filename'])) {
                $hasErrors = true;
            }
        }

        return $hasErrors ? 1 : 0;
    }

    private function generateGraphic($imageService, string $type, string $prompt, string $aspectRatio, string $filename): bool
    {
        $this->line('  Calling image API...');
        $response = $imageService->generateImage($prompt, ['aspect_ratio' => $aspectRatio]);

        if (!$response || !isset($response['data'][0]['b64_json']) || empty($response['data'][0]['b64_json'])) {
            $this->error("  Failed to generate {$type} image. Provider returned no image data.");
            return false;
        }

        $b64 = $response['data'][0]['b64_json'];
        $imageData = base64_decode($b64);

        if ($imageData === false || strlen($imageData) === 0) {
            $this->error("  Failed to decode {$type} image data.");
            return false;
        }

        $targetPath = public_path("images/{$filename}");
        $tmpPath = $targetPath . '.tmp';
        file_put_contents($tmpPath, $imageData);
        rename($tmpPath, $targetPath);

        $size = filesize($targetPath);
        $sizeFormatted = number_format($size / 1024, 1) . ' KB';
        $this->info("  {$type} image saved: images/{$filename} ({$sizeFormatted})");
        return true;
    }
}
