<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Page;

class GetCustomCssTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $scope = $parameters['scope'] ?? 'site';

        if ($scope === 'site') {
            $css = VelaConfig::where('key', 'custom_css_global')->first()?->value;
            return [
                'scope' => 'site',
                'css' => $css ?? '',
                'has_css' => !empty($css),
                'block_variables' => self::blockVariables(),
                'how_to_recolour_blocks' => 'Buttons, icons and accents across every block are driven by these custom '
                    . 'properties, not by per-element rules. Override them in a :root { } rule to restyle the whole site, '
                    . 'or inside #row-<id> / #block-<id> to restyle one section.',
            ];
        }

        if ($scope === 'page') {
            $pageId = $parameters['page_id'] ?? null;
            $pageSlug = $parameters['page_slug'] ?? null;

            $page = $pageId
                ? Page::find($pageId)
                : ($pageSlug ? Page::where('slug', $pageSlug)->first() : null);

            if (!$page) {
                return ['error' => 'Page not found'];
            }

            return [
                'scope' => 'page',
                'page_id' => $page->id,
                'page_title' => $page->title,
                'css' => $page->custom_css ?? '',
                'has_css' => !empty($page->custom_css),
                'block_variables' => self::blockVariables(),
                'how_to_recolour_blocks' => 'Buttons, icons and accents are driven by these custom properties, not by '
                    . 'per-element rules. Override them inside #row-<id> or #block-<id> (both ids are rendered on the '
                    . 'page) to restyle one section, or in :root to restyle everything.',
            ];
        }

        return ['error' => "Invalid scope '{$scope}'"];
    }

    /**
     * The custom properties the block stylesheet actually reads.
     *
     * Without this the model reaches for per-element rules — colouring
     * `.icon-box-icon` when the glyph takes its colour from --block-accent
     * through a more specific selector — and the change never lands.
     *
     * @return array<string, string>
     */
    /**
     * The custom properties the block stylesheet defines. Shared with
     * update_custom_css, which refuses rules that read properties this site
     * never sets.
     */
    public static function blockVariables(): array
    {
        $stylesheet = __DIR__ . '/../../../../public/css/page-blocks.css';
        if (!is_file($stylesheet)) {
            return [];
        }

        if (!preg_match('/:root\s*\{([^}]*)\}/', file_get_contents($stylesheet), $root)) {
            return [];
        }

        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $root[1], $declarations, PREG_SET_ORDER);

        $variables = [];
        foreach ($declarations as $declaration) {
            $variables[$declaration[1]] = trim($declaration[2]);
        }

        return $variables;
    }

    public function undo(AiActionLog $actionLog): void
    {
        // Read-only tool, nothing to undo
    }
}
