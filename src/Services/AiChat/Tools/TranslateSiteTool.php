<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Services\TranslationStatusService;
use VelaBuild\Core\Services\Translator;

/**
 * Put the site into another language.
 *
 * Without this, the only way the chatbot could answer "make my site available
 * in Thai" was to write Thai over the English source — destroying the original
 * rather than translating it. The machinery it needs already exists (the
 * Translator service behind /admin/translations); this is the door into it.
 *
 * Articles and categories keep one source row with per-locale overrides in
 * vela_translations; pages instead get a sibling row per locale. Translator
 * knows the difference, so this tool never writes either store directly — it
 * only chooses the work and records enough to undo it.
 */
class TranslateSiteTool extends BaseTool
{
    /**
     * Lang files are deliberately absent. Vela ships every interface string
     * for the locales it offers, and filling gaps there writes PHP files to
     * disk — /admin/translations is the place for that, not a chat command.
     */
    private const SURFACES = ['pages', 'articles', 'categories'];

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $status = app(TranslationStatusService::class);
        $source = $status->sourceLocale();
        $targets = $status->targetLocales($source);

        $locale = trim((string) ($parameters['locale'] ?? ''));
        if ($locale === '') {
            return [
                'error' => 'Which language should the site be translated into? Name one of the languages this site is set up for.',
                'available_locales' => $targets,
                'site_is_written_in' => $source,
            ];
        }

        if ($locale === $source) {
            return [
                'error' => "The site is already written in '{$source}' — that is the source language, not a translation. "
                    . 'Pick a different language, or tell the user the site would need a new language enabled first.',
                'available_locales' => $targets,
            ];
        }

        if (!in_array($locale, $targets, true)) {
            return [
                'error' => "This site is not set up for '{$locale}', so a translation into it would never be shown to visitors. "
                    . 'Tell the user which languages are available, or that an administrator has to enable the one they want first.',
                'available_locales' => $targets,
            ];
        }

        $surfaces = self::SURFACES;
        if (!empty($parameters['surface'])) {
            $surface = (string) $parameters['surface'];
            if (!in_array($surface, self::SURFACES, true)) {
                return [
                    'error' => "'{$surface}' is not something this tool translates.",
                    'valid_surfaces' => self::SURFACES,
                    'note' => 'Interface wording (buttons, labels) ships translated already; gaps there are fixed in the admin under Translations.',
                ];
            }
            $surfaces = [$surface];
        }

        $id = $parameters['id'] ?? null;
        if ($id !== null && empty($parameters['surface'])) {
            return ['error' => "Passing 'id' also needs 'surface', so the tool knows whether the id is a page, an article or a category."];
        }

        $limit = (int) ($parameters['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $work = $this->collectWork($status, $surfaces, $locale, $id, $limit);

        if ($work === []) {
            return [
                'success' => true,
                'translated' => 0,
                'message' => $id !== null
                    ? 'That item could not be found.'
                    : "Everything is already translated into '{$locale}' — nothing left to do.",
                'coverage' => $this->coverageFor($status, $locale),
            ];
        }

        $translator = app(Translator::class);
        $done = [];
        $failed = [];
        $undo = [];

        foreach ($work as $item) {
            // Snapshot before the write: a partly-translated item can already
            // hold wording someone chose by hand, and Translator overwrites.
            $before = $this->snapshot($item['surface'], $item['id'], $locale);

            $result = $this->translateOne($translator, $item['surface'], $item['id'], $locale);

            if (!empty($result['ok'])) {
                $done[] = ['surface' => $item['surface'], 'id' => $item['id'], 'label' => $item['label']];
                $undo[] = $before;
                continue;
            }

            $failed[] = [
                'surface' => $item['surface'],
                'label'   => $item['label'],
                'reason'  => $result['error'] ?: 'the translation service returned nothing',
            ];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['locale' => $locale, 'items' => $undo]]);
        }

        // Every item failing means nothing was translated, however many rows
        // were attempted — say so rather than reporting a successful run.
        if ($done === []) {
            return [
                'error' => "Nothing could be translated into '{$locale}'. "
                    . 'Tell the user the translation service is not answering right now rather than that their site is translated.',
                'failed' => $failed,
            ];
        }

        $coverage = $this->coverageFor($status, $locale);

