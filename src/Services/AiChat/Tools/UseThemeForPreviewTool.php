<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Services\StaticSiteGenerator;

/**
 * Point the design preview page at a theme, without dressing the site in it.
 *
 * switch_template changes what every visitor sees, immediately. A design build
 * called it at its third step so that its own work would be visible — which
 * meant a person who pressed Build to see what a design might look like had
 * their live site wearing it, header and all, from the first minute. The build
 * writes onto a page of its own for exactly this reason; the theme has to
 * follow the same rule.
 */
class UseThemeForPreviewTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $theme = trim((string) ($parameters['theme'] ?? ''));

        if ($theme === '') {
            return ['error' => 'theme is required — the name create_theme returned.'];
        }

        $canonical = $this->canonicalName($theme);

        if ($canonical === null) {
            return ['error' => 'There is no theme called "' . $theme . '". Call create_theme first, or '
                . 'list_templates for the ones that exist.'];
        }

        $theme = $canonical;

        $frame = app(DesignPreviewFrame::class);

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['theme' => $frame->theme()]]);
        }

        $frame->setTheme($theme);

        try {
            app(StaticSiteGenerator::class)->purgeHtml();
        } catch (\Throwable $e) {
            // Not worth failing the call over.
        }

        return [
            'success' => true,
            'theme' => $theme,
            'note' => 'The design preview page renders in this theme from now on. The rest of the site keeps the '
                . 'theme it has until someone presses "use this as my homepage".',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;

        if (!is_array($state)) {
            throw new \RuntimeException('No previous state to undo.');
        }

        if (($state['theme'] ?? null) === null) {
            VelaConfig::where('key', DesignPreviewFrame::THEME_KEY)->delete();

            return;
        }

        app(DesignPreviewFrame::class)->setTheme($state['theme']);
    }

    /**
     * The name the theme is really filed under, or null if there is no such theme.
     *
     * A build asked for "Zercurity" while its folder was `zercurity`, and on a
     * Mac `is_dir()` said yes — the filesystem does not care about case. The
     * name was then stored as typed, the page rendered stamped `zercurity`,
     * and the check that the preview page is wearing the theme the build wrote
     * compared two spellings of the same theme and threw the whole run away at
     * the last step. Store what the theme is called, not what was typed.
     */
    private function canonicalName(string $theme): ?string
    {
        $registry = app(\VelaBuild\Core\Vela::class)->templates()->all();

        foreach (array_keys($registry) as $name) {
            if (strcasecmp((string) $name, $theme) === 0) {
                return (string) $name;
            }
        }

        foreach (glob(resource_path('views/templates/*'), GLOB_ONLYDIR) ?: [] as $path) {
            if (strcasecmp(basename($path), $theme) === 0) {
                return basename($path);
            }
        }

        return null;
    }
}
