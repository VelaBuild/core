<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\Blocks\PostsGrid;

/**
 * What the posts grid dialog draws while its settings are being chosen, and
 * the posts it offers when they are being picked one by one.
 *
 * The cards come from PostsGrid, the same code the page renders with, so the
 * preview is of these settings on this site rather than a drawing of a grid.
 */
class PostsGridPreviewController extends Controller
{
    private const SEARCH_LIMIT = 12;

    public function preview(Request $request)
    {
        $this->authorise();

        $raw = json_decode((string) $request->query('settings', '{}'), true);
        $settings = PostsGrid::settings(is_array($raw) ? $raw : []);

        $cards = array_map(fn (array $card) => [
            'id'       => $card['id'],
            'title'    => $card['title'],
            'excerpt'  => $card['excerpt'],
            'date'     => $card['date']?->format('M j, Y'),
            'image'    => $card['image'] ? vela_image_url($card['image']->url, 640) : null,
            'category' => $card['category'],
            'author'   => $card['author'],
            'minutes'  => $card['minutes'],
        ], PostsGrid::cards(PostsGrid::posts($settings)));

        return response()->json(['cards' => $cards]);
    }

    /** Published posts by title, or the ones named by `ids` in that order. */
    public function search(Request $request)
    {
        $this->authorise();

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))));
        $query = trim((string) $request->query('q', ''));

        $posts = Content::query()
            ->where('status', 'published')
            ->with('media')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === [] && $query !== '', fn ($q) => $q->where('title', 'like', '%' . $query . '%'))
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->limit($ids !== [] ? 50 : self::SEARCH_LIMIT)
            ->get();

        if ($ids !== []) {
            $position = array_flip($ids);
            $posts = $posts->sortBy(fn ($p) => $position[$p->id] ?? PHP_INT_MAX)->values();
        }

        return response()->json(['results' => $posts->map(fn (Content $post) => [
            'id'    => $post->id,
            'title' => $post->title,
            'date'  => ($post->published_at ?? $post->created_at)?->format('M j, Y'),
            'image' => $post->main_image ? vela_image_url($post->main_image->url, 96) : null,
        ])->all()]);
    }

    private function authorise(): void
    {
        // Whoever can edit a page can see which posts it could list.
        abort_if(Gate::denies('page_access') && Gate::denies('config_access'), Response::HTTP_FORBIDDEN);
    }
}
