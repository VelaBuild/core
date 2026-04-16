<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    public function handle(): void
    {
        $generator = app(StaticSiteGenerator::class);

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
