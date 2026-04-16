<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\PagespeedResult;
use VelaBuild\Core\Services\Tools\PagespeedService;

class RunPagespeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    public function __construct(
        private string $url
    ) {}

    public function handle(PagespeedService $service): void
    {
        // Check for recent result (< 5 min) to avoid duplicate scans
        $recent = PagespeedResult::where('url', $this->url)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recent) {
            Log::info('PageSpeed: skipping recent URL', ['url' => $this->url]);
            return;
        }

        $result = $service->analyze($this->url);

        if ($result) {
            PagespeedResult::create([
                'url' => $result['url'],
                'performance_score' => $result['performance_score'],
                'accessibility_score' => $result['accessibility_score'],
                'seo_score' => $result['seo_score'],
                'best_practices_score' => $result['best_practices_score'],
                'raw_data' => $result['raw_data'],
            ]);
            Log::info('PageSpeed: result saved', ['url' => $this->url]);
        }
    }
}
