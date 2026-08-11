<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Models\PageBlock;

class DeletePageTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            // Asked to delete a blog post, the model reaches for this tool,
            // gets a bare "not found", and guesses its way to something else
            // while telling the user the post was removed. Name the gap
            // instead, in words that can be repeated to them as they stand.
            $slug = $parameters['page_slug'] ?? null;
            if ($slug && \VelaBuild\Core\Models\Content::where('type', 'post')->where('slug', $slug)->exists()) {
                return [
                    'error' => "'{$slug}' is a blog article, not a page, and articles cannot be deleted from this chat. "
                        . 'Setting its status to draft with update_article takes it off the site and keeps the writing, '
                        . 'which is what to offer the user — do not tell them it can be deleted permanently here. '
                        . 'Permanent deletion is done in the admin under Articles.',
                ];
            }

            return ['error' => 'Page not found. Pass page_id or page_slug (call list_pages to find it).'];
        }

        // The home page is structural — refuse to delete it.
        if ($page->slug === 'home') {
            return ['error' => 'Refusing to delete the home page.'];
        }

        // Deleting is the one action a user cannot inspect afterwards to see
        // what they lost, so it is gated here rather than left to prompt
        // wording alone. The refusal doubles as the text to put in front of
        // the user: it says exactly what would go.
        if (empty($parameters['confirm'])) {
            $rowCount = $page->rows()->count();
            $blockCount = PageBlock::whereIn('page_row_id', $page->rows()->pluck('id'))->count();

            return [
                'error' => "Not deleted yet — deleting needs the user's confirmation. This would remove the page "
                    . "'{$page->title}' (/{$page->slug}) together with {$rowCount} section(s) and {$blockCount} block(s) of content. "
                    . 'Tell the user exactly that, in their own language, and wait for their answer in a later message. '
                    . 'Only once they have agreed, call delete_page again with confirm: true. Do not agree on their behalf.',
                'needs_confirmation' => true,
                'page' => [
                    'id'     => $page->id,
                    'title'  => $page->title,
                    'slug'   => $page->slug,
                    'rows'   => $rowCount,
                    'blocks' => $blockCount,
                ],
            ];
        }

        // Snapshot the full page (attributes + rows + blocks) so the delete
        // is undoable.
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'attributes' => $page->getAttributes(),
                    'rows'       => $page->rows()->with('blocks')->get()->map(function ($row) {
                        return [
                            'attributes' => $row->getAttributes(),
                            'blocks'     => $row->blocks->map->getAttributes()->all(),
                        ];
                    })->all(),
                ],
            ]);
        }

        $id = $page->id;
        $title = $page->title;
        $slug = $page->slug;

        // Deleting the Page cascades cleanup via PageObserver (menu items +
        // static cache). Remove its rows/blocks explicitly first.
        foreach ($page->rows as $row) {
            $row->blocks()->delete();
            $row->delete();
        }
        $page->delete();

        return [
            'success' => true,
            'deleted' => ['id' => $id, 'title' => $title, 'slug' => $slug],
            'message' => "Deleted page '{$title}' (id={$id}, slug={$slug}).",
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['attributes'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        // Restore the page preserving its original id so references hold.
        // Page uses SoftDeletes, so the delete only stamped deleted_at and the
        // row is still there — inserting a fresh record would collide on the
        // primary key. Rows and blocks are hard-deleted, so those do get
        // re-inserted below.
        $page = Page::withTrashed()->find($state['attributes']['id'] ?? null) ?: new Page();
        $this->restoreAttributes($page, array_merge($state['attributes'], ['deleted_at' => null]));

        foreach ($state['rows'] ?? [] as $rowData) {
            $this->restoreAttributes(new PageRow(), $rowData['attributes']);

            foreach ($rowData['blocks'] ?? [] as $blockAttrs) {
                $this->restoreAttributes(new PageBlock(), $blockAttrs);
            }
        }
    }

    /**
     * Write a snapshot back exactly as it came out of the database.
     *
     * The snapshot holds raw column values (getAttributes()), so `content` and
     * `settings` are already JSON strings. Assigning them through fill() would
     * run the array casts again and store JSON inside JSON — the block then
     * reads back as a string and its view renders nothing.
     */
    private function restoreAttributes($model, array $attributes): void
    {
        $model->setRawAttributes($attributes);
        $model->save();
    }

    private function resolvePage(array $parameters): ?Page
    {
        if (!empty($parameters['page_id'])) {
            return Page::find($parameters['page_id']);
        }
        if (!empty($parameters['page_slug'])) {
            return Page::where('slug', $parameters['page_slug'])->first();
        }
        return null;
    }
}
