<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Helpers\MetaTagsHelper;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $metaTags = MetaTagsHelper::forCategoriesIndex();

        return view(vela_template_view('categories_index'), compact('categories', 'metaTags'));
    }

    public function show($slug)
    {
        // Get all categories and find the one that matches the slug
        $categories = Category::all();
        $category = null;

        foreach ($categories as $cat) {
            if (strtolower(\Illuminate\Support\Str::slug($cat->name)) === strtolower($slug)) {
                $category = $cat;
                break;
            }
        }

        if (!$category) {
            abort(404);
        }

        $posts = Content::where('status', 'published')
            ->whereHas('categories', function ($query) use ($category) {
                $query->where('vela_categories.id', $category->id);
            })
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->paginate(12);

        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $metaTags = MetaTagsHelper::forCategory($category);

        return view(vela_template_view('categories_show'), compact('category', 'posts', 'categories', 'metaTags'));
    }
}
