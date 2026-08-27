<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Services\ThemeSkeleton;

class SetThemeTokensTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $theme = trim((string) ($parameters['theme'] ?? ''));
        $tokens = $parameters['tokens'] ?? null;

        if ($theme === '' || !is_array($tokens) || !$tokens) {
            return [
                'error' => 'theme and tokens are required. tokens is an object of name to value, e.g. {"accent": "#C1440E", "bg": "#FFF8F0"}.',
                'tokens_you_can_set' => app(ThemeSkeleton::class)->tokenReference(),
            ];
        }

        $author = app(ThemeAuthor::class);

        if ($actionLog) {
            $file = $author->directory($theme) . '/layout.blade.php';
            $actionLog->update(['previous_state' => [
                'theme' => $theme,
                'contents' => is_file($file) ? file_get_contents($file) : null,
            ]]);
        }

        try {
            $result = $author->setTokens($theme, $tokens);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $response = ['success' => true, 'applied' => $result['applied']];

        if ($result['unknown']) {
            // Named but not applied, and silence here would look like success.
            $response['ignored_unknown_tokens'] = $result['unknown'];
            $response['tokens_you_can_set'] = array_keys(ThemeSkeleton::TOKENS);
        }

        return $response;
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;

        if (!$state || $state['contents'] === null) {
            throw new \RuntimeException('No previous state to restore.');
        }

        file_put_contents(
            app(ThemeAuthor::class)->directory($state['theme']) . '/layout.blade.php',
            $state['contents']
        );
    }
}
