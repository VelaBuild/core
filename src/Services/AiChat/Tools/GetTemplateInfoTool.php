<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Vela;

class GetTemplateInfoTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $templateName = $parameters['template'] ?? null;
        if (!$templateName) {
            return ['error' => 'template parameter is required'];
        }

        $registry = app(Vela::class)->templates();
        $template = $registry->get($templateName);

        if (!$template) {
            return ['error' => "Template '{$templateName}' not found."];
        }

        $files = [];
        if ($template['path'] && is_dir($template['path'])) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($template['path'], \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = str_replace($template['path'] . '/', '', $file->getPathname());
                }
            }
            sort($files);
        }

        return [
            'success' => true,
            'template' => [
                'name' => $templateName,
                'label' => $template['label'],
                'description' => $template['description'],
                'category' => $template['category'],
                'screenshot' => $template['screenshot'],
                'files' => $files,
            ],
        ];
    }
}
