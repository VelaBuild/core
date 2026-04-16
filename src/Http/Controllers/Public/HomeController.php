<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Helpers\MetaTagsHelper;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        // Check for a PageBuilder homepage
        $homePage = Page::where('slug', 'home')
            ->where('status', 'published')
            ->with(['rows.blocks'])
            ->first();

        if ($homePage) {
            $page = $homePage;
            $response = view(vela_template_view('page'), compact('page'));
            $this->writeStaticCacheIfMissing($response->render());
            return $response;
        }

        // Check if a home template exists (host or package)
        $homeView = vela_template_view('home');
        $isWelcomeFallback = ($homeView === 'vela::public.home');

        if (!$isWelcomeFallback) {
            $latestPosts = Content::where('status', 'published')
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->limit(6)
                ->get();

            $categories = Category::orderBy('order_by', 'asc')
                ->orderBy('name', 'asc')
                ->get();

            $featuredPosts = Content::where('status', 'published')
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->limit(3)
                ->get();

            $metaTags = MetaTagsHelper::forHome();

            $response = view($homeView, compact('latestPosts', 'categories', 'featuredPosts', 'metaTags'));
            $this->writeStaticCacheIfMissing($response->render());
            return $response;
        }

        // Fallback: welcome page inside theme layout
        $response = view('vela::public.welcome');
        $this->writeStaticCacheIfMissing($response->render());
        return $response;
    }

    private function writeStaticCacheIfMissing(string $html): void
    {
        if (!config('vela.static.enabled', true)) {
            return;
        }

        $path = config('vela.static.path', resource_path('static')) . '/home/index.html';

        // Only write if the file doesn't exist
        if (is_file($path)) {
            return;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $path . '.tmp';
        file_put_contents($tmp, $html);
        rename($tmp, $path);
    }
}
