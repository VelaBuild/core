<?php

namespace VelaBuild\Core\Commands;

use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class VelaInstall extends Command
{
    protected $signature = 'vela:install
                            {--admin-name=Admin : Admin user name}
                            {--admin-email= : Admin email}
                            {--admin-password= : Admin password}
                            {--queue= : Queue driver: sync, database, or redis}';

    protected $description = 'Install and configure the Vela CMS package';

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Vela CMS Installer');
        $this->newLine();

        // 1. Publish config & assets
        $this->step('Publishing configuration and assets...');
        $this->callSilently('vendor:publish', ['--tag' => 'vela-config', '--force' => true]);
        $this->callSilently('vendor:publish', ['--tag' => 'vela-assets', '--force' => true]);
        $this->components->twoColumnDetail('Config & assets', '<fg=green>published</>');

        // 2. Queue setup
        $this->configureQueue();

        // 3. Run migrations
        $this->step('Running database migrations...');
        $this->callSilently('migrate', ['--force' => true]);
        $this->components->twoColumnDetail('Migrations', '<fg=green>complete</>');

        // 4. Seed permissions & roles
        $this->seedPermissionsAndRoles();

        // 5. Create admin user
        $this->createAdminUser();

        // 6. Install default homepage if none exists
        $this->installDefaultHomepage();

        // 7. Static files in git
        $this->configureStaticTracking();

        // 8. Mark as installed
        $this->markInstalled();

        // 9. Done
        $this->newLine();
        $this->components->info('Vela CMS installed successfully!');
        $this->newLine();

        $authPrefix = config('vela.auth_prefix', 'vela');
        $adminPrefix = config('vela.admin_prefix', 'admin');

        $this->components->twoColumnDetail('Login URL', url($authPrefix . '/login'));
        $this->components->twoColumnDetail('Admin panel', url($adminPrefix));
        $this->newLine();

        return self::SUCCESS;
    }

    protected function configureQueue(): void
    {
        $this->step('Configuring queue...');

        if (!$this->input->isInteractive()) {
            $driver = $this->option('queue') ?: 'database';
        } else {
            $driver = $this->choice(
                'Which queue driver would you like to use?',
                ['sync' => 'Sync (no queue, runs inline)', 'database' => 'Database (recommended)', 'redis' => 'Redis'],
                'database'
            );
        }

        if ($driver === 'database') {
            $this->publishQueueTables();
            $this->components->twoColumnDetail('Queue driver', '<fg=green>database</> (tables created)');
        } elseif ($driver === 'redis') {
            $this->components->twoColumnDetail('Queue driver', '<fg=green>redis</>');
            if (!class_exists('Redis') && !class_exists('Predis\Client')) {
                $this->components->warn('Make sure phpredis or predis/predis is installed.');
            }
        } else {
            $this->components->twoColumnDetail('Queue driver', '<fg=yellow>sync</> (jobs run inline)');
        }

        $this->updateEnvValue('QUEUE_CONNECTION', $driver);
    }

    protected function publishQueueTables(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function ($table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function ($table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }
    }

    protected function seedPermissionsAndRoles(): void
    {
        $this->step('Seeding permissions and roles...');

        $this->callSilently('db:seed', [
            '--class' => \VelaBuild\Core\Database\Seeders\VelaDatabaseSeeder::class,
            '--force' => true,
        ]);

        $this->components->twoColumnDetail('Permissions & Roles', '<fg=green>seeded</>');
    }

    protected function createAdminUser(): void
    {
        $this->step('Creating admin user...');

        if (!$this->input->isInteractive()) {
            $name = $this->option('admin-name');
            $email = $this->option('admin-email') ?: 'admin@example.com';
            $password = $this->option('admin-password') ?: 'password';
        } else {
            $name = $this->ask('Admin name', 'Admin');
            $email = $this->ask('Admin email');
            $password = $this->secret('Admin password');
        }

        $existing = VelaUser::where('email', $email)->first();
        if ($existing) {
            $this->components->twoColumnDetail('Admin user', '<fg=yellow>already exists (' . $email . ')</>');
            return;
        }

        $user = VelaUser::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(1);

        $this->components->twoColumnDetail('Admin user', '<fg=green>' . $email . '</>');
    }

    protected function updateEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);

        if (preg_match("/^{$key}=.*/m", $contents)) {
            $contents = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $contents);
        } else {
            $contents .= "\n{$key}={$value}\n";
        }

        file_put_contents($envPath, $contents);
    }

    protected function installDefaultHomepage(): void
    {
        // Only install if no home page exists yet
        if (Page::where('slug', 'home')->exists()) {
            return;
        }

        $activeTemplate = config('vela.template.active', 'default');
        $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();
        $templateDef = $templates[$activeTemplate] ?? null;

        if (! $templateDef || ! $templateDef['path']) {
            return;
        }

        $jsonPath = $templateDef['path'] . '/home-template.json';
        if (! file_exists($jsonPath)) {
            return;
        }

        $rowsData = json_decode(file_get_contents($jsonPath), true);
        if (! is_array($rowsData)) {
            return;
        }

        $this->step('Installing default homepage...');

        DB::transaction(function () use ($rowsData) {
            $page = Page::create([
                'title'        => 'Home',
                'slug'         => 'home',
                'locale'       => config('vela.primary_language', 'en'),
                'status'       => 'published',
                'order_column' => 0,
            ]);

            foreach ($rowsData as $rowOrder => $rowData) {
                $pageRow = PageRow::create([
                    'page_id'          => $page->id,
                    'name'             => $rowData['name'] ?? null,
                    'css_class'        => $rowData['css_class'] ?? null,
                    'background_color' => $rowData['background_color'] ?? null,
                    'background_image' => $rowData['background_image'] ?? null,
                    'order_column'     => $rowData['order'] ?? $rowOrder,
                ]);

                foreach ($rowData['blocks'] ?? [] as $blockOrder => $blockData) {
                    $pageRow->blocks()->create([
                        'column_index'     => $blockData['column_index'] ?? 0,
                        'column_width'     => $blockData['column_width'] ?? 12,
                        'order_column'     => $blockData['order'] ?? $blockOrder,
                        'type'             => $blockData['type'],
                        'content'          => $blockData['content'] ?? null,
                        'settings'         => $blockData['settings'] ?? null,
                        'background_color' => $blockData['background_color'] ?? null,
                        'background_image' => $blockData['background_image'] ?? null,
                    ]);
                }
            }
        });

        $this->components->twoColumnDetail('Homepage', '<fg=green>installed from ' . config('vela.template.active', 'default') . ' template</>');
    }

    protected function configureStaticTracking(): void
    {
        $gitignore = base_path('.gitignore');
        if (!file_exists($gitignore)) {
            return;
        }

        $contents = file_get_contents($gitignore);
        if (!str_contains($contents, '/resources/static/')) {
            return;
        }

        $this->step('Static file tracking...');

        if (!$this->input->isInteractive()) {
            $track = true;
        } else {
            $track = $this->confirm(
                'Track static HTML cache in git? (recommended for deployment/backup)',
                true
            );
        }

        if ($track) {
            // Remove the static ignore lines
            $lines = explode("\n", $contents);
            $lines = array_filter($lines, function ($line) {
                $trimmed = trim($line);
                return !str_starts_with($trimmed, '/resources/static/')
                    && $trimmed !== '# Static cache (remove this line after vela:install for deployment tracking)';
            });
            file_put_contents($gitignore, implode("\n", $lines));
            $this->components->twoColumnDetail('Static files', '<fg=green>tracked in git</>');
        } else {
            $this->components->twoColumnDetail('Static files', '<fg=yellow>gitignored</>');
        }
    }

    protected function markInstalled(): void
    {
        // Detach from the Vela CMS starter repo — this is the user's project now
        $this->detachStarterOrigin();

        // Create a marker file so install.php knows the site is installed
        $marker = storage_path('vela_installed');
        if (!file_exists($marker)) {
            file_put_contents($marker, date('Y-m-d H:i:s'));
        }
    }

    protected function detachStarterOrigin(): void
    {
        $starterOrigins = [
            'git@github.com:VelaBuild/cms.git',
            'https://github.com/VelaBuild/cms.git',
            'git@github.com:velabuild/cms.git',
            'https://github.com/velabuild/cms.git',
        ];

        $originUrl = trim(shell_exec('cd ' . escapeshellarg(base_path()) . ' && git remote get-url origin 2>/dev/null') ?? '');

        if (in_array($originUrl, $starterOrigins, true) || in_array(rtrim($originUrl, '.git') . '.git', $starterOrigins, true)) {
            shell_exec('cd ' . escapeshellarg(base_path()) . ' && git remote remove origin 2>/dev/null');
            $this->components->twoColumnDetail('Git origin', '<fg=green>starter repo detached</>');
        }
    }

    protected function step(string $message): void
    {
        $this->newLine();
        $this->line("  <fg=blue>→</> {$message}");
    }
}
