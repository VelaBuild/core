<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Content;

class GetArticleTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $id = $parameters['article_id'] ?? null;
        $slug = $parameters['slug'] ?? null;

        if (!$id && !$slug) {
            return ['error' => 'Pass either article_id or slug.'];
        }

        $article = $id
            ? Content::find($id)
            : Content::where('slug', $slug)->first();

        if (!$article) {
            return ['error' => 'Article not found.'];
        }

        $article->load('categories');

        return [
            'success'      => true,
            'id'           => $article->id,
            'title'        => $article->title,
            'slug'         => $article->slug,
            'description'  => $article->description,
            'status'       => $article->status,
            'author_id'    => $article->author_id,
            'categories'   => $article->categories->pluck('name')->all(),
            'published_at' => $article->published_at?->toISOString(),
            'updated_at'   => $article->updated_at?->toISOString(),
            // Returned as the raw EditorJS JSON string. The AI can json_decode
            // it itself if needed; usually it just rewrites the whole thing
            // via edit_article_content with new markdown.
            'content_json' => $article->content,
        ];
    }
}
