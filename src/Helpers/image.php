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

if (!function_exists('vela_image_url')) {
    /**
     * Get an optimised /imgp/ URL (no <img> tag wrap). Useful for
     * <link rel="icon">, og:image meta tags, CSS background-image, etc.
     * Returns the raw src unchanged when bundling is disabled or the source
     * can't be resolved.
     */
    function vela_image_url(string $src, int $width, int $height = 0, string $mode = 'fit'): string
    {
        $relativePath = vela_image_relative_path($src);
        if (!config('vela.images.enabled', true) || $relativePath === null) {
            return $src;
        }
        return app(\VelaBuild\Core\Services\ImageOptimizer::class)
            ->generateUrl($relativePath, $width, $height, $mode);
    }
}

if (!function_exists('vela_optimize_imgs')) {
    /**
     * Rewrite every raw <img src="..."> in a blob of HTML through vela_image().
     *
     * Used as a render-time safety net for author-supplied HTML (html blocks,
     * CMS editors, AI-generated copy) so the "every public <img> goes through
     * the optimiser" rule can't be broken by a copy-paste. Idempotent — images
     * whose src already points at /imgp/ or /imgr/ are left alone, as are
     * data: URLs and cross-origin images (we can't process remote files).
     *
     * Runs on the rendered HTML, not per request: in the static-cache deploy
     * model this fires once per page per regen, cost is a regex + URL build.
     */
    function vela_optimize_imgs(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        return preg_replace_callback(
            '#<img\s([^>]*?)\s*/?>#i',
            function ($m) {
                $attrs = [];
                preg_match_all('#([\w:-]+)\s*=\s*"([^"]*)"#', $m[1], $matches, PREG_SET_ORDER);
                foreach ($matches as $a) {
                    $attrs[strtolower($a[1])] = $a[2];
                }

                $src = $attrs['src'] ?? '';
                if ($src === ''
                    || str_contains($src, '/imgp/')
                    || str_contains($src, '/imgr/')
                    || str_starts_with($src, 'data:')) {
                    return $m[0];
                }

                // Skip cross-origin — we can't resize what we don't host.
                if (preg_match('#^https?://#', $src)) {
                    $host = parse_url($src, PHP_URL_HOST);
                    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
                    if ($host && $appHost && $host !== $appHost) {
                        return $m[0];
                    }
                }

                $alt = $attrs['alt'] ?? '';
                unset($attrs['src'], $attrs['alt'], $attrs['srcset'], $attrs['loading']);

                return vela_image($src, $alt, [640, 960, 1280, 1920], 'fit', $attrs);
            },
            $html
        );
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
