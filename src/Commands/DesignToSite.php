<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Services\DesignBuildStatus;
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

    /** Where a build puts what it makes, until someone says to use it. */
    public const PREVIEW_SLUG = 'design-preview';

    protected $description = 'Build a site from design assets using AI with visual QA loop';

    private AiProviderManager $aiManager;
    private DesignBuilderService $builder;
    private ScreenshotService $screenshotService;
    private ?DesignBuildStatus $status = null;

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

            // Everything printed from here is also recorded, so the admin page
            // that started this in a process of its own can follow along.
            if (!$dryRun) {
                $this->status = new DesignBuildStatus($designPath);
                $this->status->start($maxLoops);
            }

            // Step 2: Prerequisite validation
            if (!$this->aiManager->hasTextProvider()) {
                $this->error('No AI text provider configured. Set OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY in .env, or add a key under admin → Settings → AI.');
                return 1;
            }

            $this->builder->onProgress(fn($msg) => $this->line($msg));

            // Settle on a provider that both reads images and actually
            // answers, before anything is captured or sent anywhere. A key
            // that is present but out of credit used to get this far and then
            // fail silently on every call of the run.
            try {
                $this->builder->provider();
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                $this->status?->finish(false, $e->getMessage());
                return 1;
            }

            // Nothing here for the operator to install by hand: an existing
            // browser is used, a configured cloud service is used, and failing
            // both a browser is fetched into this site's own storage.
            try {
                $this->line($this->screenshotService->ensureCaptureRoute(fn($msg) => $this->line($msg)));
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                $this->status?->finish(false, $e->getMessage());
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
                // Leftovers from an earlier run are not a design to build from.
                if (preg_match('/^loop_\d+_(screenshot|report)\./i', $file)) {
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

            // Step 6: Overwrite safety check. A dry run changes nothing, so
            // there is nothing here to agree to.
            if (!$force && !$dryRun) {
                $hasCustomizations = VelaConfig::where('key', 'like', 'css_%')->exists()
                    || Page::where('slug', '!=', 'home')->exists()
                    || Content::exists();

                if ($hasCustomizations) {
                    $this->warn('Existing site content/styling detected. This command will modify your site.');
                    // With no terminal to answer, confirm() takes the default
                    // and declines — which used to end the run with a success
                    // code and no word about why nothing happened.
                    if (!$this->confirm('Continue? Use --force to skip this prompt.')) {
                        $this->line('Stopped. Nothing was changed. Re-run with --force to build anyway.');
                        return 1;
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

            // Step 11: Build loop. The theme in use is noted first: a theme
            // written during the build can fail in ways no amount of checking
            // the Blade will catch, and a site left answering 500 is worse
            // than one that never changed.
            $themeBefore = VelaConfig::where('key', 'active_template')->value('value');
            $themeSnapshot = $this->snapshotTheme($themeBefore);

            // The design is built onto a page of its own rather than over the
            // homepage. Two reasons. The site that is already there is an
            // anchor: shown a design it has never seen and a homepage full of
            // the last one, the model edits what it finds towards the design
            // instead of building what the design shows — a restaurant asked
            // to become a SaaS page stayed a restaurant. And a build used to
            // delete every row of a live homepage before it had produced
            // anything to replace it with, which is a poor trade to offer
            // someone who only wanted to see what a design would look like.
            // Rounds from the run before this one are not results of this
            // one. Left in place, a two-round build showed a third round from
            // an earlier design underneath its own, as though it had produced
            // it. The status file lives here too and is this run's, so only
            // the rounds go.
            foreach (glob($designPath . '/output/loop_*') ?: [] as $stale) {
                @unlink($stale);
            }

            $preview = $this->previewPage();
            $context['target_page'] = [
                'id' => $preview->id,
                'slug' => $preview->slug,
                'title' => $preview->title,
            ];

            // Unlisted, so it can be photographed over HTTP without a login
            // and without appearing anywhere a visitor would find it.
            $qaUrl = rtrim($url, '/') . '/' . $preview->slug;

            $this->info('Building onto "' . $preview->slug . '" — the homepage is left as it is.');
            $this->builder->runBuildLoop($context, $designPath, $url);

            if (!$this->siteStillWorks($url)) {
                $this->restoreTheme($themeBefore, $themeSnapshot);
                $this->error('The site stopped responding after the build, so the theme it was using has been put back. The reason is in storage/logs.');
                $this->status?->finish(false, 'The build left the site unable to render, and was rolled back.');

                return 1;
            }

            // Step 12: QA loop. Captures and reports go to a subfolder of
            // their own: written beside the design, a run's own screenshots
            // were read back in as designs the next time round.
            $outputPath = $designPath . '/output';
            if (!is_dir($outputPath)) {
                mkdir($outputPath, 0755, true);
            }

            $staleCount = 0;
            $previousAssessment = null;
            $loopsRun = 0;
            $rolledBack = false;

            for ($loop = 1; $loop <= $maxLoops; $loop++) {
                $loopsRun = $loop;
                $this->info("QA Loop {$loop}/{$maxLoops}...");

                // Photographing a page that is not there produces a picture of
                // an error, and the comparison reads it as a design that does
                // not match: it reported "diverges significantly" against a
                // 500 and spent a round of fixes on a site that was down.
                if (!$this->siteStillWorks($qaUrl)) {
                    $this->restoreTheme($themeBefore, $themeSnapshot);
                    $this->error('The page being built stopped rendering, so the theme has been put back as it was. The reason is in storage/logs.');
                    $rolledBack = true;
                    break;
                }

                $screenshotPath = $outputPath . '/loop_' . $loop . '_screenshot.png';
                $screenshotPath = $this->screenshotService->captureLiveFullPage($qaUrl, $screenshotPath);

                // Validate screenshot
                if (file_exists($screenshotPath) && filesize($screenshotPath) < 1024) {
                    $this->warn('Screenshot appears small, retrying...');
                    $screenshotPath = $this->screenshotService->captureLiveFullPage($qaUrl, $screenshotPath);
                    if (!file_exists($screenshotPath) || filesize($screenshotPath) < 1024) {
                        $this->error('Screenshot appears blank — check server and URL.');
                        break;
                    }
                }

                $assessment = $this->builder->runQaComparison($context, $screenshotPath, $designPath);

                // Save report
                $reportPath = $outputPath . '/loop_' . $loop . '_report.md';
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

                // A round of fixes can break the site as easily as the build
                // can. Stop at the first one that does, with the site working.
                if (!$this->siteStillWorks($url) || !$this->siteStillWorks($qaUrl)) {
                    $this->restoreTheme($themeBefore, $themeSnapshot);
                    $this->error('A round of fixes left the site unable to render, so the theme has been put back as it was.');

                    // A run that ends by undoing itself did not succeed, and
                    // the page watching it should not be told that it did.
                    $rolledBack = true;
                    break;
                }

                $previousAssessment = $assessment['fixes'];

                $this->line("Tokens used this loop: input={$assessment['usage']['input']}, output={$assessment['usage']['output']}");
            }

            // Step 13: Summary output
            $this->info("Design builder complete. {$loopsRun} QA loops executed.");
            $this->info("Screenshots and reports saved to: {$outputPath}");

            if ($rolledBack) {
                $this->status?->finish(false, 'A round of fixes left the site unable to render. The theme has been put back as it was, and the design was not applied.');

                return 1;
            }

            $this->status?->finish(true);

            return 0;

        } catch (\Exception $e) {
            $this->error($e->getMessage());
            $this->status?->finish(false, $e->getMessage());
            return 1;
        } finally {
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }

            // Any exit that did not say how it went — a missing design folder,
            // a server that is not up — would otherwise leave the page
            // watching a build that is no longer running.
            if (($this->status?->read()['state'] ?? null) === 'running') {
                $this->status->finish(false, 'The build stopped before it finished. See the messages above.');
            }
        }
    }

    /**
     * The page a build works on: a clean one, kept out of the way.
     *
     * Reused between runs rather than piling up, and emptied first — a build
     * asked to redo a design should start from the design, not from its own
     * previous attempt at it.
     */
    private function previewPage(): Page
    {
        $page = Page::withTrashed()->where('slug', self::PREVIEW_SLUG)->first();

        if ($page) {
            if ($page->trashed()) {
                $page->restore();
            }

            $page->rows()->each(fn ($row) => $row->delete());
            $page->update(['status' => 'unlisted']);

            return $page->fresh();
        }

        return Page::create([
            'title' => 'Design preview',
            'slug' => self::PREVIEW_SLUG,
            'locale' => config('app.locale', 'en'),
            'status' => 'unlisted',
            'meta_title' => 'Design preview',
            'meta_description' => 'A design being tried out. Not listed anywhere on the site.',
        ]);
    }

    /**
     * Whether the site can still render its own front page.
     */
    private function siteStillWorks(string $url): bool
    {
        try {
            return Http::timeout(20)->get($url)->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Put back the theme the site was using before the build.
     */
    /**
     * Keep a copy of a theme's files before anything is allowed to change
     * them.
     *
     * Noting which theme was in use is not enough to undo a build. A rebuild
     * works on the theme that is already there, so the name recorded and the
     * theme broken are the same one, and putting it back puts back exactly
     * what stopped the site: the rollback ran, said so, and left a site
     * answering 500. Only a copy of the files can undo that.
     */
    private function snapshotTheme(?string $theme): ?string
    {
        if (!$theme) {
            return null;
        }

        $source = resource_path('views/templates/' . $theme);

        if (!is_dir($source)) {
            // A theme that ships with Vela is not ours to change and cannot be
            // damaged by a build, so there is nothing to keep.
            return null;
        }

        $target = storage_path('app/vela-theme-backup/' . $theme);

        File::deleteDirectory($target);
        File::ensureDirectoryExists(dirname($target));

        return File::copyDirectory($source, $target) ? $target : null;
    }

    private function restoreTheme(?string $theme, ?string $snapshot = null): void
    {
        if ($theme === null) {
            return;
        }

        if ($snapshot && is_dir($snapshot)) {
            $target = resource_path('views/templates/' . $theme);
            File::deleteDirectory($target);
            File::copyDirectory($snapshot, $target);
        }

        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => $theme]);

        try {
            app(\VelaBuild\Core\Services\SiteConfigWriter::class)->write();
            \VelaBuild\Core\Services\SiteConfigWriter::apply();
        } catch (\Throwable $e) {
            $this->warn('The theme was restored in the database but the site config cache could not be rebuilt: ' . $e->getMessage());
        }
    }

    /**
     * Everything the command prints, recorded as it is printed.
     *
     * info(), warn() and error() all reach the terminal through line(), so
     * capturing it here catches the whole commentary without each call site
     * having to remember to report itself.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        parent::line($string, $style, $verbosity);

        $this->status?->line((string) $string);
    }
}
