<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\ThemeAuthor;

class WriteThemeFileTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $theme = trim((string) ($parameters['theme'] ?? ''));
        $view = trim((string) ($parameters['view'] ?? ''));
        $contents = (string) ($parameters['contents'] ?? '');

        if ($theme === '' || $view === '' || $contents === '') {
            return ['error' => 'theme, view and contents are all required.'];
        }

        $author = app(ThemeAuthor::class);

        if ($actionLog) {
            $file = $author->directory($theme) . '/' . $view . '.blade.php';
            $actionLog->update(['previous_state' => [
                'theme' => $theme,
                'view' => $view,
                'contents' => is_file($file) ? file_get_contents($file) : null,
            ]]);
        }

        try {
            $author->writeView($theme, $view, $contents);
        } catch (\RuntimeException $e) {
            // The message says what is wrong with the Blade, so the caller can
            // fix it rather than discovering it as a white page later.
            return ['error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'written' => $view,
            'theme_now_has' => $author->writtenViews($theme),
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;

        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $file = app(ThemeAuthor::class)->directory($state['theme']) . '/' . $state['view'] . '.blade.php';

        if ($state['contents'] === null) {
            @unlink($file);
            return;
        }

        file_put_contents($file, $state['contents']);
    }
}
