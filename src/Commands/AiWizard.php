<?php
namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AiProviderManager;

class AiWizard extends Command
{
    protected $signature = 'vela:wizard
                            {--skip= : Comma-separated steps to skip: template,colors,graphics,categories,content}';

    protected $description = 'Interactive AI-powered site setup wizard';

    private AiProviderManager $aiManager;

    public function __construct(AiProviderManager $aiManager)
    {
        parent::__construct();
        $this->aiManager = $aiManager;
    }

    public function handle(): int
    {
        $skip = array_filter(explode(',', $this->option('skip') ?? ''));

        $this->info('=== Vela AI Site Setup Wizard ===');
        $this->newLine();

        if (!$this->aiManager->hasTextProvider() && !$this->aiManager->hasImageProvider()) {
            $this->error('No AI providers configured. Set at least one API key in .env');
            return 1;
        }

        $steps = [
            'template' => 'Select and configure template',
            'colors' => 'Customize template colors',
            'graphics' => 'Generate logo and hero image',
            'categories' => 'Create content categories',
            'content' => 'Generate initial content',
        ];

        $totalSteps = count(array_diff_key($steps, array_flip($skip)));
        $currentStep = 0;

        // Step 1: Template selection
        if (!in_array('template', $skip)) {
            $currentStep++;
            $this->info("[{$currentStep}/{$totalSteps}] Select template");
            $template = $this->choice('Choose a template', ['default', 'minimal'], 'default');
            $this->info("Template set to: {$template}");
        }

        // Step 2: Colors
        if (!in_array('colors', $skip)) {
            $currentStep++;
            $this->info("[{$currentStep}/{$totalSteps}] Customize colors");
            $primaryColor = $this->ask('Primary color (hex)', '#0066cc');
            $this->call('vela:customize-template', [
                '--colors' => json_encode(['--primary' => $primaryColor]),
            ]);
        }

        // Step 3: Graphics
        if (!in_array('graphics', $skip)) {
            $currentStep++;
            $this->info("[{$currentStep}/{$totalSteps}] Generate graphics");
            if ($this->aiManager->hasImageProvider()) {
                $this->call('vela:setup-graphics', ['--force' => true]);
            } else {
                $this->warn('No image provider available. Skipping graphics.');
            }
        }

        // Step 4: Categories
        if (!in_array('categories', $skip)) {
            $currentStep++;
            $this->info("[{$currentStep}/{$totalSteps}] Create categories");
            $categoriesInput = $this->ask('Enter category names (comma-separated)', 'News, Tutorials, Reviews');
            $categories = array_map('trim', explode(',', $categoriesInput));
            foreach ($categories as $catName) {
                \VelaBuild\Core\Models\Category::firstOrCreate(['name' => $catName]);
                $this->line("  Created category: {$catName}");
            }
        }

        // Step 5: Content generation
        if (!in_array('content', $skip)) {
            $currentStep++;
            $this->info("[{$currentStep}/{$totalSteps}] Generate initial content");
            if ($this->aiManager->hasTextProvider()) {
                $count = (int) $this->ask('How many articles to generate?', '3');
                for ($i = 1; $i <= $count; $i++) {
                    $this->info("  Generating article {$i}/{$count}...");
                    $this->call('vela:create-content', [
                        '--prompt' => "Write an engaging introductory article for a new website",
                        '--with-images' => true,
                        '--status' => 'draft',
                    ]);
                }
            } else {
                $this->warn('No text provider available. Skipping content generation.');
            }
        }

        $this->newLine();
        $this->info('=== Wizard complete! ===');
        return 0;
    }
}
