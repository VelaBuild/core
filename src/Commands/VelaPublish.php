<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;

class VelaPublish extends Command
{
    protected $signature = 'vela:publish {--force : Overwrite existing views}';

    protected $description = 'Publish Vela CMS views';

    public function handle(): int
    {
        $params = ['--tag' => 'vela-views'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);

        $this->info('Vela views published successfully.');

        return self::SUCCESS;
    }
}
