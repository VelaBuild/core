<?php

namespace VelaBuild\Core\Commands;

use VelaBuild\Core\Contracts\AiImageProvider;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateMissingCategoryImages extends Command
{
    protected $signature = 'vela:generate-category-images
                            {--force : Force regeneration of all images}
                            {--size=1024x1024 : Image size (1024x1024, 1024x1792, or 1792x1024)}
                            {--quality=high : Image quality (high, medium, low)}
                            {--dry-run : Show what would be generated without actually generating}';

    protected $description = 'Generate missing category images using OpenAI gpt-image';

    private ?AiImageProvider $openAiService = null;

    public function handle()
    {
        $this->info('Starting category image generation...');

        // Keys may come from .env OR the admin AI Settings (DB). availableProviders()
        // checks both via AiSettingsService::hasApiKey().
        $aiManager = app(AiProviderManager::class);
        if (!in_array('openai', $aiManager->availableProviders('image'), true)) {
            $this->error('OpenAI API key is not configured. Set OPENAI_API_KEY in .env or add it under admin → Settings → AI.');
            return 1;
        }
        $this->openAiService = $aiManager->resolveImageProvider('openai');

        $force = $this->option('force');
        $size = $this->option('size');
        $quality = $this->option('quality');
        $dryRun = $this->option('dry-run');

        $validSizes = ['1024x1024', '1024x1792', '1792x1024'];
        if (!in_array($size, $validSizes)) {
            $this->error('Invalid size option. Must be one of: ' . implode(', ', $validSizes));
            return 1;
        }

        $validQualities = ['high', 'medium', 'low'];
        if (!in_array($quality, $validQualities)) {
            $this->error('Invalid quality option. Must be one of: ' . implode(', ', $validQualities));
            return 1;
        }

        $categories = Category::all();

        if ($categories->isEmpty()) {
            $this->warn('No categories found in the database.');
            return 0;
        }

        $this->info("Found {$categories->count()} categories");

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($categories as $category) {
            $this->line("Processing: {$category->name}");

            $hasImage = $category->getMedia('image')->isNotEmpty();

            if ($hasImage && !$force) {
                $this->line('  Image already exists, skipping...');
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  Would generate image for: {$category->name}");
                $processed++;
                continue;
            }

            $this->line('  Generating image...');

            try {
                $prompt = $this->generateCategoryPrompt($category->name, $category->icon ?? '', app(SiteContext::class));

                $imageData = $this->openAiService->generateImage($prompt, [
                    'size' => $size,
                    'quality' => $quality,
                ]);

                if (!$imageData || !isset($imageData['data'][0]['b64_json'])) {
                    $this->error("  Failed to generate image for: {$category->name}");
                    $errors++;
                    continue;
                }

                $base64Data = $imageData['data'][0]['b64_json'];
                $filename = strtolower(str_replace([' ', '&'], ['-', 'and'], $category->name)) . '.png';
                $savedPath = $this->openAiService->saveBase64Image($base64Data, $filename);

                if (!$savedPath) {
                    $this->error("  Failed to save image for: {$category->name}");
                    $errors++;
                    continue;
                }

                $fullPath = Storage::disk('public')->path($savedPath);
                $category->addMedia($fullPath)->toMediaCollection('image');

                $this->line("  Image generated and saved: {$savedPath}");
                $processed++;
            } catch (\Exception $e) {
                $this->error("  Error generating image for {$category->name}: " . $e->getMessage());
                $errors++;
            }

            sleep(1);
        }

        $this->newLine();
        $this->info('Generation Summary:');
        $this->line("  Processed: {$processed}");
        $this->line("  Skipped: {$skipped}");
        $this->line("  Errors: {$errors}");

        if ($errors > 0) {
            $this->warn('Some images failed to generate. Check the logs for details.');
            return 1;
        }

        $this->info('Category image generation completed successfully!');
        return 0;
    }

    private function generateCategoryPrompt(string $categoryName, string $icon, SiteContext $siteContext): string
    {
        $iconDescriptions = [
            'fas fa-map-marker-alt' => 'location, landmark, place of interest',
            'fas fa-mask' => 'equipment, gear, accessories',
            'fas fa-fish' => 'wildlife, nature, ecosystem',
            'fas fa-certificate' => 'certification, training, achievement',
            'fas fa-camera' => 'photography, visual media, creative content',
            'fas fa-plane' => 'travel, destination, adventure',
            'fas fa-heartbeat' => 'safety, health, wellness',
            'fas fa-water' => 'water activities, aquatic sports, outdoor recreation',
        ];

        $iconDescription = $iconDescriptions[$icon] ?? '';
        $niche = trim($siteContext->getNiche());
        $siteDescription = trim($siteContext->getSiteDescription());

        $parts = [];
        $parts[] = "A professional, modern photorealistic image representing the \"{$categoryName}\" category";
        if ($niche !== '' && stripos($niche, 'general') === false) {
            // Anchor the visual to the site's niche — without this, "Conservation"
            // on a dive site looks the same as "Conservation" on a forestry site.
            $parts[] = "for a {$niche} website";
        }
        if ($siteDescription !== '') {
            $parts[] = "Site context: {$siteDescription}";
        }
        if ($iconDescription !== '') {
            $parts[] = "Lean into the visual themes of {$iconDescription}";
        }
        $parts[] = 'High quality, clean modern feel, commercial use style. No text, no logos.';

        return implode('. ', $parts) . '.';
    }
}
