<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Category;
use Illuminate\Support\Str;

class CreateArticleTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $title = $parameters['title'] ?? null;
        $content = $parameters['content'] ?? '';
        $status = $parameters['status'] ?? 'draft';
        $categoryName = $parameters['category'] ?? null;

        if (!$title) {
            return ['error' => 'Title parameter is required'];
        }

        $slug = Str::slug($title);

        // Ensure slug uniqueness
        $original = $slug;
        $i = 1;
        while (Content::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i++;
        }

        $article = Content::create([
            'title'      => $title,
            'slug'       => $slug,
            'type'       => 'post',
            'description' => Str::limit($content, 160),
            'content'    => $this->convertToEditorJs($content),
            'author_id'  => 1,
            'status'     => $status,
            'written_at' => now(),
        ]);

        if ($categoryName) {
            $category = Category::where('name', $categoryName)->first();
            if ($category) {
                $article->categories()->attach($category->id);
            }
        }

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => ['created_id' => $article->id],
            ]);
        }

        return [
            'success' => true,
            'article' => [
                'id'    => $article->id,
                'title' => $article->title,
            ],
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state || !isset($state['created_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        Content::find($state['created_id'])?->delete();
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
