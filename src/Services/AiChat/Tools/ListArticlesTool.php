<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Content;

class ListArticlesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $limit = $parameters['limit'] ?? 20;

        $query = Content::with('categories:name')
            ->select('id', 'title', 'slug', 'status', 'created_at')
            ->where('type', 'post')
            ->orderBy('created_at', 'desc');

        if (!empty($parameters['category'])) {
            $query->whereHas('categories', function ($q) use ($parameters) {
                $q->where('name', $parameters['category']);
            });
        }

        $articles = $query->take($limit)->get()->toArray();

        return ['success' => true, 'articles' => $articles];
    }
}
