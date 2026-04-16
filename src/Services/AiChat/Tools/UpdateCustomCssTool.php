<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Jobs\GenerateStaticFilesJob;

class UpdateCustomCssTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $scope = $parameters['scope'] ?? 'site';
        $css = $parameters['css'] ?? '';

        if ($css === '') {
            return ['error' => 'CSS content is required'];
        }

        if ($scope === 'site') {
            $current = VelaConfig::where('key', 'custom_css_global')->first();
            $previousState = ['scope' => 'site', 'value' => $current?->value];

            if ($actionLog) {
                $actionLog->update(['previous_state' => $previousState]);
            }

            VelaConfig::updateOrCreate(['key' => 'custom_css_global'], ['value' => $css]);

            // Regenerate all static files since sitewide CSS affects every page
            GenerateStaticFilesJob::dispatch('all');

            return ['success' => true, 'scope' => 'site', 'message' => 'Sitewide CSS updated and cache cleared'];
        }

        if ($scope === 'page') {
            $pageId = $parameters['page_id'] ?? null;
            $pageSlug = $parameters['page_slug'] ?? null;

            $page = $pageId
                ? Page::find($pageId)
                : ($pageSlug ? Page::where('slug', $pageSlug)->first() : null);

            if (!$page) {
                return ['error' => 'Page not found. Provide page_id or page_slug.'];
            }

            $previousState = ['scope' => 'page', 'page_id' => $page->id, 'value' => $page->custom_css];

            if ($actionLog) {
                $actionLog->update(['previous_state' => $previousState]);
            }

            $page->update(['custom_css' => $css]);

            // Regenerate this page's static file
            GenerateStaticFilesJob::dispatch('page', $page->id);

            return ['success' => true, 'scope' => 'page', 'page_id' => $page->id, 'message' => "CSS updated for page '{$page->title}' and cache cleared"];
        }

        return ['error' => "Invalid scope '{$scope}'. Use 'site' or 'page'."];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        if ($state['scope'] === 'site') {
            if ($state['value'] === null) {
                VelaConfig::where('key', 'custom_css_global')->delete();
            } else {
                VelaConfig::updateOrCreate(['key' => 'custom_css_global'], ['value' => $state['value']]);
            }
        } elseif ($state['scope'] === 'page') {
            $page = Page::find($state['page_id']);
            if ($page) {
                $page->update(['custom_css' => $state['value']]);
            }
        }
    }
}
