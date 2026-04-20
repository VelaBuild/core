<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;

class SafeModeCommand extends Command
{
    protected $signature = 'vela:safe-mode {--disable : Remove safe mode flag}';

    protected $description = 'Enable or disable Vela safe mode';

    public function handle(): int
    {
        $flagFile = storage_path('app/.vela-safe-mode');

        if ($this->option('disable')) {
            @unlink($flagFile);
            $this->info('Safe mode disabled.');
        } else {
            file_put_contents($flagFile, '');
            $this->info('Safe mode enabled.');
        }

        return 0;
    }
}
