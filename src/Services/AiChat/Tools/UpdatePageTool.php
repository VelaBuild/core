<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Str;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;

class UpdatePageTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug (call list_pages to find it).'];
        }

        // Snapshot for undo before any mutation.
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'page_id'          => $page->id,
                    'title'            => $page->title,
                    'slug'             => $page->slug,
                    'status'           => $page->status,
                    'meta_title'       => $page->meta_title,
                    'meta_description' => $page->meta_description,
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
            // The design preview's address is the handle everything holds it
            // by: the build finds it to photograph, the preview frame decides
            // which page wears the staged theme by matching it, and the admin
            // page links to it. Told to name the page after the site — which
            // it is told to do — a run renamed the slug with the title, and
            // the page it was building vanished from under it: 404 on the
            // address, a new empty one made on the next run, and the theme it
            // had staged no longer reaching the page it was written for.
            if ($page->slug === \VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG) {
                return [
                    'error' => 'This is the design preview page, and its address is what the build, the preview '
                        . 'frame and the admin all use to find it — renaming it loses the page. Change its title '
                        . 'freely; the address becomes the site\'s own when the design is kept.',
                    'slug' => $page->slug,
                ];
            }

            $newSlug = Str::slug($parameters['slug']);
            if ($newSlug === '') {
                return ['error' => "Slug '{$parameters['slug']}' is empty after slugifying."];
            }
            Page::releaseSlugFromTrash($newSlug, (string) $page->locale, $page->id);
            if (Page::where('slug', $newSlug)->where('id', '!=', $page->id)->exists()) {
                return ['error' => "Slug '{$newSlug}' is already used by another page."];
            }
            $updates['slug'] = $newSlug;
            $changed[] = 'slug';
        }

        if (array_key_exists('status', $parameters) && $parameters['status'] !== null) {
            $allowed = ['draft', 'published', 'unlisted'];
            if (!in_array($parameters['status'], $allowed, true)) {
                return ['error' => 'status must be one of: ' . implode(', ', $allowed)];
            }
            $updates['status'] = $parameters['status'];
            $changed[] = 'status';
        }

        // Search-engine metadata. Without these the AI has no way to act on
        // "fix my SEO" — the only common request it could otherwise answer
        // with nothing but advice.
        foreach (['meta_title' => 60, 'meta_description' => 160] as $field => $limit) {
            if (!array_key_exists($field, $parameters) || $parameters[$field] === null) {
                continue;
            }
            $value = trim((string) $parameters[$field]);
            if (mb_strlen($value) > $limit) {
                return ['error' => "{$field} is " . mb_strlen($value) . " characters; search engines truncate it past {$limit}. Shorten it and resend."];
            }
            $updates[$field] = $value !== '' ? $value : null;
            $changed[] = $field;
        }

        if (empty($changed)) {
            return ['error' => 'No fields to update. Pass at least one of: title, slug, status, meta_title, meta_description.'];
        }

        $page->update($updates);

        return [
            'success'        => true,
            'page_id'        => $page->id,
            'changed_fields' => $changed,
            'slug'           => $page->slug,
            'url'            => url('/' . $page->slug),
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['page_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $page = Page::find($state['page_id']);
        if (!$page) {
            throw new \RuntimeException("Page {$state['page_id']} no longer exists.");
        }

        $page->update([
            'title'            => $state['title'],
            'slug'             => $state['slug'],
            'status'           => $state['status'],
            // Older logs predate these keys — leave the current value alone
            // rather than blanking the metadata on undo.
            'meta_title'       => $state['meta_title'] ?? $page->meta_title,
            'meta_description' => $state['meta_description'] ?? $page->meta_description,
        ]);
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
