<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Content;
use Illuminate\Support\Str;

class EditArticleContentTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $articleId = $parameters['article_id'] ?? null;
        $content = $parameters['content'] ?? null;

        if (!$articleId) {
            return ['error' => 'article_id parameter is required'];
        }
        if ($content === null) {
            return ['error' => 'content parameter is required'];
        }

        $article = Content::find($articleId);
        if (!$article) {
            return ['error' => "Article {$articleId} not found"];
        }

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'article_id' => $articleId,
                    'content'    => $article->content,
                    'description' => $article->description,
                ],
            ]);
        }

        $article->update([
            'content'     => MarkdownToEditorJs::convert($content),
            'description' => Str::limit($content, 160),
        ]);

        return [
            'success'    => true,
            'article_id' => $article->id,
            'message'    => 'Article content updated',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state || !isset($state['article_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $article = Content::find($state['article_id']);
        if (!$article) {
            throw new \RuntimeException("Article {$state['article_id']} not found for undo.");
        }

        $article->update([
            'content'     => $state['content'],
            'description' => $state['description'],
        ]);
    }
}
