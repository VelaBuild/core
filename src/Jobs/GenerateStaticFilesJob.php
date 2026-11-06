<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Services\Tools\CloudflareService;
use VelaBuild\Core\Services\StaticSiteGenerator;

class GenerateStaticFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    protected string $type;
    protected ?int $modelId;

    public function __construct(string $type, ?int $modelId = null)
    {
        $this->type = $type;
        $this->modelId = $modelId;
    }

    /**
     * Dispatch a regeneration that actually runs without requiring a
     * separately-managed queue worker.
     *
     * By default this fires AFTER the HTTP response is flushed, in the same
     * PHP process (dispatchAfterResponse) — so an admin edit refreshes the
     * static cache out of the box, even when QUEUE_CONNECTION has no worker
     * draining it. Sites that run a real worker/Horizon can set
     * static.regen_queue=true to push to the configured queue instead.
     */
    public static function dispatchFresh(string $type, ?int $modelId = null): void
    {
        if (config('vela.static.regen_queue', false)) {
            static::dispatch($type, $modelId);

            return;
        }

        // One save fires several observers (a builder save updates the page
        // and then touches it), each asking for the same rebuild. Run it once.
        $pending = self::pending();
        $key = $type . ':' . $modelId;
        if (isset($pending[$key])) {
            return;
        }
        $pending[$key] = true;
        static::dispatchAfterResponse($type, $modelId);
    }

    /** After-response rebuilds still to run for this app instance, by "type:id". */
    private static function pending(): \ArrayObject
    {
        if (!app()->bound('vela.static.pending')) {
            app()->instance('vela.static.pending', new \ArrayObject());
        }

        return app('vela.static.pending');
    }

    public function handle(): void
    {
        try {
            $this->regenerate();
        } finally {
            $pending = self::pending();
            unset($pending[$this->type . ':' . $this->modelId]);
        }

        // Clear the CDN once the last rebuild of this save is on disk.
        // Purging earlier (it used to wait in the queue, which hosted sites
        // only drain once a minute) either left visitors on the old page for
        // up to a minute, or let the CDN re-cache the old file for an hour.
        if (count($pending) === 0) {
            $this->purgeCdn();
        }
    }

    private function purgeCdn(): void
    {
        try {
            if (app(CloudflareService::class)->isConfigured()) {
                app()->call([PurgeCloudflareCacheJob::purgeTags(['site']), 'handle']);
            }
        } catch (\Throwable $e) {
            Log::warning('CDN purge after static rebuild failed', ['error' => $e->getMessage()]);
        }
    }

    private function regenerate(): void
    {
        $generator = app(StaticSiteGenerator::class);
        if (!$generator->writable()) {
            return;
        }

        switch ($this->type) {
            case 'all':
                $lock = Cache::lock('static-regen-all', 120);
                if ($lock->get()) {
                    try {
                        $generator->regenerateAll();
                    } finally {
                        $lock->release();
                    }
                }
                break;
            case 'page':
                $page = \VelaBuild\Core\Models\Page::find($this->modelId);
                if ($page) $generator->generatePage($page);
                break;
            case 'content':
                $content = \VelaBuild\Core\Models\Content::find($this->modelId);
                if ($content) $generator->generateContent($content);
                break;
            case 'home':
                $generator->generateHomePage();
                break;
            case 'posts_index':
                $generator->generatePostsIndex();
                break;
            case 'category':
                $category = \VelaBuild\Core\Models\Category::find($this->modelId);
                if ($category) $generator->generateCategoryPage($category);
                break;
            case 'categories_index':
                $generator->generateCategoriesIndex();
                break;
        }
    }
}
