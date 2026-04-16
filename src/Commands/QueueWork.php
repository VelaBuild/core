<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;

class QueueWork extends Command
{
    protected $signature = 'vela:queue-work';

    protected $description = '';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        \Artisan::call('queue:work --stop-when-empty  --max-time=120');
        return Command::SUCCESS;
    }
}
