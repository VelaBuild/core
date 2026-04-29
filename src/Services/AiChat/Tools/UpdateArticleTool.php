<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Str;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;

class UpdateArticleTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $id = $parameters['article_id'] ?? null;
        if (!$id) {
            return ['error' => 'article_id is required.'];
        }

        $article = Content::find($id);
        if (!$article) {
            return ['error' => "Article {$id} not found."];
        }

        // Snapshot for undo before any mutation.
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'article_id'  => $article->id,
                    'title'       => $article->title,
                    'slug'        => $article->slug,
                    'status'      => $article->status,
                    'description' => $article->description,
                    'keyword'     => $article->keyword,
                    'category_ids' => $article->categories()->pluck('vela_categories.id')->all(),
                ],
            ]);
        }

        $updates = [];
        $changed = [];

        if (array_key_exists('title', $parameters) && $parameters['title'] !== null && $parameters['title'] !== '') {
            $updates['title'] = (string) $parameters['title'];
            $changed[] = 'title';
        }

        if (array_key_exists('slug', $parameters) && $parameters['slug'] !== null && $parameters['slug'] !== '') {
            $newSlug = Str::slug($parameters['slug']);
            // Don't collide with another article's slug.
            if (Content::where('slug', $newSlug)->where('id', '!=', $article->id)->exists()) {
                return ['error' => "Slug '{$newSlug}' already in use by another article."];
            }
            $updates['slug'] = $newSlug;
            $changed[] = 'slug';
        }

        if (array_key_exists('status', $parameters) && $parameters['status'] !== null) {
            $allowed = ['planned', 'draft', 'scheduled', 'published'];
            if (!in_array($parameters['status'], $allowed, true)) {
                return ['error' => 'status must be one of: ' . implode(', ', $allowed)];
            }
            $updates['status'] = $parameters['status'];
            $changed[] = 'status';
        }

        if (array_key_exists('description', $parameters) && $parameters['description'] !== null) {
            $updates['description'] = (string) $parameters['description'];
            $changed[] = 'description';
        }

        if (array_key_exists('keyword', $parameters) && $parameters['keyword'] !== null) {
            $updates['keyword'] = (string) $parameters['keyword'];
            $changed[] = 'keyword';
        }

        if (!empty($updates)) {
            $article->update($updates);
        }

        // Categories: full sync if the array was provided. Names are matched
        // case-insensitively; missing categories are reported (not auto-created).
        if (array_key_exists('categories', $parameters) && is_array($parameters['categories'])) {
            $names = array_filter(array_map('trim', $parameters['categories']));
            $missing = [];
            $ids = [];
            foreach ($names as $name) {
                $cat = Category::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
                if ($cat) {
                    $ids[] = $cat->id;
                } else {
                    $missing[] = $name;
                }
            }
            $article->categories()->sync($ids);
            $changed[] = 'categories';
            if (!empty($missing)) {
                return [
                    'success'         => true,
                    'article_id'      => $article->id,
                    'changed_fields'  => $changed,
                    'missing_categories' => $missing,
                    'note'            => 'Article updated, but these category names did not match existing categories: ' . implode(', ', $missing) . '. Use create_category first if you want them attached.',
                ];
            }
        }

        if (empty($changed)) {
            return ['error' => 'No fields to update. Pass at least one of: title, slug, status, description, keyword, categories.'];
        }

        return [
            'success'        => true,
            'article_id'     => $article->id,
            'changed_fields' => $changed,
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['article_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $article = Content::find($state['article_id']);
        if (!$article) {
            throw new \RuntimeException("Article {$state['article_id']} no longer exists.");
        }

        $article->update([
            'title'       => $state['title'],
            'slug'        => $state['slug'],
            'status'      => $state['status'],
            'description' => $state['description'],
            'keyword'     => $state['keyword'],
        ]);
        if (isset($state['category_ids'])) {
            $article->categories()->sync($state['category_ids']);
        }
    }
}
