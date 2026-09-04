<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;

/**
 * What a link in the editor could point at.
 *
 * Every box in the editor that takes a link took a typed address and nothing
 * else, so linking to a page of your own meant knowing its slug by heart —
 * and getting it wrong produced a link that looked fine in the editor and
 * 404'd for a visitor. A page's title is what somebody remembers; its slug is
 * what the link needs. This turns the first into the second.
 *
 * Suggestions only. The box still takes anything typed into it: an address
 * outside the site, an anchor, a mailto — none of which this could ever list.
 */
class LinkSuggestController extends Controller
{
    /** How many of each kind to offer. Enough to choose from, few enough to read. */
    private const PER_KIND = 6;

    public function __invoke(Request $request)
    {
        // Whoever can open the page editor can see the titles of the things
        // they might link to — the same list the Pages screen shows them.
        abort_if(Gate::denies('page_access') && Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $query = trim((string) $request->query('q', ''));

        return response()->json(['results' => array_merge(
            $this->builtIn($query),
            $this->pages($query),
            $this->articles($query),
            $this->categories($query)
        )]);
    }

    /**
     * The addresses that are not content: the front page and the two listings.
     *
     * They have no row anywhere to be found by title, and they are among the
     * likeliest things to want a link to.
     */
    private function builtIn(string $query): array
    {
        $all = [
            ['label' => trans('vela::global.home'), 'url' => url('/'), 'kind' => 'built-in'],
            ['label' => trans('vela::global.articles'), 'url' => $this->safeRoute('vela.public.posts.index'), 'kind' => 'built-in'],
            ['label' => trans('vela::global.categories'), 'url' => $this->safeRoute('vela.public.categories.index'), 'kind' => 'built-in'],
        ];

        return array_values(array_filter($all, function ($item) use ($query) {
            return $item['url'] !== null
                && ($query === '' || mb_stripos($item['label'], $query) !== false);
        }));
    }

    private function pages(string $query): array
    {
        $pages = Page::query()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%' . $query . '%')
                ->orWhere('slug', 'like', '%' . $query . '%')))
            // Published first: a draft is a perfectly good thing to link to
            // ahead of publishing it, but it is not the usual answer, and it
            // is the reason the status is shown rather than the page hidden.
            ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
            ->orderBy('title')
            ->limit(self::PER_KIND)
            ->get(['id', 'title', 'slug', 'status']);

        return $pages->map(fn ($page) => [
            'label' => $page->title,
            'url' => url('/' . ltrim((string) $page->slug, '/')),
            'kind' => 'page',
            'note' => $page->status === 'published' ? null : $page->status,
        ])->all();
    }

    private function articles(string $query): array
    {
        $articles = Content::query()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%' . $query . '%')
                ->orWhere('slug', 'like', '%' . $query . '%')))
            ->latest('id')
            ->limit(self::PER_KIND)
            ->get(['id', 'title', 'slug']);

        return $articles->map(fn ($article) => [
            'label' => $article->title,
            'url' => $this->safeRoute('vela.public.posts.show', $article->slug),
            'kind' => 'article',
        ])->filter(fn ($item) => $item['url'] !== null)->values()->all();
    }

    private function categories(string $query): array
    {
        $categories = Category::query()
            // By name only, for the same reason: there is no slug to match on.
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%' . $query . '%'))
            ->orderBy('name')
            ->limit(self::PER_KIND)
            // Not 'slug': a category has no such column. Its address is its
            // name run through Str::slug, which the model now reads for us.
            ->get(['id', 'name']);

        return $categories->map(fn ($category) => [
            'label' => $category->name,
            'url' => $this->safeRoute('vela.public.categories.show', $category->slug),
            'kind' => 'topic',
        ])->filter(fn ($item) => $item['url'] !== null)->values()->all();
    }

    /**
     * A route that may not be registered on this install, as a URL or null.
     *
     * The public listings can be switched off, and a suggestion box is not
     * worth a 500.
     */
    private function safeRoute(string $name, ...$parameters): ?string
    {
        try {
            return route($name, ...$parameters);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
