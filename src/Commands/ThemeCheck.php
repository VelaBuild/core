<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Vela;

class ThemeCheck extends Command
{
    protected $signature = 'vela:theme-check
                            {--theme= : Specific theme to check (default: all)}
                            {--mode=static : Validation mode: static, render, or both}
                            {--fix : Auto-fix what can be fixed}
                            {--json : Output results as JSON}';

    protected $description = 'Validate themes against quality and standards requirements';

    public function __construct(private Vela $vela)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = $this->option('mode');
        $targetTheme = $this->option('theme');
        $outputJson = $this->option('json');
        $fix = $this->option('fix');

        if (!in_array($mode, ['static', 'render', 'both'])) {
            $this->error("Invalid mode '{$mode}'. Use: static, render, or both.");
            return 1;
        }

        $templates = $this->vela->templates()->all();
        if ($targetTheme) {
            if (!isset($templates[$targetTheme])) {
                $this->error("Theme '{$targetTheme}' not found.");
                return 1;
            }
            $templates = [$targetTheme => $templates[$targetTheme]];
        }

        $allResults = [];
        $hasErrors = false;

        foreach ($templates as $name => $config) {
            $results = [];

            if (in_array($mode, ['static', 'both'])) {
                $results['static'] = $this->runStaticChecks($name, $config, $fix);
            }

            if (in_array($mode, ['render', 'both'])) {
                $results['render'] = $this->runRenderChecks($name, $config);
            }

            $allResults[$name] = $results;

            foreach ($results as $checkType => $checks) {
                foreach ($checks as $check) {
                    if ($check['status'] === 'fail') {
                        $hasErrors = true;
                    }
                }
            }
        }

        if ($outputJson) {
            $this->line(json_encode($allResults, JSON_PRETTY_PRINT));
        } else {
            $this->renderResultsTable($allResults);
        }

