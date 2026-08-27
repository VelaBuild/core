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

        $author = app(ThemeAuthor::class);

        try {
            $theme = $author->scaffold(
                $name,
                (string) ($parameters['label'] ?? $name),
                (string) ($parameters['description'] ?? '')
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
