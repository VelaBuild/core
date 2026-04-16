<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use VelaBuild\Core\Services\StaticSiteGenerator;

class GenerateTranslationSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;

    protected string $type;
    protected string $slug;
    protected string $locale;

    public function __construct(string $type, string $slug, string $locale)
    {
        $this->type = $type;
        $this->slug = $slug;
        $this->locale = $locale;
    }

    public function handle(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale($this->locale);

        try {
            $generator = app(StaticSiteGenerator::class);

            switch ($this->type) {
                case 'page':
                    $page = \VelaBuild\Core\Models\Page::where('slug', $this->slug)
                        ->where('status', 'published')
                        ->with(['rows.blocks'])
                        ->first();
                    if ($page) {
                        $html = view(vela_template_view('page'), compact('page'))->render();
                        $html = preg_replace('/<meta name="csrf-token" content="[^"]*"/',
                        $generator->generateTranslationSnapshot($this->type, $this->slug, $this->locale, $html);
                    }
                    break;
                case 'post':
                    $post = \VelaBuild\Core\Models\Content::where('slug', $this->slug)
                        ->where('status', 'published')
                        ->where('type', 'post')
                        ->first();
                    if ($post) {
                        $relatedPosts = \VelaBuild\Core\Models\Content::where('status', 'published')
                            ->where('type', 'post')
                            ->where('id', '!=', $post->id)
                            ->limit(3)->get();
                        $categories = \VelaBuild\Core\Models\Category::orderBy('order_by')->get();
                        $metaTags = \VelaBuild\Core\Helpers\MetaTagsHelper::forContent($post);
                        $html = view(vela_template_view('article'), compact('post', 'relatedPosts', 'categories', 'metaTags'))->render();
                        $html = preg_replace('/<meta name="csrf-token" content="[^"]*"/',
                        $generator->generateTranslationSnapshot($this->type, $this->slug, $this->locale, $html);
                    }
                    break;
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
