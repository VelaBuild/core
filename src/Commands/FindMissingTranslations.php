<?php

namespace VelaBuild\Core\Commands;

use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Jobs\TranslateCategoryJob;
use VelaBuild\Core\Jobs\TranslateContentJob;
use Illuminate\Console\Command;

class FindMissingTranslations extends Command
{
    protected $signature = 'vela:find-translations
                            {--limit=0 : Number of content items to process}
                            {--language= : Specific language to translate to (optional)}
                            {--force : Force translation even if translation exists}';

    protected $description = 'Find content missing translations and queue them for translation';

    public function handle()
    {
        $limit = $this->option('limit');
        $targetLanguage = $this->option('language');
        $force = $this->option('force');

        $this->info("Finding content missing translations (limit: {$limit})...");

        // Get supported languages
        $supportedLanguages = ['th', 'zh-Hans', 'ar', 'de', 'fr', 'it', 'nl', 'ru', 'dk'];

        if ($targetLanguage) {
            if (!in_array($targetLanguage, $supportedLanguages)) {
                $this->error("Unsupported language: {$targetLanguage}");
                return 1;
            }
            $supportedLanguages = [$targetLanguage];
        }

        $processedCount = 0;
        $queuedJobs = 0;

        // Find content that needs translation
        $contents = Content::whereIn('status', ['scheduled', 'published']);
        if ($limit) {
            $contents->limit($limit);
        }
        $contents = $contents->get();

        if ($contents->isEmpty()) {
            $this->info('No content found that needs translation.');
            return 0;
        }

        foreach ($contents as $content) {
            $this->info("Processing content ID: {$content->id} - {$content->title}");

            foreach ($supportedLanguages as $language) {
                // Check if translation already exists (check for title translation as indicator)
                $existingTranslation = Translation::where('model_type', 'Content')
                    ->where('model_key', $content->id . '_title')
                    ->where('lang_code', $language)
                    ->whereNotNull('translation')
                    ->first();

                if ($existingTranslation && !$force) {
                    $this->line("  - Translation to {$language} already exists, skipping...");
                    continue;
                }

                // Queue translation job
                TranslateContentJob::dispatch($content, $language);
                $queuedJobs++;
                $this->line("  - Queued translation to {$language}");
            }

            $processedCount++;
        }

        // Also process categories
        $categories = Category::get();

        foreach ($categories as $category) {
            $this->info("Processing category ID: {$category->id} - {$category->name}");

            foreach ($supportedLanguages as $language) {
                // Check if category translation already exists (check for name translation as indicator)
                $existingTranslation = Translation::where('model_type', 'Category')
                    ->where('model_key', $category->id . '_name')
                    ->where('lang_code', $language)
                    ->first();

                if ($existingTranslation && !$force) {
                    $this->line("  - Category translation to {$language} already exists, skipping...");
                    continue;
                }

                // Queue category translation job
                TranslateCategoryJob::dispatch($category, $language);
                $queuedJobs++;
                $this->line("  - Queued category translation to {$language}");
            }
        }

        $this->info("Processed {$processedCount} content items and queued {$queuedJobs} translation jobs.");

        return 0;
    }
}
