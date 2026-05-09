<?php

namespace VelaBuild\Core\Services;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Translation;

/**
 * Single translation engine used by admin buttons, CLI, and queued jobs.
 * Wraps AiProviderManager so installs can switch providers (OpenAI →
 * Anthropic → Gemini → vela_gateway) without changing translation code.
 *
 * Each translateX() method:
 *   - Loads source content
 *   - Calls the AI provider
 *   - Persists into the right storage (Translation table or new Page row)
 *   - Returns a structured result the caller surfaces in the UI / log
 *
 * Translations are idempotent — calling twice for the same (model, locale)
 * overwrites previous values so re-runs after fixing a prompt are safe.
 */
class Translator
{
    public function __construct(
        protected AiProviderManager $ai,
    ) {}

    /**
     * Translate one Content row's title/description/content to $locale.
     * Returns ['ok' => bool, 'fields' => string[], 'error' => ?string].
     * Existing translations are overwritten.
     */
    public function translateContent(Content $content, string $locale): array
    {
        $written = [];
        try {
            foreach (['title', 'description', 'content'] as $field) {
                $source = (string) ($content->{$field} ?? '');
                if ($source === '') continue;
                $translated = $this->translateText($source, $locale, $field);
                if ($translated === null) continue;

                Translation::updateOrCreate(
                    ['model_type' => 'Content', 'model_key' => $content->id . '_' . $field, 'lang_code' => $locale],
                    ['translation' => $translated],
                );
                $written[] = $field;
            }
            return ['ok' => true, 'fields' => $written, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => $written, 'error' => $e->getMessage()];
        }
    }

    /**
     * Translate one Category's name + description to $locale.
     */
    public function translateCategory(Category $category, string $locale): array
    {
        $written = [];
        try {
            foreach (['name', 'description'] as $field) {
                $source = (string) ($category->{$field} ?? '');
                if ($source === '') continue;
                $translated = $this->translateText($source, $locale, $field);
                if ($translated === null) continue;

                Translation::updateOrCreate(
                    ['model_type' => 'Category', 'model_key' => $category->id . '_' . $field, 'lang_code' => $locale],
                    ['translation' => $translated],
                );
                $written[] = $field;
            }
            return ['ok' => true, 'fields' => $written, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => $written, 'error' => $e->getMessage()];
        }
    }

    /**
     * Translate a Page row by creating (or updating) its sibling row in
     * $locale. Pages use a per-locale row model, so we duplicate rather
     * than write into the Translation table.
     *
     * Looks up the existing row by (parent_id IS NULL = self-parent, or
     * parent's siblings) — same conventions PageObserver uses for the
     * "/{locale}/{slug}" URL builder.
     */
    public function translatePage(Page $page, string $locale): array
    {
        try {
            // Already exists? Update. Identity = same slug+locale combo,
            // tracked by the original page's id stored in `parent_id`
            // when the locale row is created from translation.
            $sibling = Page::where('locale', $locale)
                ->where(function ($q) use ($page) {
                    $q->where('id', $page->id)
                      ->orWhere('parent_id', $page->id);
                })
                ->first();

            $newTitle = $this->translateText((string) $page->title, $locale, 'title');
            $newMetaTitle = $this->translateText((string) ($page->meta_title ?? ''), $locale, 'meta_title');
            $newMetaDesc = $this->translateText((string) ($page->meta_description ?? ''), $locale, 'meta_description');

            if ($sibling) {
                $sibling->update([
                    'title' => $newTitle ?? $sibling->title,
                    'meta_title' => $newMetaTitle,
                    'meta_description' => $newMetaDesc,
                ]);
            } else {
                Page::create([
                    'title' => $newTitle ?? $page->title,
                    'slug' => $page->slug, // share slug; locale prefix routes them
                    'locale' => $locale,
                    'status' => $page->status,
                    'meta_title' => $newMetaTitle,
                    'meta_description' => $newMetaDesc,
                    'parent_id' => $page->parent_id ?: $page->id,
                    'order_column' => $page->order_column,
                ]);
            }
            return ['ok' => true, 'fields' => ['title'], 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Fill missing keys in lang/{locale}/{file}.php from lang/en/{file}.php.
     * Only writes keys not already present, so admin overrides are
     * preserved. Returns ['ok' => bool, 'added' => int, 'error' => ?string].
     */
    public function translateLangFile(string $sourceFile, string $locale, string $sourceLocale = 'en'): array
    {
        try {
            if (! is_file($sourceFile)) {
                return ['ok' => false, 'added' => 0, 'error' => "Source file not found: {$sourceFile}"];
            }

            $rel = $this->relPath($sourceFile, $sourceLocale);
            $targetFile = $this->langTargetPath($sourceFile, $sourceLocale, $locale);

            $sourceArr = require $sourceFile;
            $targetArr = is_file($targetFile) ? (require $targetFile) : [];

            [$merged, $added] = $this->translateLangArray($sourceArr, $targetArr, $locale);
            if ($added === 0) {
                return ['ok' => true, 'added' => 0, 'error' => null];
            }

            @mkdir(dirname($targetFile), 0775, true);
            file_put_contents($targetFile, "<?php\n\nreturn " . var_export($merged, true) . ";\n");
            return ['ok' => true, 'added' => $added, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'added' => 0, 'error' => $e->getMessage()];
        }
    }

    /** Recursively translate keys missing in $target from $source. */
    protected function translateLangArray(array $source, array $target, string $locale, int $added = 0): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                $sub = is_array($target[$key] ?? null) ? $target[$key] : [];
                [$target[$key], $added] = $this->translateLangArray($value, $sub, $locale, $added);
                continue;
            }
            if (isset($target[$key]) && $target[$key] !== '') continue;
            if (! is_string($value) || $value === '') {
                $target[$key] = $value;
                continue;
            }
            $translated = $this->translateText($value, $locale, 'lang.' . $key);
            $target[$key] = $translated ?? $value;
            $added++;
        }
        return [$target, $added];
    }

