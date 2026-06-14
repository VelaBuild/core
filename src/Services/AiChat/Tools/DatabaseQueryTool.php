<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Illuminate\Support\Facades\DB;

class DatabaseQueryTool extends BaseTool
{
    private const BLOCKED_STATEMENTS = [
        '/\bDROP\b/i',
        '/\bTRUNCATE\b/i',
        '/\bDELETE\b/i',
        '/\bUPDATE\b/i',
        '/\bINSERT\b/i',
        '/\bALTER\b/i',
        '/\bCREATE\b/i',
        '/\bGRANT\b/i',
        '/\bREVOKE\b/i',
    ];

    private const MAX_ROWS = 100;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $query = trim($parameters['query'] ?? '');
        if (!$query) {
            return ['error' => 'query is required'];
        }

        if (!preg_match('/^\s*SELECT\b/i', $query)) {
            return ['error' => 'Only SELECT queries are allowed. This tool is read-only.'];
        }

        foreach (self::BLOCKED_STATEMENTS as $pattern) {
            if (preg_match($pattern, $query)) {
                return ['error' => 'Query contains a blocked statement. Only read-only SELECT queries are allowed.'];
            }
        }

        try {
            $results = DB::select(DB::raw($query . ' LIMIT ' . self::MAX_ROWS));
            $rows = array_map(fn($row) => (array) $row, $results);

            return [
                'rows' => $rows,
                'count' => count($rows),
                'truncated' => count($rows) >= self::MAX_ROWS,
            ];
        } catch (\Exception $e) {
            return ['error' => 'Query failed: ' . $e->getMessage()];
        }
    }
}
