<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\ThemeAuthor;

class CreateThemeTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $name = trim((string) ($parameters['name'] ?? ''));

        if ($name === '') {
            return ['error' => 'name is required — something short, like the site itself.'];
        }

        $kind = trim((string) ($parameters['kind'] ?? 'landing'));

        if (!array_key_exists($kind, \VelaBuild\Core\Services\ThemeSkeleton::KINDS)) {
            return [
                'error' => 'kind must be one of: ' . implode(', ', array_keys(\VelaBuild\Core\Services\ThemeSkeleton::KINDS))
                    . '. It decides what the theme looks like before you touch it, so choose the one the design is.',
                'kinds' => \VelaBuild\Core\Services\ThemeSkeleton::KINDS,
            ];
        }

        $author = app(ThemeAuthor::class);

        try {
            $theme = $author->scaffold(
                $name,
                (string) ($parameters['label'] ?? $name),
                (string) ($parameters['description'] ?? ''),
                $kind,
                // The one theme this may write over: the one a previous build
                // of this design staged as its preview. A design's name gives
                // the same theme name every run, so rebuilding has to be able
                // to reuse its own folder — and must not be able to reuse
                // anybody else's, which is what it had been doing.
                app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->theme()
            );
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['theme' => $theme]]);
        }

        return [
            'success' => true,
            'theme' => $theme,
            'kind' => $kind,
            'views_you_can_write' => ThemeAuthor::VIEWS,
            // Handed over unasked, because a stylesheet written against
            // invented class names fails silently — the page renders, the
            // rules match nothing, and the design never appears.
            'style_these_block_classes' => $author->classesInUse(
                \VelaBuild\Core\Models\PageBlock::query()->distinct()->pluck('type')->all()
            ),
            'next' => 'Call get_theme_contract, then write_theme_file for each view. '
                . 'Nothing is visible until you switch_template to "' . $theme . '".',
        ];
    }
}
