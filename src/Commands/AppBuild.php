<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class AppBuild extends Command
{
    protected $signature = 'vela:app-build
        {--platform=both : Platform to build: android, ios, or both}
        {--build-type=release : Build type: debug or release}
        {--sdk-path= : Path to Android SDK or Xcode tools}
        {--force : Skip confirmation prompts}';

    protected $description = 'Build native app from Capacitor project';

    public function handle(): int
    {
        // 1. Check Capacitor project exists
        if (!is_dir(base_path('capacitor')) || !file_exists(base_path('capacitor/capacitor.config.ts'))) {
            $this->error('No Capacitor project found. Run vela:app-init first.');
            return 1;
        }

        // 2. Determine platform
        $platform = $this->option('platform');
        if (!in_array($platform, ['android', 'ios', 'both'])) {
            $this->error('Invalid platform. Use: android, ios, or both.');
            return 1;
        }

        $buildAndroid = in_array($platform, ['android', 'both']);
        $buildIos = in_array($platform, ['ios', 'both']);

        if ($buildIos && PHP_OS_FAMILY === 'Linux') {
            $this->warn('iOS builds require macOS with Xcode. Skipping iOS.');
            $buildIos = false;
            if (!$buildAndroid) {
                $this->error('No platforms to build on this OS.');
                return 1;
            }
        }

        $buildType = $this->option('build-type');
        if (!in_array($buildType, ['debug', 'release'])) {
            $this->error('Invalid build type. Use: debug or release.');
            return 1;
        }

        $sdkPath = $this->option('sdk-path');

        // 3. Check build tools
        if ($buildAndroid) {
            $androidSdk = $sdkPath
                ?? getenv('ANDROID_HOME')
                ?: getenv('ANDROID_SDK_ROOT')
                ?: null;

            if (!$androidSdk || !is_dir($androidSdk)) {
                $this->error(
                    'Android SDK not found. Set the ANDROID_SDK_ROOT environment variable or use --sdk-path.' . PHP_EOL .
                    'Install Android Studio from https://developer.android.com/studio to get the SDK.'
                );
                return 1;
            }

            $javaResult = Process::run('java --version');
            if ($javaResult->exitCode() !== 0) {
                $this->error('Java is not available. Install JDK 17+ and ensure JAVA_HOME is set.');
                return 1;
            }
        }

        if ($buildIos) {
            $xcodeResult = Process::run('xcodebuild -version');
            if ($xcodeResult->exitCode() !== 0) {
                $this->error('xcodebuild not found. Install Xcode from the Mac App Store.');
                return 1;
            }
        }

        // 4. Run npx cap sync
        $this->step('Syncing Capacitor...');
        $syncResult = Process::path(base_path('capacitor'))->timeout(120)->run('npx cap sync');
        if ($syncResult->exitCode() !== 0) {
            $this->error('npx cap sync failed:');
            $this->line($syncResult->errorOutput());
            return 1;
        }

        // 5. Build Android
        if ($buildAndroid) {
            $exitCode = $this->buildAndroid($buildType, $sdkPath);
            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        // 6. Build iOS
        if ($buildIos) {
            $exitCode = $this->buildIos($buildType, $sdkPath);
            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        $this->newLine();
        $this->components->info('Build completed successfully!');

        return Command::SUCCESS;
    }

    private function buildAndroid(string $buildType, ?string $sdkPath): int
    {
        $this->step('Building Android (' . $buildType . ')...');

        $env = [];
        if ($sdkPath) {
            $env['ANDROID_SDK_ROOT'] = $sdkPath;
        }

        $buildTask = $buildType === 'debug' ? 'assembleDebug' : 'assembleRelease';

        $process = Process::path(base_path('capacitor/android'))
            ->timeout(600)
            ->env($env)
            ->run('./gradlew ' . $buildTask);

        if ($process->exitCode() !== 0) {
            $output = $process->output() . $process->errorOutput();
            $this->outputAndroidGuidance($output);
            $this->error('Android build failed. Raw output:');
            $this->line($output);
            return 1;
        }

        $apkPath = 'capacitor/android/app/build/outputs/apk/' . $buildType . '/app-' . $buildType . '.apk';
        $this->components->info('Android build succeeded.');
        $this->line('  APK: ' . $apkPath);

        return 0;
    }

    private function buildIos(string $buildType, ?string $sdkPath): int
    {
        $this->step('Building iOS (' . $buildType . ')...');

        $configuration = ucfirst($buildType);

        $xcodebuildCmd = 'xcodebuild'
            . ' -workspace App.xcworkspace'
            . ' -scheme App'
            . ' -configuration ' . $configuration
            . ' -archivePath build/App.xcarchive'
            . ' archive';

        if ($sdkPath) {
            $xcodebuildCmd = 'DEVELOPER_DIR=' . escapeshellarg($sdkPath) . ' ' . $xcodebuildCmd;
        }

        $process = Process::path(base_path('capacitor/ios/App'))
            ->timeout(600)
            ->run($xcodebuildCmd);

        if ($process->exitCode() !== 0) {
            $output = $process->output() . $process->errorOutput();
            $this->outputIosGuidance($output);
            $this->error('iOS build failed. Raw output:');
            $this->line($output);
            return 1;
        }

        $archivePath = 'capacitor/ios/App/build/App.xcarchive';
        $this->components->info('iOS build succeeded.');
        $this->line('  Archive: ' . $archivePath);

        return 0;
    }

    private function outputAndroidGuidance(string $output): void
    {
        if (str_contains($output, 'SDK location not found')) {
            $this->warn('Fix: Set the ANDROID_SDK_ROOT environment variable to your Android SDK path.');
        } elseif (str_contains($output, 'JAVA_HOME is not set') || str_contains($output, 'No java')) {
            $this->warn('Fix: Install JDK 17+ and set the JAVA_HOME environment variable.');
        }
    }

    private function outputIosGuidance(string $output): void
    {
        if (str_contains($output, 'No signing certificate') || str_contains($output, 'code signing')) {
            $this->warn('Fix: Configure code signing in Xcode. Open the project in Xcode and set your team/certificate under Signing & Capabilities.');
        }
    }

    private function step(string $message): void
    {
        $this->newLine();
        $this->line("  <fg=blue>→</> {$message}");
    }
}
