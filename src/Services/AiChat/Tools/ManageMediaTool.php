<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ManageMediaTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $action = $parameters['action'] ?? 'list';

        return match ($action) {
            'list' => $this->listMedia($parameters),
            'info' => $this->getMediaInfo($parameters),
            'search' => $this->searchMedia($parameters),
            default => ['error' => "Unknown action: {$action}. Available: list, info, search"],
        };
    }

    private function listMedia(array $params): array
    {
        $limit = min($params['limit'] ?? 20, 50);
        $collection = $params['collection'] ?? null;

        $query = Media::orderByDesc('created_at');
        if ($collection) {
            $query->where('collection_name', $collection);
        }

        $items = $query->limit($limit)->get()->map(fn($m) => [
            'id' => $m->id,
            'name' => $m->file_name,
            'collection' => $m->collection_name,
            'mime' => $m->mime_type,
            'size' => $m->size,
            'url' => $m->getUrl(),
            'created' => $m->created_at->toDateTimeString(),
        ]);

        return ['media' => $items->toArray(), 'count' => $items->count()];
    }

    private function getMediaInfo(array $params): array
    {
        $id = $params['id'] ?? null;
        if (!$id) return ['error' => 'id is required'];

        $media = Media::find($id);
        if (!$media) return ['error' => 'Media not found'];

        return [
            'id' => $media->id,
            'name' => $media->file_name,
            'collection' => $media->collection_name,
            'mime' => $media->mime_type,
            'size' => $media->size,
            'url' => $media->getUrl(),
            'model_type' => $media->model_type,
            'model_id' => $media->model_id,
            'custom_properties' => $media->custom_properties,
            'created' => $media->created_at->toDateTimeString(),
        ];
    }

    private function searchMedia(array $params): array
    {
        $query = $params['query'] ?? '';
        if (strlen($query) < 2) return ['error' => 'query must be at least 2 characters'];

        $items = Media::where('file_name', 'like', "%{$query}%")
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->file_name,
                'collection' => $m->collection_name,
                'url' => $m->getUrl(),
            ]);

        return ['media' => $items->toArray(), 'count' => $items->count()];
    }
}