        return $hasErrors ? 1 : 0;
    }

    private function runStaticChecks(string $name, array $config, bool $fix): array
    {
        $checks = [];
        $path = $config['path'] ?? null;

        if (!$path || !is_dir($path)) {
            $checks[] = ['check' => 'path_exists', 'status' => 'fail', 'message' => "Theme path does not exist: {$path}"];
            return $checks;
        }

        // 1. Required files
        $requiredFiles = ['layout.blade.php', 'home.blade.php', 'article.blade.php', 'articles.blade.php', 'page.blade.php', 'categories_index.blade.php', 'categories_show.blade.php', 'offline.blade.php'];
        foreach ($requiredFiles as $file) {
            $exists = file_exists($path . '/' . $file);
            $checks[] = [
                'check' => "file_exists:{$file}",
                'status' => $exists ? 'pass' : 'fail',
                'message' => $exists ? '' : "Missing required file: {$file}",
            ];
        }

        // 2. Layout includes shared partials
        $layoutPath = $path . '/layout.blade.php';
        if (file_exists($layoutPath)) {
            $layoutContent = file_get_contents($layoutPath);

            $requiredPartials = [
                'vela::templates._partials.meta-seo',
                'vela::templates._partials.meta-opengraph',
                'vela::templates._partials.meta-pwa',
                'vela::templates._partials.hreflang',
                'vela::templates._partials.analytics',
                'vela::templates._partials.custom-css',
                'vela::templates._partials.scripts-footer',
            ];

            foreach ($requiredPartials as $partial) {
                $included = str_contains($layoutContent, $partial);
                $checks[] = [
                    'check' => "partial_included:{$partial}",
                    'status' => $included ? 'pass' : 'fail',
                    'message' => $included ? '' : "Layout missing required partial: {$partial}",
                ];
            }

            // 3. Blade compilation
            try {
                app('blade.compiler')->compileString($layoutContent);
                $checks[] = ['check' => 'blade_compiles', 'status' => 'pass', 'message' => ''];
            } catch (\Exception $e) {
                $checks[] = ['check' => 'blade_compiles', 'status' => 'fail', 'message' => 'Blade compilation error: ' . $e->getMessage()];
            }

            // 4. Security scan
            $dangerousPatterns = ['eval(', 'system(', 'exec(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen('];
            foreach ($dangerousPatterns as $pattern) {
                if (stripos($layoutContent, $pattern) !== false) {
                    $checks[] = ['check' => "security:{$pattern}", 'status' => 'fail', 'message' => "Dangerous pattern found: {$pattern}"];
                }
            }

            // 5. HTML basics
            $hasLangAttr = (bool) preg_match('/<html[^>]+lang=/', $layoutContent);
            $checks[] = ['check' => 'html_lang_attribute', 'status' => $hasLangAttr ? 'pass' : 'fail', 'message' => $hasLangAttr ? '' : 'Missing lang attribute on <html>'];

            // 6. page-blocks.css loaded
            $hasPageBlocks = str_contains($layoutContent, 'page-blocks.css');
            $checks[] = ['check' => 'page_blocks_css', 'status' => $hasPageBlocks ? 'pass' : 'fail', 'message' => $hasPageBlocks ? '' : 'page-blocks.css not loaded'];

            // 7. Alpine.js loaded
            $hasAlpine = str_contains($layoutContent, 'alpinejs');
            $checks[] = ['check' => 'alpine_js', 'status' => $hasAlpine ? 'pass' : 'fail', 'message' => $hasAlpine ? '' : 'Alpine.js not loaded'];

            // 8. Responsive: media queries or viewport-relative units
            $hasResponsive = str_contains($layoutContent, '@media') || str_contains($layoutContent, 'max-width');
            // For themes with external CSS, check the CSS files too
            $themeCssDir = dirname($path, 3) . '/../../public/css/' . $name;
            if (is_dir($themeCssDir)) {
                foreach (glob($themeCssDir . '/*.css') as $cssFile) {
                    $cssContent = file_get_contents($cssFile);
                    if (str_contains($cssContent, '@media')) {
                        $hasResponsive = true;
                    }
                }
            }
            $checks[] = ['check' => 'responsive', 'status' => $hasResponsive ? 'pass' : 'warn', 'message' => $hasResponsive ? '' : 'No media queries found'];
        }

        // 9. Check views extend the layout
        $viewFiles = ['home.blade.php', 'article.blade.php', 'articles.blade.php', 'page.blade.php', 'categories_index.blade.php', 'categories_show.blade.php'];
        foreach ($viewFiles as $viewFile) {
            $viewPath = $path . '/' . $viewFile;
            if (file_exists($viewPath)) {
                $viewContent = file_get_contents($viewPath);
                $extendsLayout = str_contains($viewContent, '@extends(vela_template_layout())') || str_contains($viewContent, '@extends(template_layout())');
                $checks[] = [
                    'check' => "extends_layout:{$viewFile}",
                    'status' => $extendsLayout ? 'pass' : 'fail',
                    'message' => $extendsLayout ? '' : "{$viewFile} does not extend vela_template_layout()",
                ];
            }
        }

        return $checks;
    }

    private function runRenderChecks(string $name, array $config): array
    {
        $checks = [];

        // Temporarily set active template
        $originalTemplate = config('vela.template.active');
        config(['vela.template.active' => $name]);

        $routes = [
            'home' => '/',
            'articles' => '/articles',
        ];

        foreach ($routes as $label => $uri) {
            try {
                $response = $this->laravel->make(\Illuminate\Contracts\Http\Kernel::class)
                    ->handle(\Illuminate\Http\Request::create($uri, 'GET'));

                $statusCode = $response->getStatusCode();
                $checks[] = [
                    'check' => "http_status:{$label}",
                    'status' => $statusCode === 200 ? 'pass' : 'fail',
                    'message' => $statusCode === 200 ? '' : "HTTP {$statusCode} for {$uri}",
                ];

                if ($statusCode === 200) {
                    $content = $response->getContent();

                    // Check required elements
                    $requiredElements = ['<nav', '<main', '<footer'];
                    foreach ($requiredElements as $element) {
                        $found = stripos($content, $element) !== false;
                        $checks[] = [
                            'check' => "element:{$label}:{$element}",
                            'status' => $found ? 'pass' : 'fail',
                            'message' => $found ? '' : "Missing {$element} in {$label} page",
                        ];
                    }

                    // Check meta tags
                    $hasMeta = stripos($content, '<meta') !== false;
                    $checks[] = [
                        'check' => "meta_tags:{$label}",
                        'status' => $hasMeta ? 'pass' : 'fail',
                        'message' => $hasMeta ? '' : "No meta tags found in {$label} page",
                    ];
                }
            } catch (\Exception $e) {
                $checks[] = [
                    'check' => "render:{$label}",
                    'status' => 'fail',
                    'message' => "Render error for {$label}: " . $e->getMessage(),
                ];
            }
        }

        // Restore original template
        config(['vela.template.active' => $originalTemplate]);

        return $checks;
    }

    private function renderResultsTable(array $allResults): void
    {
        foreach ($allResults as $theme => $modes) {
            $this->newLine();
            $this->info("Theme: {$theme}");
            $this->line(str_repeat('─', 60));

            foreach ($modes as $mode => $checks) {
                $this->comment("  [{$mode}]");
                foreach ($checks as $check) {
                    $icon = match ($check['status']) {
                        'pass' => '<fg=green>PASS</>',
                        'fail' => '<fg=red>FAIL</>',
                        'warn' => '<fg=yellow>WARN</>',
                    };
                    $line = "    {$icon} {$check['check']}";
                    if ($check['message']) {
                        $line .= " — {$check['message']}";
                    }
                    $this->line($line);
                }
            }
        }

        $this->newLine();
    }
}
