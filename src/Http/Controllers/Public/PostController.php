<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Helpers\MetaTagsHelper;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        try {
            $posts = Content::where('status', 'published')
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->paginate(12);

            $categories = Category::orderBy('order_by', 'asc')
                ->orderBy('name', 'asc')
                ->get();
        } catch (\Illuminate\Database\QueryException | \PDOException $e) {
            abort(503, 'Content unavailable');
        }

        $metaTags = MetaTagsHelper::forArticlesIndex();

        return view(vela_template_view('articles'), compact('posts', 'categories', 'metaTags'));
    }

    public function show($slug)
    {
        try {
            $post = Content::where('slug', $slug)
                ->where('status', 'published')
                ->firstOrFail();

            $relatedPosts = Content::where('status', 'published')
                ->where('id', '!=', $post->id)
                ->whereHas('categories', function ($query) use ($post) {
                    $query->whereIn('vela_categories.id', $post->categories->pluck('id'));
                })
                ->limit(3)
                ->get();

            $categories = Category::orderBy('order_by', 'asc')
                ->orderBy('name', 'asc')
                ->get();
        } catch (\Illuminate\Database\QueryException | \PDOException $e) {
            abort(503, 'Content unavailable');
        }

        $metaTags = MetaTagsHelper::forContent($post);

        return view(vela_template_view('article'), compact('post', 'relatedPosts', 'categories', 'metaTags'));
    }
}
