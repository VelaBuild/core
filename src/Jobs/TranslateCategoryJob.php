<?php

namespace VelaBuild\Core\Jobs;

use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Services\OpenAiTextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $category;
    protected $targetLanguage;
    protected $openAiService;

    public function __construct(Category $category, string $targetLanguage)
    {
        $this->category = $category;
        $this->targetLanguage = $targetLanguage;
        $this->openAiService = new OpenAiTextService();
    }

    public function handle()
    {
        try {
            Log::info("Starting category translation for category ID: {$this->category->id} to language: {$this->targetLanguage}");

            // Translate category name
            $translatedName = $this->translateText($this->category->name, $this->targetLanguage);

            // Translate description if exists
            $translatedDescription = $this->category->description ?
                $this->translateText($this->category->description, $this->targetLanguage) : null;

            // Create or update translation record
            $this->createTranslationRecord($translatedName, $translatedDescription);

            Log::info("Successfully translated category ID: {$this->category->id} to language: {$this->targetLanguage}");

        } catch (\Exception $e) {
            Log::error("Failed to translate category ID: {$this->category->id} to language: {$this->targetLanguage}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function translateText(string $text, string $targetLanguage): string
    {
        if (empty($text)) {
            return $text;
        }

        $languageNames = [
            'en' => 'English',
            'th' => 'Thai',
            'zh-Hans' => 'Chinese (Simplified)',
            'ar' => 'Arabic',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'dk' => 'Danish',
        ];

        $targetLanguageName = $languageNames[$targetLanguage] ?? $targetLanguage;

        $prompt = "Translate the following text to {$targetLanguageName}.
        Maintain the same tone and style as the original.
        Return only the translated text without any additional formatting or explanations.

        Text to translate: {$text}";

        $response = $this->openAiService->generateText($prompt, 500, 0.3);

        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            throw new \Exception("Failed to get translation response from OpenAI");
        }

        return trim($response['choices'][0]['message']['content']);
    }

    protected function createTranslationRecord(string $name, ?string $description): void
    {
        // Create translation records for each field
        $this->createOrUpdateTranslation('name', $name);
        if ($description) {
            $this->createOrUpdateTranslation('description', $description);
        }
    }

    protected function createOrUpdateTranslation(string $field, string $translation): void
    {
        // Check if translation already exists
        $existingTranslation = Translation::where('model_type', 'Category')
            ->where('model_key', $this->category->id . '_' . $field)
            ->where('lang_code', $this->targetLanguage)
            ->first();

        if ($existingTranslation) {
            // Update existing translation
            $existingTranslation->update([
                'translation' => $translation,
                'updated_at' => now(),
            ]);
        } else {
            // Create new translation
            Translation::create([
                'model_type' => 'Category',
                'model_key' => $this->category->id . '_' . $field,
                'lang_code' => $this->targetLanguage,
                'translation' => $translation,
            ]);
        }
    }
}
