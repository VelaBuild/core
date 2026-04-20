<?php

namespace VelaBuild\Core\Services\Marketplace;

use VelaBuild\Core\Models\InstalledPackage;

class LicenseCacheWriter
{
    /**
     * Rebuild the license cache file from DB values.
     *
     * Called after any license or package status change to ensure
     * the cached PHP file reflects the latest DB state.
     */
    public function write(): void
    {
        $packages = InstalledPackage::with('license')->get();

        $data = [];
        foreach ($packages as $package) {
            $license = $package->license;
            $data[$package->composer_name] = [
                'valid' => $license ? $license->validation_status === 'valid' : false,
                'type' => $license?->type,
                'expires_at' => $license?->expires_at?->format('Y-m-d H:i:s'),
                'domain' => $license?->domain,
                'dev_domain' => $license?->dev_domain,
                'status' => $package->status,
            ];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $content = "<?php\n\nreturn json_decode('" . addcslashes($json, "'\\") . "', true);\n";

        $path = storage_path('app/vela-licenses.php');
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }

    /**
     * Read the license cache file.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = storage_path('app/vela-licenses.php');
        if (!file_exists($path)) {
            return [];
        }
        try {
            return include $path;
        } catch (\Throwable) {
            return [];
        }
    }
}
