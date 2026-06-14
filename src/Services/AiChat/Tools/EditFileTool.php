<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class EditFileTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $path = $parameters['path'] ?? '';
        $search = $parameters['search'] ?? '';
        $replace = $parameters['replace'] ?? null;

        if (!$path || !$search || $replace === null) {
            return ['error' => 'path, search, and replace are required'];
        }

        $writeTool = app(WriteFileTool::class);
        $readTool = app(ReadFileTool::class);

        $readResult = $readTool->execute(['path' => $path]);
        if (isset($readResult['error'])) {
            return $readResult;
        }

        $content = $readResult['content'];
        $count = substr_count($content, $search);

        if ($count === 0) {
            return ['error' => 'Search string not found in file. Make sure you use the exact text from the file.'];
        }

        if ($count > 1 && !($parameters['replace_all'] ?? false)) {
            return ['error' => "Found {$count} matches. Provide more context to match uniquely, or set replace_all=true."];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['content' => $content]]);
        }

        $newContent = $parameters['replace_all'] ?? false
            ? str_replace($search, $replace, $content)
            : $this->replaceFirst($content, $search, $replace);

        $writeResult = $writeTool->execute(['path' => $path, 'content' => $newContent]);
        if (isset($writeResult['error'])) {
            return $writeResult;
        }

        return ['success' => true, 'path' => $path, 'replacements' => $parameters['replace_all'] ?? false ? $count : 1];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $prev = $actionLog->previous_state;
        $path = $actionLog->parameters['path'] ?? null;
        if (!$path || !isset($prev['content'])) return;

        file_put_contents(base_path(ltrim($path, '/')), $prev['content']);
    }

    private function replaceFirst(string $haystack, string $needle, string $replacement): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) return $haystack;
        return substr_replace($haystack, $replacement, $pos, strlen($needle));
    }
}
