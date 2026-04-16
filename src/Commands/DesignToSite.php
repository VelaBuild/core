<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Services\AssetExtractorService;
use VelaBuild\Core\Services\FigmaExportService;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;

class DesignToSite extends Command
{
    protected $signature = 'vela:design-to-site
        {--url= : Target URL to screenshot (default: APP_URL)}
        {--design-path= : Path to design folder (default: storage/app/design/)}
        {--max-loops=5 : Maximum QA iterations}
        {--force : Overwrite existing site content}
        {--dry-run : Show build plan without executing}
        {--figma-url= : Figma file URL to export assets from}';

    protected $description = 'Build a site from design assets using AI with visual QA loop';

    private AiProviderManager $aiManager;
    private DesignBuilderService $builder;
    private ScreenshotService $screenshotService;

    public function __construct(
        AiProviderManager $aiManager,
        DesignBuilderService $builder,
        ScreenshotService $screenshotService
    ) {
        parent::__construct();
        $this->aiManager = $aiManager;
        $this->builder = $builder;
        $this->screenshotService = $screenshotService;
    }

    public function handle(): int
    {
        $lockPath = storage_path('app/.design-builder.lock');
        $fp = null;

        try {
            // Step 1: Resolve options
            $url = $this->option('url') ?: config('app.url');
            $designPath = $this->option('design-path') ?: storage_path('app/design');
            $maxLoops = max(1, min(20, (int) ($this->option('max-loops') ?? 5)));
            $force = (bool) $this->option('force');
            $dryRun = (bool) $this->option('dry-run');
            $figmaUrl = $this->option('figma-url') ?: null;

            // Step 2: Prerequisite validation
            if (!$this->aiManager->hasTextProvider()) {
                $this->error('No AI text provider configured. Set OPENAI_API_KEY, ANTHROPIC_API_KEY, or GEMINI_API_KEY in .env');
                return 1;
            }

            $textProvider = $this->aiManager->resolveTextProvider();
            if (!$textProvider->supportsVision()) {
                $this->error('The configured AI provider does not support vision. Configure a vision-capable provider.');
                return 1;
            }

            if (!$this->screenshotService->isAvailable()) {
                $this->error('Chrome/Chromium not found. Install chromium-browser or google-chrome for screenshot capture.');
                return 1;
            }

            try {
                $serverCheck = Http::timeout(5)->get($url);
            } catch (\Exception $e) {
                $this->error("No server detected at {$url}. Start your server with \"php artisan serve\" and try again.");
                return 1;
            }

            if ($serverCheck->failed()) {
                $this->error("No server detected at {$url}. Start your server with \"php artisan serve\" and try again.");
                return 1;
            }

            if ($figmaUrl) {
                $figmaToken = config('vela.ai.figma.access_token') ?: env('FIGMA_ACCESS_TOKEN');
                if (!$figmaToken) {
                    $this->error('FIGMA_ACCESS_TOKEN required for Figma export. Set it in .env');
                    return 1;
                }
            }

            // Step 3: Figma export
            if ($figmaUrl) {
                $count = app(FigmaExportService::class)->export($figmaUrl, $designPath);
                $this->info("Exported {$count} frames from Figma to {$designPath}");
            }

            // Step 4: Check design folder has files
            if (!is_dir($designPath)) {
                mkdir($designPath, 0755, true);
            }

            $supportedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'md', 'txt'];
            $designFiles = [];
            foreach (scandir($designPath) as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $supportedExtensions)) {
                    $designFiles[] = $file;
                }
            }

            if (empty($designFiles)) {
                $this->error("No design files found in {$designPath}. Add screenshots (.png/.jpg), logos (.svg), or instructions (.md).");
                return 1;
            }

            // Step 5: PSD/AI extraction
            app(AssetExtractorService::class)->extractAll($designPath);

            // Step 6: Overwrite safety check
            if (!$force) {
                $hasCustomizations = VelaConfig::where('key', 'like', 'css_%')->exists()
                    || Page::where('slug', '!=', 'home')->exists()
                    || Content::exists();

                if ($hasCustomizations) {
                    $this->warn('Existing site content/styling detected. This command will modify your site.');
                    if (!$this->confirm('Continue? Use --force to skip this prompt.')) {
                        return 0;
                    }
                }
            }

            // Step 7: Acquire file lock
            $fp = fopen($lockPath, 'c');
            if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
                $this->error('Another design builder instance is running.');
                return 1;
            }

            // Step 8: Generate context
            $this->builder->onProgress(fn($msg) => $this->line($msg));
            $context = $this->builder->generateContext($designPath);
            $this->info('Design context generated: ' . count($context['assets']) . ' assets, ' . count($context['instructions']) . ' instruction files');

            // Step 9: Dry-run exit point
            if ($dryRun) {
                $this->line(json_encode($context, JSON_PRETTY_PRINT));
                $this->info('Dry run complete. No changes made.');
                return 0;
            }

            // Step 10: Warn about data transmission
            $this->warn('Note: Screenshots of your site will be sent to the AI provider for visual comparison.');

            // Step 11: Build loop
            $this->info('Starting initial build...');
            $this->builder->runBuildLoop($context, $designPath, $url);

            // Step 12: QA loop
            $staleCount = 0;
            $previousAssessment = null;
            $loop = 1;

            for (; $loop <= $maxLoops; $loop++) {
                $this->info("QA Loop {$loop}/{$maxLoops}...");

                $screenshotPath = $designPath . '/loop_' . $loop . '_screenshot.png';
                $screenshotPath = $this->screenshotService->capture($url, $screenshotPath);

                // Validate screenshot
                if (file_exists($screenshotPath) && filesize($screenshotPath) < 1024) {
                    $this->warn('Screenshot appears small, retrying...');
                    $screenshotPath = $this->screenshotService->capture($url, $screenshotPath);
                    if (!file_exists($screenshotPath) || filesize($screenshotPath) < 1024) {
                        $this->error('Screenshot appears blank — check server and URL.');
                        break;
                    }
                }

                $assessment = $this->builder->runQaComparison($context, $screenshotPath, $designPath);

                // Save report
                $reportPath = $designPath . '/loop_' . $loop . '_report.md';
                file_put_contents($reportPath, $assessment['report']);

                $this->line($assessment['summary']);

                if ($assessment['passed']) {
                    $this->info('Design QA passed!');
                    break;
                }

                // Stale-loop detection
                if ($assessment['fixes'] === $previousAssessment) {
                    $staleCount++;
                    if ($staleCount >= 2) {
                        $this->warn('No meaningful improvement detected. Stopping.');
                        break;
                    }
                } else {
                    $staleCount = 0;
                }

                $this->builder->runFixLoop($assessment['fixes'], $context, $designPath, $url);
                $previousAssessment = $assessment['fixes'];

                $this->line("Tokens used this loop: input={$assessment['usage']['input']}, output={$assessment['usage']['output']}");
            }

            // Step 13: Summary output
            $this->info("Design builder complete. {$loop} QA loops executed.");
            $this->info("Screenshots and reports saved to: {$designPath}");

            return 0;

        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        } finally {
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }
}
