<?php

if (!function_exists('vela_image')) {
    /**
     * Generate an optimized responsive <img> tag with WebP + srcset.
     *
     * @param string $src  Image URL, absolute path, or relative path from base_path()
     * @param string $alt  Alt text
     * @param array  $sizes Responsive widths
     * @param string $mode 'fit' or 'crop'
     * @param array  $attrs Extra HTML attributes (e.g. ['class' => 'my-class'])
     */
    function vela_image(string $src, string $alt = '', array $sizes = [400, 800, 1200], string $mode = 'fit', array $attrs = []): string
    {
        $relativePath = vela_image_relative_path($src);

        if (!config('vela.images.enabled', true) || $relativePath === null) {
            $extraAttrs = '';
            foreach ($attrs as $k => $v) {
                $extraAttrs .= ' ' . e($k) . '="' . e($v) . '"';
            }
            return '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy"' . $extraAttrs . '>';
        }

        $optimizer = app(\VelaBuild\Core\Services\ImageOptimizer::class);

        $srcsetParts = [];
        $urls = [];
        foreach ($sizes as $width) {
            $url = $optimizer->generateUrl($relativePath, $width, 0, $mode);
            $srcsetParts[] = $url . ' ' . $width . 'w';
            $urls[] = $url;
        }
        // Use middle size as default src (reasonable fallback for old browsers)
        $defaultUrl = $urls[(int) floor(count($urls) / 2)] ?? $urls[0];

        $srcset = implode(', ', $srcsetParts);

        $extraAttrs = '';
        foreach ($attrs as $k => $v) {
            $extraAttrs .= ' ' . e($k) . '="' . e($v) . '"';
        }

        return '<img src="' . $defaultUrl . '" srcset="' . $srcset . '"' . $extraAttrs . ' loading="lazy" alt="' . e($alt) . '">';
    }
}

if (!function_exists('vela_image_relative_path')) {
    /**
     * Convert a URL, absolute path, or relative path to a path relative to base_path().
     * Returns null if the path can't be resolved.
     */
    function vela_image_relative_path(string $src): ?string
    {
        $basePath = base_path();

        // Already a relative path (e.g. storage/app/public/1/file.jpg)
        if (!str_starts_with($src, '/') && !str_starts_with($src, 'http')) {
            return is_file($basePath . '/' . $src) ? $src : null;
        }

        // Full URL — extract the URL path
        if (str_starts_with($src, 'http')) {
            $parsed = parse_url($src, PHP_URL_PATH);
            if ($parsed === null || $parsed === false) {
                return null;
            }
            $src = $parsed;
        }

        // Strip the app's public base path prefix (handles subdirectory installs)
        // e.g. /myapp/public/storage/1/file.jpg → /storage/1/file.jpg
        $publicBase = rtrim(parse_url(config('app.url', ''), PHP_URL_PATH) ?? '', '/');
        if ($publicBase === '') {
            // Fallback: derive from SCRIPT_NAME
            $publicBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        }
        if ($publicBase !== '' && str_starts_with($src, $publicBase)) {
            $src = substr($src, strlen($publicBase));
        }
        // Normalize double slashes
        $src = preg_replace('#/+#', '/', $src);

        // Path like /storage/1/file.jpg → storage/app/public/1/file.jpg
        if (preg_match('#^/storage/(.+)$#', $src, $m)) {
            $relative = 'storage/app/public/' . $m[1];
            return is_file($basePath . '/' . $relative) ? $relative : null;
        }

        // Path like /images/hero.png → public/images/hero.png
        $publicPath = 'public' . $src;
        if (is_file($basePath . '/' . $publicPath)) {
            return $publicPath;
        }

        // Absolute filesystem path
        if (str_starts_with($src, $basePath)) {
            return ltrim(substr($src, strlen($basePath)), '/');
        }

        return null;
    }
}
