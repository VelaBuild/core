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
            'content'     => $this->convertToEditorJs($content),
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

    private function convertToEditorJs(string $contentText): string
    {
        $lines = explode("\n", $contentText);
        $blocks = [];
        $blockId = 1;
        $currentList = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }
                continue;
            }

            // Handle image tags
            if (preg_match('/\[IMAGE\s+topic="([^"]+)"\s+alt="([^"]+)"\]/i', $line, $matches)) {
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }
                $blocks[] = [
                    'id'   => 'paragraph-' . $blockId++,
                    'type' => 'paragraph',
                    'data' => ['text' => $line],
                ];
            }
            // Handle headings
            elseif (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }
                $level = strlen($matches[1]);
                $blocks[] = [
                    'id'   => 'heading-' . $blockId++,
                    'type' => 'header',
                    'data' => [
                        'text'  => $matches[2],
                        'level' => min($level, 6),
                    ],
                ];
            }
            // Handle unordered lists
            elseif (preg_match('/^-\s+(.+)$/', $line, $matches)) {
                if (!$currentList || $currentList['type'] !== 'list') {
                    if ($currentList) {
                        $blocks[] = $currentList;
                    }
                    $currentList = [
                        'id'   => 'list-' . $blockId++,
                        'type' => 'list',
                        'data' => ['style' => 'unordered', 'items' => []],
                    ];
                }
                $currentList['data']['items'][] = $this->processInlineFormatting($matches[1]);
            }
            // Handle ordered lists
            elseif (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
                if (!$currentList || $currentList['type'] !== 'list' || $currentList['data']['style'] !== 'ordered') {
                    if ($currentList) {
                        $blocks[] = $currentList;
                    }
                    $currentList = [
                        'id'   => 'list-' . $blockId++,
                        'type' => 'list',
                        'data' => ['style' => 'ordered', 'items' => []],
                    ];
                }
                $currentList['data']['items'][] = $this->processInlineFormatting($matches[1]);
            }
            // Handle regular paragraphs
            else {
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }
                $blocks[] = [
                    'id'   => 'paragraph-' . $blockId++,
                    'type' => 'paragraph',
                    'data' => ['text' => $this->processInlineFormatting($line)],
                ];
            }
        }

        if ($currentList) {
            $blocks[] = $currentList;
        }

        return json_encode([
            'time'   => time() * 1000,
            'blocks' => $blocks,
        ]);
    }

    private function processInlineFormatting(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        return $text;
    }
}
