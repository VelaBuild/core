<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

abstract class BaseTool
{
    abstract public function execute(array $parameters, ?AiActionLog $actionLog = null): array;

    public function undo(AiActionLog $actionLog): void
    {
        throw new \RuntimeException('Undo not supported for this tool.');
    }
}
