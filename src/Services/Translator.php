<?php

namespace VelaBuild\Core\Services;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Services\Translation\PageBodyTranslator;
use Illuminate\Support\Facades\DB;

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
    /** A menu item's own label (items without one show their page's or topic's translated title). */
    public function translateMenuItem(\VelaBuild\Core\Models\MenuItem $item, string $locale): array
    {
        $label = trim((string) $item->label);
        if ($label === '') {
            return ['ok' => true, 'fields' => [], 'error' => null];
        }
        try {
            $translated = $this->translateText($label, $locale, 'website menu link');
            if ($translated === null) {
                return ['ok' => false, 'fields' => [], 'error' => 'no translation returned'];
            }
            Translation::updateOrCreate(
                ['model_type' => 'MenuItem', 'model_key' => $item->id . '_label', 'lang_code' => $locale],
                ['translation' => $translated],
            );

            return ['ok' => true, 'fields' => ['label'], 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => [], 'error' => $e->getMessage()];
        }
    }

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
     * Translate a Page into $locale as its own row (pages are per-locale rows,
     * linked to the source by parent_id): title, meta, and every row and block
     * copied from the source with the prose translated.
     *
     * The source is the truth: an existing translation is rebuilt from it.
     * With $onlyIfStale, a translation newer than its source is left alone.
     * All AI work happens before anything is written, and the write is one
     * transaction, so a failure never leaves a blank page behind — visitors
     * then get the source page, which is better than nothing.
     */
    public function translatePage(Page $page, string $locale, bool $onlyIfStale = false): array
    {
        try {
            $page->loadMissing('rows.blocks');
            $sibling = Page::withTrashed()
                ->where('locale', $locale)
                ->where('id', '!=', $page->id)
                ->where('parent_id', $page->id)
                ->first();

            if ($onlyIfStale && $sibling && !$sibling->trashed() && $sibling->updated_at >= $page->updated_at) {
                return ['ok' => true, 'fields' => [], 'error' => null, 'skipped' => true];
            }

            $body = new PageBodyTranslator($this->ai->resolveTextProvider(), $this->localeName($locale));

            $newTitle = $this->translateText((string) $page->title, $locale, 'title');
            if ($newTitle === null || $newTitle === '') {
                throw new \RuntimeException('title translation failed');
            }
            $newMetaTitle = $page->meta_title ? $this->translateText((string) $page->meta_title, $locale, 'meta_title') : null;
            $newMetaDesc = $page->meta_description ? $this->translateText((string) $page->meta_description, $locale, 'meta_description') : null;

            $blocks = [];
            foreach ($page->rows as $row) {
                foreach ($row->blocks as $block) {
                    $blocks[] = ['type' => $block->type, 'content' => $block->content, 'settings' => $block->settings];
                }
            }
            $translated = $body->translateBlocks($blocks, $locale);
            // A mostly-English "translation" is worse than the fallback to the source.
            if ($body->total > 0 && $body->succeeded < 0.75 * $body->total) {
                throw new \RuntimeException("body translation failed ({$body->succeeded}/{$body->total} strings)");
            }

            DB::transaction(function () use ($page, $locale, &$sibling, $newTitle, $newMetaTitle, $newMetaDesc, $translated) {
                if ($sibling && $sibling->trashed()) {
                    $sibling->restore();
                }
                if (!$sibling) {
                    Page::releaseSlugFromTrash($page->slug, $locale);
                    $sibling = new Page(['slug' => $page->slug, 'locale' => $locale, 'parent_id' => $page->id]);
                }
                $sibling->fill([
                    'title'            => $newTitle,
                    'status'           => $page->status,
                    'meta_title'       => $newMetaTitle ?: null,
                    'meta_description' => $newMetaDesc ?: null,
                    'custom_css'       => $page->custom_css,
                    'custom_js'        => $page->custom_js,
                    'order_column'     => $page->order_column,
                ]);
                $sibling->save();
                $sibling->touch();

                foreach ($sibling->rows()->get() as $old) {
                    $old->blocks()->delete();
                    $old->delete();
                }
                $i = 0;
                foreach ($page->rows as $row) {
                    $newRow = $row->replicate(['page_id']);
                    $newRow->page_id = $sibling->id;
                    $newRow->save();
                    foreach ($row->blocks as $block) {
                        $newBlock = $block->replicate(['page_row_id']);
                        $newBlock->page_row_id = $newRow->id;
                        $newBlock->content = $translated[$i]['content'];
                        $newBlock->settings = $translated[$i]['settings'];
                        $newBlock->save();
                        $i++;
                    }
                }
            });

            return ['ok' => true, 'fields' => ['title', 'body'], 'error' => null, 'ai_calls' => $body->calls, 'strings' => $body->total, 'kept_source' => $body->total - $body->succeeded];
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
        $context = $this->siteContext();

        $prompt = <<<P
Translate the following text into {$localeName}{$hint}.{$context}

Rules:
  - Preserve HTML tags, Blade directives (@if, @foreach, etc.), placeholder tokens (:name, {name}), and Markdown formatting exactly.
  - Translate the meaning naturally — don't transliterate.
  - Do not use the em dash character; use a comma, colon or full stop instead, unless the target language's grammar requires a dash.
  - Return ONLY the translation, no commentary, no quotes around the result.

Text:
{$text}
P;

        $provider = $this->ai->resolveTextProvider();
        $out = $provider->generateText($prompt, 2000, 0.3);
        return is_string($out) ? trim($out) : null;
    }

    /**
     * What the site is, so a short label is read in context: on a coffee
     * roaster's site "Brewing Guides" came back as beer-brewing guides in
     * French, German and Thai.
     */
    protected function siteContext(): string
    {
        $name = trim((string) config('app.name'));
        $about = trim((string) (config('vela.site.description') ?: config('vela.site.tagline')));
        if ($name === '' && $about === '') {
            return '';
        }
        $site = $name !== '' ? "the website \"{$name}\"" : 'a website';

        return "\nIt is for {$site}" . ($about !== '' ? ": {$about}" : '') . '. Use the terms that business and its customers would use.';
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
