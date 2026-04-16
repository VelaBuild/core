<?php

namespace VelaBuild\Core\Commands;

use VelaBuild\Core\Jobs\CreateContentImagesJob;
use VelaBuild\Core\Models\Content;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessContentImages extends Command
{
    protected $signature = 'vela:process-images {--limit=10 : Maximum number of content items to process per run}';

    protected $description = 'Process content items that need image generation';

    public function handle()
    {
        $limit = (int) $this->option('limit');

        $this->info("Processing content images (limit: {$limit})...");

        // Find content that has [IMAGE] tags OR needs a main image
        $contents = Content::where(function ($query) {
            $query->where('content', 'like', '%[IMAGE%')
                  ->orWhereDoesntHave('media', function ($q) {
                      $q->where('collection_name', 'main_image');
                  });
        })
            ->limit($limit)
            ->get();

        // Debug: Show what we found
        $this->info("Debug: Found {$contents->count()} contents with image blocks");
        foreach ($contents as $content) {
            $this->info("  - ID: {$content->id}, Title: {$content->title}, Status: {$content->status}");
        }

        if ($contents->isEmpty()) {
            $this->info('No content items found that need image processing.');
            return;
        }

        $this->info("Found {$contents->count()} content items to process.");

        foreach ($contents as $content) {
            $this->info("Dispatching image generation for: {$content->title}");

            // Dispatch the job
            CreateContentImagesJob::dispatch($content);

            Log::info('Dispatched CreateContentImagesJob', [
                'content_id' => $content->id,
                'content_title' => $content->title,
            ]);
        }

        $this->info("Dispatched {$contents->count()} image generation jobs.");
    }
}