    private function relPath(string $absSourceFile, string $sourceLocale): string
    {
        // Find /{$sourceLocale}/ segment and return the path after it.
        $needle = DIRECTORY_SEPARATOR . $sourceLocale . DIRECTORY_SEPARATOR;
        $pos = strrpos($absSourceFile, $needle);
        return $pos === false ? basename($absSourceFile) : substr($absSourceFile, $pos + strlen($needle));
    }

    private function langTargetPath(string $absSourceFile, string $sourceLocale, string $targetLocale): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $needle = $sep . $sourceLocale . $sep;
        $pos = strrpos($absSourceFile, $needle);
        if ($pos === false) {
            return dirname($absSourceFile) . $sep . $targetLocale . $sep . basename($absSourceFile);
        }
        return substr($absSourceFile, 0, $pos) . $sep . $targetLocale . $sep . substr($absSourceFile, $pos + strlen($needle));
    }

    /**
     * Single AI call. Field name is passed only to bias the prompt
     * (e.g. "translate this title" vs "translate this body") — no
     * special handling per field.
     */
    public function translateText(string $text, string $locale, string $fieldHint = ''): ?string
    {
        $text = trim($text);
        if ($text === '') return $text;

        $localeName = $this->localeName($locale);
        $hint = $fieldHint !== '' ? " (this is a {$fieldHint})" : '';

        $prompt = <<<P
Translate the following text into {$localeName}{$hint}.

Rules:
  - Preserve HTML tags, Blade directives (@if, @foreach, etc.), placeholder tokens (:name, {name}), and Markdown formatting exactly.
  - Translate the meaning naturally — don't transliterate.
  - Return ONLY the translation, no commentary, no quotes around the result.

Text:
{$text}
P;

        $provider = $this->ai->resolveTextProvider();
        $out = $provider->generateText($prompt, 2000, 0.3);
        return is_string($out) ? trim($out) : null;
    }

    /**
     * Display name for a locale ('th' → 'Thai'). Falls back to the locale
     * code if not in the supported-locales list — better than crashing.
     */
    protected function localeName(string $locale): string
    {
        try {
            $supported = LaravelLocalization::getSupportedLocales();
            if (isset($supported[$locale]['name'])) {
                return $supported[$locale]['name'];
            }
        } catch (\Throwable $e) {}
        return $locale;
    }
}
