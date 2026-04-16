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
            return ['scope' => 'site', 'css' => $css ?? '', 'has_css' => !empty($css)];
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
            ];
        }

        return ['error' => "Invalid scope '{$scope}'"];
    }

    public function undo(AiActionLog $actionLog): void
    {
        // Read-only tool, nothing to undo
    }
}
