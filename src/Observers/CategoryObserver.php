<?php

namespace VelaBuild\Core\Observers;

use VelaBuild\Core\Models\Category;

class CategoryObserver
{
    /**
     * Handle the Category "created" event.
     */
    public function created(Category $category): void
    {
        if (config('vela.static.enabled', true)) {
            \VelaBuild\Core\Jobs\GenerateStaticFilesJob::dispatch('categories_index');
        }
    }

    /**
     * Handle the Category "updated" event.
     */
    public function updated(Category $category): void
    {
        if (config('vela.static.enabled', true)) {
            \VelaBuild\Core\Jobs\GenerateStaticFilesJob::dispatch('category', $category->id);
            \VelaBuild\Core\Jobs\GenerateStaticFilesJob::dispatch('categories_index');
        }
    }

    /**
     * Handle the Category "deleted" event.
     */
    public function deleted(Category $category): void
    {
        if (config('vela.static.enabled', true)) {
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->removeAll('categories', \Illuminate\Support\Str::slug($category->name));
            \VelaBuild\Core\Jobs\GenerateStaticFilesJob::dispatch('categories_index');
        }
    }

    /**
     * Handle the Category "restored" event.
     */
    public function restored(Category $category): void
    {
        //
    }

    /**
     * Handle the Category "force deleted" event.
     */
    public function forceDeleted(Category $category): void
    {
        //
    }
}
