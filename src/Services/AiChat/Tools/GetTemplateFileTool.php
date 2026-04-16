<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class GetTemplateFileTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $file = $parameters['file'] ?? null;
        if (!$file) {
            return ['error' => 'File parameter is required'];
        }

        $vela = app(\VelaBuild\Core\Vela::class);
        $templates = $vela->templates()->all();
        $activeTemplate = config('vela.template.active', 'default');

        if (!isset($templates[$activeTemplate])) {
            return ['error' => "Active template '{$activeTemplate}' not found"];
        }

        $templatePath = $templates[$activeTemplate]['path'];
        $fullPath = realpath($templatePath . '/' . $file);

        if ($fullPath === false || strpos($fullPath, realpath($templatePath)) !== 0) {
            return ['error' => 'File not found or access denied'];
        }

        if (!file_exists($fullPath)) {
            return ['error' => 'File not found or access denied'];
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return ['error' => 'File not found or access denied'];
        }

        return [
            'success' => true,
            'file' => $file,
            'content' => $content,
        ];
    }
}
