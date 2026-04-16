<?php

namespace VelaBuild\Core\Jobs;

use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Services\OpenAiTextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TranslateContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $content;
    protected $targetLanguage;
    protected $openAiService;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 1800; // 30 minutes

    public function __construct(Content $content, string $targetLanguage)
    {
        $this->content = $content;
        $this->targetLanguage = $targetLanguage;
        $this->openAiService = new OpenAiTextService();
    }

    public function handle()
    {
        try {
            // Check if content exists
            if (!$this->content) {
                Log::error("Content not found", ['content_id' => $this->content->id ?? 'null']);
                return;
            }

            // Translate and save title immediately
            $translatedTitle = $this->translateText($this->content->title, $this->targetLanguage);
            $this->createOrUpdateTranslation('title', $translatedTitle);

            // Translate and save description immediately
            $translatedDescription = $this->translateText($this->content->description, $this->targetLanguage);
            $this->createOrUpdateTranslation('description', $translatedDescription);

            // Translate content (EditorJS format)
            $translatedContent = $this->translateEditorJsContent($this->content->content, $this->targetLanguage);
            $this->createOrUpdateTranslation('content', $translatedContent);

        } catch (\Exception $e) {
            Log::error("=== TRANSLATION JOB FAILED ===", [
                'content_id' => $this->content->id ?? 'unknown',
                'target_language' => $this->targetLanguage,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString()
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
        Maintain the same tone and style as the original. Adapt to suit the target language where needed.
        Return only the translated text without any additional formatting or explanations.

        Text to translate: {$text}";

        $response = $this->openAiService->generateText($prompt, 500, 0.3);

        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            throw new \Exception("Failed to get translation response from OpenAI");
        }

        return trim($response['choices'][0]['message']['content']);
    }

    protected function translateTextWithContext(string $text, string $targetLanguage, string $context): string
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

        // Create context-specific prompts
        $contextPrompts = [
            'header' => "Translate this heading to {$targetLanguageName}. Keep it concise and impactful. Maintain the same formatting (numbers, punctuation).",
            'paragraph' => "Translate this paragraph to {$targetLanguageName}. Maintain the same tone and style as the original.",
            'list_item' => "Translate this list item to {$targetLanguageName}. Keep the same format and structure. If it starts with a number (like '1.' or '2.'), keep the number format.",
            'image_caption' => "Translate this image caption to {$targetLanguageName}. Keep it descriptive and SEO-friendly.",
        ];

        $basePrompt = $contextPrompts[$context] ?? "Translate the following text to {$targetLanguageName}. Maintain the same tone and style as the original.";

        $prompt = "{$basePrompt}

        Return only the translated text without any additional formatting or explanations.

        Text to translate: {$text}";

        $response = $this->openAiService->generateText($prompt, 500, 0.3);

        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            throw new \Exception("Failed to get translation response from OpenAI");
        }

        return trim($response['choices'][0]['message']['content']);
    }

    protected function translateEditorJsContent($content, string $targetLanguage): string
    {
        if (empty($content)) {
            return $content;
        }

        // Parse EditorJS content
        $contentData = is_string($content) ? json_decode($content, true) : $content;

        if (!isset($contentData['blocks']) || !is_array($contentData['blocks'])) {
            return $content;
        }

        $blocksCount = count($contentData['blocks']);

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
        $processedBlocks = 0;
        $apiCallsCount = 0;

        // Translate each block individually for better accuracy
        foreach ($contentData['blocks'] as $index => &$block) {
            $processedBlocks++;

            if (isset($block['data']['text'])) {
                $block['data']['text'] = $this->translateTextWithContext($block['data']['text'], $targetLanguage, $block['type'] ?? 'paragraph');
                $apiCallsCount++;
            }

            if (isset($block['data']['items']) && is_array($block['data']['items'])) {
                foreach ($block['data']['items'] as $itemIndex => &$item) {
                    $item = $this->translateTextWithContext($item, $targetLanguage, 'list_item');
                    $apiCallsCount++;
                }
            }

            // Translate image alt tags/captions
            if ($block['type'] === 'image' && isset($block['data']['caption'])) {
                $block['data']['caption'] = $this->translateTextWithContext($block['data']['caption'], $targetLanguage, 'image_caption');
                $apiCallsCount++;
            }
        }


        return json_encode($contentData);
    }

    protected function batchTranslateTexts(array $texts, string $targetLanguage): array
    {
        if (empty($texts)) {
            return [];
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

        // Create a single prompt with all texts
        $textsList = '';
        foreach ($texts as $index => $text) {
            $textsList .= "Text " . ($index + 1) . ": " . $text . "\n\n";
        }

        $prompt = "Translate the following texts to {$targetLanguageName}.
        Maintain the same tone and style as the original.
        Return the translations in the same order, separated by '---TRANSLATION---'.

        Texts to translate:
        {$textsList}";

        Log::info("Batch translation API call", [
            'content_id' => $this->content->id,
            'texts_count' => count($texts),
            'prompt_length' => strlen($prompt)
        ]);

        $response = $this->openAiService->generateText($prompt, 4000, 0.3);

        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            throw new \Exception("Failed to get batch translation response from OpenAI");
        }

        $translatedContent = trim($response['choices'][0]['message']['content']);

        // Split the response by the separator
        $translations = explode('---TRANSLATION---', $translatedContent);
        $translations = array_map('trim', $translations);

        // Ensure we have the same number of translations as texts
        if (count($translations) !== count($texts)) {
            Log::warning("Translation count mismatch", [
                'content_id' => $this->content->id,
                'expected_count' => count($texts),
                'actual_count' => count($translations)
            ]);

            // If we have fewer translations, pad with original texts
            while (count($translations) < count($texts)) {
                $translations[] = $texts[count($translations)];
            }
        }

        return array_slice($translations, 0, count($texts));
    }


    protected function createOrUpdateTranslation(string $field, string $translation): void
    {
        Log::info("=== DATABASE SAVE OPERATION STARTED ===", [
            'content_id' => $this->content->id,
            'field' => $field,
            'target_language' => $this->targetLanguage,
            'translation_length' => strlen($translation),
            'translation_preview' => substr($translation, 0, 100) . '...',
            'model_key' => $this->content->id . '_' . $field
        ]);

        try {
            // Check if translation already exists
            $existingTranslation = Translation::where('model_type', 'Content')
                ->where('model_key', $this->content->id . '_' . $field)
                ->where('lang_code', $this->targetLanguage)
                ->first();

            Log::info("Translation lookup completed", [
                'content_id' => $this->content->id,
                'field' => $field,
                'existing_translation_found' => $existingTranslation ? true : false,
                'existing_translation_id' => $existingTranslation ? $existingTranslation->id : null
            ]);

            if ($existingTranslation) {
                // Update existing translation
                Log::info("Updating existing translation", [
                    'content_id' => $this->content->id,
                    'field' => $field,
                    'translation_id' => $existingTranslation->id,
                    'old_translation_length' => strlen($existingTranslation->translation)
                ]);

                $updateResult = $existingTranslation->update([
                    'translation' => $translation,
                    'updated_at' => now(),
                ]);

                Log::info("Translation update completed", [
                    'content_id' => $this->content->id,
                    'field' => $field,
                    'translation_id' => $existingTranslation->id,
                    'update_success' => $updateResult,
                    'new_translation_length' => strlen($translation)
                ]);
            } else {
                // Create new translation
                Log::info("Creating new translation", [
                    'content_id' => $this->content->id,
                    'field' => $field,
                    'model_type' => 'Content',
                    'model_key' => $this->content->id . '_' . $field,
                    'lang_code' => $this->targetLanguage
                ]);

                $newTranslation = Translation::create([
                    'model_type' => 'Content',
                    'model_key' => $this->content->id . '_' . $field,
                    'lang_code' => $this->targetLanguage,
                    'translation' => $translation,
                ]);

                Log::info("New translation created successfully", [
                    'content_id' => $this->content->id,
                    'field' => $field,
                    'translation_id' => $newTranslation->id,
                    'translation_length' => strlen($translation)
                ]);
            }

            Log::info("=== DATABASE SAVE OPERATION COMPLETED ===", [
                'content_id' => $this->content->id,
                'field' => $field,
                'success' => true
            ]);

        } catch (\Exception $e) {
            Log::error("=== DATABASE SAVE OPERATION FAILED ===", [
                'content_id' => $this->content->id,
                'field' => $field,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
