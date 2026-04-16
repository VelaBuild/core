<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CleanupImageCache extends Command
{
    protected $signature = 'vela:cleanup-image-cache {--older-than=30d : Delete cached images older than this threshold}';
    protected $description = 'Clean up old cached optimized images';

    public function handle(): int
    {
        $threshold = $this->option('older-than');
        preg_match('/^(\d+)([dhm])$/', $threshold, $matches);

        if (empty($matches)) {
            $this->error('Invalid threshold format. Use format like 30d, 24h, etc.');
            return Command::FAILURE;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        $cutoff = match ($unit) {
            'd' => Carbon::now()->subDays($value),
            'h' => Carbon::now()->subHours($value),
            'm' => Carbon::now()->subMinutes($value),
        };

        $cachePath = config('vela.images.cache_path', storage_path('app/image-cache'));
        if (!is_dir($cachePath)) {
            $this->info('No image cache directory found.');
            return Command::SUCCESS;
        }

        $deleted = 0;
        $files = glob($cachePath . '/*');
        foreach ($files as $file) {
            if (is_file($file) && !str_ends_with($file, '.lock')) {
                if (filemtime($file) < $cutoff->timestamp) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        $this->info("Deleted {$deleted} cached images.");

        return Command::SUCCESS;
    }
}
