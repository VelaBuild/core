<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AiSettingsService;
use VelaBuild\Core\Services\GeminiImageService;

class GenerateThemeScreenshots extends Command
{
    protected $signature = 'vela:generate-theme-screenshots {--force : Overwrite existing screenshots}';
    protected $description = 'Generate preview screenshots for each theme using Gemini image generation';

    public function handle(): int
    {
        $aiSettings = app(AiSettingsService::class);
        if (! $aiSettings->hasApiKey('gemini')) {
            $this->error('No Gemini API key configured. Set GEMINI_API_KEY in .env');
            return 1;
        }

        $gemini = app(GeminiImageService::class);
        $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();

        $outputDir = dirname(__DIR__, 2) . '/public/screenshots';
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $descriptions = [
            'default' => 'A premium, feature-rich website theme with a large hero image section at the top, smooth animations, vibrant blue accent colors, a clean navigation bar, and card-based article layout below. Modern and professional design for a content-driven website.',
            'minimal' => 'A minimalist, clean website theme with lots of white space, simple typography, no hero image, a thin top navigation bar, and a straightforward single-column article list. Content-focused with very little decoration.',
            'corporate' => 'A bold corporate website theme with a dark navy header, strong geometric sections, professional typography, a call-to-action banner, and a grid of article cards with shadow effects. Business-oriented and authoritative.',
            'editorial' => 'A magazine-style editorial website theme with a large featured article at the top, multi-column layout below, serif typography for headings, and a sophisticated reading experience. Optimized for long-form content.',
            'modern' => 'A modern website theme with clean geometric shapes, vibrant gradient accent colors (purple to blue), rounded corners on cards, a sleek navigation bar, and a contemporary card grid layout. Fresh and creative design.',
            'dark' => 'A dark-mode website theme with a dark charcoal/black background, light text, subtle gray card backgrounds with slight borders, a dark navigation bar, and muted accent colors. Elegant and easy on the eyes.',
        ];

        $generated = 0;
        foreach ($templates as $slug => $template) {
            $outputPath = "{$outputDir}/{$slug}.png";

            if (file_exists($outputPath) && ! $this->option('force')) {
                $this->line("  Skipping {$slug} — already exists (use --force to overwrite)");
                continue;
            }

            $this->info("Generating screenshot for '{$slug}'...");

            $description = $descriptions[$slug] ?? $template['description'];
            $prompt = "Generate a realistic website screenshot preview image for a CMS theme called '{$template['label']}'. "
                . "The image should look like a browser screenshot of a homepage. "
                . "{$description} "
                . "Make it look like a real website screenshot, not an illustration. Show realistic web content with placeholder text and images. 16:9 aspect ratio.";

            $result = $gemini->generateImageRaw($prompt, '16:9');

            if (! $result || empty($result['data'][0]['b64_json'])) {
                $this->error("  Failed to generate image for '{$slug}'");
                continue;
            }

            $imageData = base64_decode($result['data'][0]['b64_json']);
            if ($imageData === false) {
                $this->error("  Failed to decode image data for '{$slug}'");
                continue;
            }

            $tmpPath = "{$outputPath}.tmp";
            file_put_contents($tmpPath, $imageData);
            rename($tmpPath, $outputPath);

            $this->info("  Saved {$outputPath}");
            $generated++;
        }

        // Publish to public directory
        if ($generated > 0) {
            $publicDir = public_path('vendor/vela/screenshots');
            if (! is_dir($publicDir)) {
                mkdir($publicDir, 0755, true);
            }
            foreach (glob("{$outputDir}/*.png") as $file) {
                copy($file, $publicDir . '/' . basename($file));
            }
            $this->info("Published {$generated} screenshot(s) to public/vendor/vela/screenshots/");
        }

        $this->info("Done. Generated {$generated} screenshot(s).");
        return 0;
    }
}
