<?php

namespace VelaBuild\Core\Http\Controllers\Api;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\BrowserRenderingService;

class ContentApiController extends Controller
{
    /**
     * List published pages.
     */
    public function pages(): JsonResponse
    {
        $this->checkEnabled();

        $pages = Page::where('status', 'published')
            ->select('id', 'title', 'slug', 'locale', 'meta_title', 'meta_description', 'updated_at')
            ->orderBy('title')
            ->paginate(50);

        return response()->json($pages);
    }

    /**
     * Get a single published page by slug.
     */
    public function page(string $slug): JsonResponse
    {
        $this->checkEnabled();

        $page = Page::where('slug', $slug)
            ->where('status', 'published')
            ->with('rows.blocks')
            ->first();

        if (! $page) {
            return response()->json([
                'error' => 'Not found',
                'message' => 'Page not found',
            ], 404);
        }

        return response()->json(['data' => $page]);
    }

    /**
     * List published posts with optional category and search filters.
     */
    public function posts(Request $request): JsonResponse
    {
        $this->checkEnabled();

        $query = Content::where('status', 'published')
            ->where('type', 'post')
            ->select('id', 'title', 'slug', 'excerpt', 'locale', 'published_at', 'updated_at')
            ->with('categories:id,name,slug')
            ->orderByDesc('published_at');

        if ($request->filled('category')) {
            $categorySlug = $request->input('category');
            $query->whereHas('categories', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where('title', 'like', '%' . $term . '%');
        }

        $posts = $query->paginate(50);

        return response()->json($posts);
    }

    /**
     * Get a single published post by slug.
     */
    public function post(string $slug): JsonResponse
    {
        $this->checkEnabled();

        $post = Content::where('slug', $slug)
            ->where('status', 'published')
            ->where('type', 'post')
            ->with('categories')
            ->first();

        if (! $post) {
            return response()->json([
                'error' => 'Not found',
                'message' => 'Post not found',
            ], 404);
        }

        return response()->json(['data' => $post]);
    }

    /**
     * List all categories with published content counts.
     */
    public function categories(): JsonResponse
    {
        $this->checkEnabled();

        $categories = Category::select('id', 'name', 'slug', 'description')
            ->withCount(['contents' => function ($q) {
                $q->where('status', 'published');
            }])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * Search across pages and posts.
     */
    public function search(Request $request): JsonResponse
    {
        $this->checkEnabled();

        $q = trim($request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'The q parameter is required and must be at least 2 characters.',
            ], 422);
        }

        $likePattern = '%' . $q . '%';

        $pages = Page::where('status', 'published')
            ->where('title', 'like', $likePattern)
            ->select('id', 'title', 'slug', 'meta_description', 'updated_at')
            ->orderBy('title')
            ->limit(20)
            ->get()
            ->map(function ($page) {
                return [
                    'type' => 'page',
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'description' => $page->meta_description,
                    'updated_at' => $page->updated_at,
                ];
            });

        $posts = Content::where('status', 'published')
            ->where('type', 'post')
            ->where('title', 'like', $likePattern)
            ->select('id', 'title', 'slug', 'excerpt', 'published_at', 'updated_at')
            ->orderByDesc('published_at')
            ->limit(20)
            ->get()
            ->map(function ($post) {
                return [
                    'type' => 'post',
                    'id' => $post->id,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'description' => $post->excerpt,
                    'published_at' => $post->published_at,
                    'updated_at' => $post->updated_at,
                ];
            });

        return response()->json([
            'data' => $pages->concat($posts)->values(),
            'query' => $q,
        ]);
    }

    /**
     * Verify the public content API is enabled.
     *
     * Checks the database configuration first, falling back to the
     * VELA_PUBLIC_API environment variable. Throws a 403 abort if disabled
     * or if the database is unreachable.
     */
    /**
     * Render a page as screenshot or PDF via Cloudflare Browser Rendering.
     */
    public function render(Request $request): JsonResponse
    {
        $this->checkEnabled();

        $renderer = app(BrowserRenderingService::class);
        if (!$renderer->isConfigured()) {
            return response()->json([
                'error' => 'Not configured',
                'message' => 'Browser rendering is not available',
            ], 503);
        }

        $request->validate([
            'url' => 'required|url',
            'format' => 'nullable|in:screenshot,pdf',
            'width' => 'nullable|integer|min:320|max:3840',
            'height' => 'nullable|integer|min:200|max:2160',
            'full_page' => 'nullable|boolean',
        ]);

        $targetUrl = $request->input('url');
        $format = $request->input('format', 'screenshot');
        $options = [
            'width' => $request->integer('width', 1280),
            'height' => $request->integer('height', 800),
            'full_page' => $request->boolean('full_page', false),
        ];

        if ($format === 'pdf') {
            $data = $renderer->pdf($targetUrl, $options);
            $mime = 'application/pdf';
        } else {
            $data = $renderer->screenshot($targetUrl, $options);
            $mime = 'image/png';
        }

        if ($data === null) {
            return response()->json(['error' => 'Rendering failed'], 502);
        }

        return response()->json([
            'data' => $data,
            'format' => $format,
            'mime' => $mime,
            'encoding' => 'base64',
        ]);
    }

    private function checkEnabled(): void
    {
        try {
            $dbValue = VelaConfig::where('key', 'public_api_enabled')->value('value');

            if ($dbValue !== null) {
                if ($dbValue === '0') {
                    abort(response()->json([
                        'error' => 'Forbidden',
                        'message' => 'Content API is disabled',
                    ], 403));
                }

                return;
            }
        } catch (QueryException | \PDOException $e) {
            abort(response()->json([
                'error' => 'Service unavailable',
                'message' => 'Content API is not available',
            ], 403));
        }

        if (! config('vela.public_api_enabled', true)) {
            abort(response()->json([
                'error' => 'Forbidden',
                'message' => 'Content API is disabled',
            ], 403));
        }
    }
}
