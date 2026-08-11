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

        // Refuse to create a duplicate when a non-trashed article with the same
        // title already exists. The AI should call list_articles + edit_article_content
        // to update an existing entry instead of stacking copies. The error
        // returns the existing id so the model can pivot to edit_article_content
        // without another round trip.
        $existing = Content::where('type', 'post')
            ->whereRaw('LOWER(title) = ?', [strtolower(trim($title))])
            ->first();
        if ($existing) {
            return [
                'error'           => "An article titled '{$existing->title}' already exists (id={$existing->id}). Use edit_article_content to update it instead of creating a duplicate.",
                'existing_id'     => $existing->id,
                'existing_title'  => $existing->title,
                'existing_status' => $existing->status,
            ];
        }

        $document = MarkdownToEditorJs::convert($content);
        if ($error = $this->validateArticleImages($document)) {
            return $error;
        }

        $slug = Str::slug($title);
        $original = $slug;
        $i = 1;
        while (Content::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i++;
        }

        $article = Content::create([
            'title'      => $title,
            'slug'       => $slug,
            'type'       => 'post',
            'description' => MarkdownToEditorJs::excerpt($content),
            'content'    => $document,
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
}
