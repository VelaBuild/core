<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use VelaBuild\Core\Models\VelaConfig;

class AppInit extends Command
{
    protected $signature = 'vela:app-init
        {--name= : App display name (defaults to PWA name or site name)}
        {--app-id= : App bundle identifier e.g. com.example.myapp}
        {--force : Overwrite existing Capacitor project}
        {--dry-run : Show what would be generated without creating files}';

    protected $description = 'Initialize a Capacitor project for native app builds';

    public function handle(): int
    {
        // 1. Check Node.js
        $nodeResult = Process::run('node --version');
        if ($nodeResult->exitCode() !== 0) {
            $this->error('Node.js is not installed. Install it from https://nodejs.org/');
            return 1;
        }

        $nodeVersion = trim($nodeResult->output());
        if (preg_match('/v?(\d+)\./', $nodeVersion, $m)) {
            $nodeMajor = (int) $m[1];
            if ($nodeMajor < 18) {
                $this->error('Node.js 18+ is required. Current version: ' . $nodeVersion);
                return 1;
            }
        }

        // 2. Check npm
        $npmResult = Process::run('npm --version');
        if ($npmResult->exitCode() !== 0) {
            $this->error('npm is not installed.');
            return 1;
        }

        // 3. Check existing project
        if (is_dir(base_path('capacitor'))) {
            if (!$this->option('force')) {
                $this->error('Capacitor project already exists. Use --force to overwrite.');
                return 1;
            }
            File::deleteDirectory(base_path('capacitor'));
        }

        // 4. Read PWA config with flag overrides
        $appName = $this->option('name')
            ?? VelaConfig::where('key', 'app_name')->value('value')
            ?? VelaConfig::where('key', 'pwa_name')->value('value')
            ?? VelaConfig::where('key', 'site_name')->value('value')
            ?? config('app.name');

        $appId = $this->option('app-id') ?? 'com.example.app';

        $themeColor = VelaConfig::where('key', 'pwa_theme_color')->value('value') ?? '#1f2937';
        $backgroundColor = VelaConfig::where('key', 'pwa_background_color')->value('value') ?? '#ffffff';

        // 5. Validate app-id
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*(\.[a-zA-Z][a-zA-Z0-9]*){2,}$/', $appId)) {
            $this->error('Invalid app ID format. Use reverse domain notation: com.example.myapp');
            return 1;
        }

        // 6. Validate APP_URL
        $appUrl = config('app.url', '');
        if (preg_match('/(localhost|127\.0\.0\.1)/i', $appUrl)) {
            $this->warn('APP_URL points to localhost — the app will not work on a real device.');
        }
        if (!str_starts_with($appUrl, 'https://')) {
            $this->warn('APP_URL is not HTTPS — iOS requires HTTPS for webviews.');
        }

        // 7. Dry run
        if ($this->option('dry-run')) {
            $this->components->info('Dry run — no files will be created.');
            $this->line('  Would create: ' . base_path('capacitor/'));
            $this->line('  App ID:       ' . $appId);
            $this->line('  App Name:     ' . $appName);
            $this->line('  App URL:      ' . $appUrl);
            $this->line('  Would install: @capacitor/core @capacitor/cli @capacitor/android @capacitor/ios');
            $this->line('  Would add platforms: android, ios');
            return 0;
        }

        // 8. Create /capacitor/ directory
        mkdir(base_path('capacitor'), 0755, true);

        // 9. Run npm init
        $this->step('Running npm init...');
        $initResult = Process::path(base_path('capacitor'))->timeout(60)->run('npm init -y');
        if ($initResult->exitCode() !== 0) {
            $this->error('npm init failed:');
            $this->line($initResult->errorOutput());
            return 1;
        }

        // 10. Run npm install
        $this->step('Installing Capacitor packages...');
        $installResult = Process::path(base_path('capacitor'))->timeout(120)->run('npm install @capacitor/core @capacitor/cli @capacitor/android @capacitor/ios');
        if ($installResult->exitCode() !== 0) {
            $this->error('npm install failed:');
            $this->line($installResult->errorOutput());
            return 1;
        }

        // 11. Generate capacitor.config.ts (atomic write)
        $this->step('Generating capacitor.config.ts...');
        $configContent = <<<TS
import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: {$this->jsonStr($appId)},
  appName: {$this->jsonStr($appName)},
  webDir: 'www',
  server: {
    url: {$this->jsonStr($appUrl)},
    cleartext: true
  }
};

export default config;
TS;

        $tmpPath = base_path('capacitor/capacitor.config.ts.tmp');
        $finalPath = base_path('capacitor/capacitor.config.ts');
        file_put_contents($tmpPath, $configContent);
        rename($tmpPath, $finalPath);

        // 12. Create www/ directory with placeholder index.html
        mkdir(base_path('capacitor/www'), 0755, true);
        file_put_contents(base_path('capacitor/www/index.html'), '<!DOCTYPE html><html><body><p>Loading...</p></body></html>');

        // 13. Add platforms
        $this->step('Adding Android platform...');
        $androidResult = Process::path(base_path('capacitor'))->timeout(120)->run('npx cap add android');
        if ($androidResult->exitCode() !== 0) {
            $this->error('Failed to add Android platform:');
            $this->line($androidResult->errorOutput());
            return 1;
        }

        $this->step('Adding iOS platform...');
        $iosResult = Process::path(base_path('capacitor'))->timeout(120)->run('npx cap add ios');
        if ($iosResult->exitCode() !== 0) {
            if (PHP_OS_FAMILY === 'Linux') {
                $this->warn('Could not add iOS platform (expected on Linux — iOS builds require macOS with Xcode).');
            } else {
                $this->error('Failed to add iOS platform:');
                $this->line($iosResult->errorOutput());
                return 1;
            }
        }

        // 14. Update .gitignore
        $this->updateGitignore();

        // 15. Output JSON summary
        $this->newLine();
        $platforms = ['android'];
        if ($iosResult->exitCode() === 0) {
            $platforms[] = 'ios';
        }
        $this->line(json_encode([
            'app_id'   => $appId,
            'app_name' => $appName,
            'url'      => $appUrl,
            'platforms' => $platforms,
        ]));

        $this->newLine();
        $this->components->info('Capacitor project initialized successfully!');

        return Command::SUCCESS;
    }

    private function jsonStr(string $value): string
    {
        return json_encode($value);
    }

    private function updateGitignore(): void
    {
        $gitignorePath = base_path('.gitignore');
        if (!file_exists($gitignorePath)) {
            return;
        }

        $contents = file_get_contents($gitignorePath);

        $entriesToAdd = [
            '/capacitor/node_modules/',
            '/capacitor/android/app/build/',
            '/capacitor/ios/App/Pods/',
        ];

        $missing = [];
        foreach ($entriesToAdd as $entry) {
            if (!str_contains($contents, $entry)) {
                $missing[] = $entry;
            }
        }

        if (empty($missing)) {
            return;
        }

        $append = "\n# Capacitor (generated by vela:app-init)\n" . implode("\n", $missing) . "\n";
        file_put_contents($gitignorePath, $contents . $append);
    }

    private function step(string $message): void
    {
        $this->newLine();
        $this->line("  <fg=blue>→</> {$message}");
    }
}