        return [
            'success'    => true,
            'locale'     => $locale,
            'translated' => count($done),
            'items'      => $done,
            'failed'     => $failed,
            'coverage'   => $coverage,
            'message'    => $this->summarise($locale, count($done), $failed, $coverage),
        ];
    }

    /**
     * Restore whatever each item held before this run — deleting the rows and
     * the sibling pages the run created, and putting back any wording it
     * overwrote.
     */
    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['items'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $locale = $state['locale'];

        foreach ($state['items'] as $item) {
            if (($item['surface'] ?? '') === 'pages') {
                $this->undoPage($item, $locale);
                continue;
            }

            foreach ($item['translations'] ?? [] as $key => $previous) {
                $row = Translation::where('model_type', $item['model_type'])
                    ->where('model_key', $key)
                    ->where('lang_code', $locale)
                    ->first();

                if ($previous === null) {
                    $row?->delete();
                } elseif ($row) {
                    $row->update(['translation' => $previous]);
                }
            }
        }
    }

    private function undoPage(array $item, string $locale): void
    {
        $sibling = $item['sibling_id'] ?? null;

        if ($sibling === null) {
            // The run created the locale's page; there was none before.
            Page::where('locale', $locale)
                ->where(function ($q) use ($item) {
                    $q->where('id', $item['id'])->orWhere('parent_id', $item['id']);
                })
                ->get()
                ->each
                ->delete();
            return;
        }

        Page::find($sibling)?->update($item['fields'] ?? []);
    }

    /**
     * Build the list of items to translate: either the one that was asked for,
     * or what each surface is still missing, up to the limit.
     */
    private function collectWork(TranslationStatusService $status, array $surfaces, string $locale, $id, int $limit): array
    {
        if ($id !== null) {
            $surface = $surfaces[0];
            $label = $this->labelFor($surface, $id);

            return $label === null ? [] : [['surface' => $surface, 'id' => $id, 'label' => $label]];
        }

        $work = [];
        foreach ($surfaces as $surface) {
            foreach ($status->missing($surface, $locale) as $missing) {
                if (count($work) >= $limit) {
                    return $work;
                }
                $work[] = [
                    'surface' => $surface,
                    'id'      => $missing['id'],
                    'label'   => $missing['label'] ?? (string) $missing['id'],
                ];
            }
        }

        return $work;
    }

    private function labelFor(string $surface, $id): ?string
    {
        return match ($surface) {
            'pages'      => Page::find((int) $id)?->title,
            'articles'   => Content::find((int) $id)?->title,
            'categories' => Category::find((int) $id)?->name,
            default      => null,
        };
    }

    private function translateOne(Translator $translator, string $surface, $id, string $locale): array
    {
        try {
            return match ($surface) {
                'pages'      => $translator->translatePage(Page::findOrFail((int) $id), $locale),
                'articles'   => $translator->translateContent(Content::findOrFail((int) $id), $locale),
                'categories' => $translator->translateCategory(Category::findOrFail((int) $id), $locale),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'fields' => [], 'error' => $e->getMessage()];
        }
    }

    private function snapshot(string $surface, $id, string $locale): array
    {
        if ($surface === 'pages') {
            $sibling = Page::where('locale', $locale)
                ->where(function ($q) use ($id) {
                    $q->where('id', (int) $id)->orWhere('parent_id', (int) $id);
                })
                ->first();

            return [
                'surface'    => 'pages',
                'id'         => (int) $id,
                'sibling_id' => $sibling?->id,
                'fields'     => $sibling ? [
                    'title'            => $sibling->title,
                    'meta_title'       => $sibling->meta_title,
                    'meta_description' => $sibling->meta_description,
                ] : null,
            ];
        }

        [$modelType, $fields] = $surface === 'articles'
            ? ['Content', ['title', 'description', 'content']]
            : ['Category', ['name', 'description']];

        $previous = [];
        foreach ($fields as $field) {
            $key = $id . '_' . $field;
            $previous[$key] = Translation::where('model_type', $modelType)
                ->where('model_key', $key)
                ->where('lang_code', $locale)
                ->value('translation');
        }

        return ['surface' => $surface, 'model_type' => $modelType, 'translations' => $previous];
    }

    private function coverageFor(TranslationStatusService $status, string $locale): array
    {
        $out = [];
        foreach ($status->coverage() as $surface => $byLocale) {
            if (!in_array($surface, self::SURFACES, true)) {
                continue;
            }
            $out[$surface] = $byLocale[$locale] ?? ['translated' => 0, 'total' => 0];
        }

        return $out;
    }

    /**
     * A sentence the chatbot can repeat to the user without dressing it up —
     * including what is still outstanding, so "your site is translated" is
     * never said over a half-finished run.
     */
    private function summarise(string $locale, int $done, array $failed, array $coverage): string
    {
        $remaining = 0;
        foreach ($coverage as $counts) {
            $remaining += max(0, ($counts['total'] ?? 0) - ($counts['translated'] ?? 0));
        }

        $message = "Translated {$done} item(s) into {$locale}.";
        if ($failed !== []) {
            $message .= ' ' . count($failed) . ' could not be translated.';
        }
        $message .= $remaining > 0
            ? " {$remaining} item(s) still have no {$locale} version — run this again to continue."
            : " Everything on the site now has a {$locale} version.";

        return $message;
    }
}
