<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Gate;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\StaticSiteGenerator;

class CacheController extends Controller
{
    public function clear()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $generator = app(StaticSiteGenerator::class);
        $staticPath = config('vela.static.path', resource_path('static'));

        // Clear all static HTML files
        foreach (['home', 'posts', 'categories', 'pages'] as $dir) {
            $path = $staticPath.'/'.$dir;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            }
        }

        // Clear view cache
        $viewPath = config('view.compiled', storage_path('framework/views'));
        if (is_dir($viewPath)) {
            foreach (glob($viewPath.'/*.php') as $file) {
                @unlink($file);
            }
        }

        // Clear config cache
        Artisan::call('config:clear');

        // Clear route cache
        Artisan::call('route:clear');

        // Clear PWA manifest cache
        $pwaDir = storage_path('app/pwa');
        if (is_dir($pwaDir)) {
            foreach (glob($pwaDir.'/*.json') as $file) {
                @unlink($file);
            }
        }

        // Clear image optimization cache
        $imageCachePath = config('vela.images.cache_path', storage_path('app/image-cache'));
        if (is_dir($imageCachePath)) {
            $this->deleteDirectory($imageCachePath);
        }

        // Regenerate all static files
        $generator->regenerateAll();

        return redirect()->back()->with('message', __('vela::global.all_caches_cleared'));
    }

    public function clearHome()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $generator = app(StaticSiteGenerator::class);
        $staticPath = config('vela.static.path', resource_path('static'));

        $homePath = $staticPath.'/home';
        if (is_dir($homePath)) {
            $this->deleteDirectory($homePath);
        }

        $generator->regenerateAll();

        return redirect()->back()->with('message', __('vela::global.home_cache_cleared'));
    }

    public function clearPages()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $generator = app(StaticSiteGenerator::class);
        $staticPath = config('vela.static.path', resource_path('static'));

        $pagesPath = $staticPath.'/pages';
        if (is_dir($pagesPath)) {
            $this->deleteDirectory($pagesPath);
        }

        $generator->regenerateAll();

        return redirect()->back()->with('message', __('vela::global.pages_cache_cleared'));
    }

    public function clearArticles()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $generator = app(StaticSiteGenerator::class);
        $staticPath = config('vela.static.path', resource_path('static'));

        foreach (['posts', 'categories'] as $dir) {
            $path = $staticPath.'/'.$dir;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            }
        }

        $generator->regenerateAll();

        return redirect()->back()->with('message', __('vela::global.articles_cache_cleared'));
    }

    public function clearImages()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $imageCachePath = config('vela.images.cache_path', storage_path('app/image-cache'));
        if (is_dir($imageCachePath)) {
            $this->deleteDirectory($imageCachePath);
        }

        return redirect()->back()->with('message', __('vela::global.image_cache_cleared'));
    }

    public function clearPwa()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $pwaDir = storage_path('app/pwa');
        if (is_dir($pwaDir)) {
            foreach (glob($pwaDir.'/*.json') as $file) {
                @unlink($file);
            }
        }

        return redirect()->back()->with('message', __('vela::global.pwa_cache_cleared'));
    }

    private function deleteDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }
}
