<?php

if (!function_exists('vela_image')) {
    /**
     * Generate an optimized responsive <img> tag with WebP + srcset.
     *
     * Loading behaviour (6th parameter):
     *   'lazy'    — (default) loading="lazy" + sizes="auto". Best for below-fold.
     *   'eager'   — No loading attribute, no sizes="auto". For images that must
     *               render immediately but don't need preload priority.
     *   'preload' — loading="eager" + fetchpriority="high". For the LCP / hero
     *               image. The caller should also emit a <link rel="preload">
     *               in <head> via vela_image_preload() for best results.
     *   false     — Skip loading attr entirely (legacy compat, same as 'eager').
     *
     * @param string $src      Image URL, absolute path, or relative path
     * @param string $alt      Alt text
     * @param array  $sizes    Responsive widths (px)
     * @param string $mode     'fit' or 'crop'
     * @param array  $attrs    Extra HTML attributes (e.g. ['class' => '...'])
     * @param string|false $loading  'lazy' (default), 'eager', 'preload', or false
     */
    function vela_image(string $src, string $alt = '', array $sizes = [400, 800, 1200], string $mode = 'fit', array $attrs = [], string|false $loading = 'lazy'): string
    {
        $relativePath = vela_image_relative_path($src);

        // Vector files have no pixels to resample. Sending one through the
        // optimizer produces a URL that errors, and the picture renders as a
        // broken icon — so it goes out untouched, which is also the smallest
        // and sharpest it can be.
        $isVector = preg_match('#\.svgz?($|[?\#])#i', $src) === 1;

        if (!config('vela.images.enabled', true) || $relativePath === null || $isVector) {
            $extraAttrs = '';
            foreach ($attrs as $k => $v) {
                $extraAttrs .= ' ' . e($k) . '="' . e($v) . '"';
            }
            $loadAttr = match ($loading) {
                'lazy' => ' loading="lazy"',
                'preload' => ' loading="eager" fetchpriority="high"',
                'eager', false => '',
                default => ' loading="lazy"',
            };
            return '<img src="' . e($src) . '" alt="' . e($alt) . '"' . $loadAttr . $extraAttrs . '>';
        }

        $optimizer = app(\VelaBuild\Core\Services\ImageOptimizer::class);

        $srcsetParts = [];
        $urls = [];
        foreach ($sizes as $width) {
            $url = $optimizer->generateUrl($relativePath, $width, 0, $mode);
            $srcsetParts[] = $url . ' ' . $width . 'w';
            $urls[] = $url;
        }
        $defaultUrl = $urls[(int) floor(count($urls) / 2)] ?? $urls[0];

        $srcset = implode(', ', $srcsetParts);

        $extraAttrs = '';
        $hasSizes = false;
        $hasDimension = false;
        foreach ($attrs as $k => $v) {
            $lower = strtolower($k);
            if ($lower === 'sizes') {
                $hasSizes = true;
            }
            if ($lower === 'width' || $lower === 'height') {
                $hasDimension = true;
            }
            $extraAttrs .= ' ' . e($k) . '="' . e($v) . '"';
        }

        // Emit intrinsic width/height so the browser knows the aspect ratio
        // before the file arrives. Without them, `sizes="auto"` in browsers that
        // don't support it resolves to an invalid source size, the candidate's
        // density-corrected intrinsic height collapses, and an image styled
        // `width: 100%` renders as a vertically squashed strip. Also removes CLS.
        if (!$hasDimension) {
            [$intrinsicW, $intrinsicH] = vela_image_dimensions($relativePath);
            if ($intrinsicW > 0 && $intrinsicH > 0) {
                $defaultWidth = $sizes[(int) floor(count($sizes) / 2)] ?? $sizes[0] ?? $intrinsicW;
                $renderW = min((int) $defaultWidth, $intrinsicW);
                $renderH = (int) round($intrinsicH * ($renderW / $intrinsicW));
                $extraAttrs .= ' width="' . $renderW . '" height="' . $renderH . '"';
            }
        }

        $sizesAttr = '';
        $loadAttr = '';

        switch ($loading) {
            case 'lazy':
                $loadAttr = ' loading="lazy"';
                if (!$hasSizes) {
                    $sizesAttr = ' sizes="auto"';
                }
                break;
            case 'preload':
                $loadAttr = ' loading="eager" fetchpriority="high"';
                // Fall through: an eagerly loaded image still needs `sizes`.
            case 'eager':
            case false:
                // A srcset carrying width descriptors is invalid HTML without
                // `sizes`, and `sizes="auto"` is defined only for lazy images —
                // so state the widest candidate instead. Without this every
                // eager or preloaded image fails validation.
                if (!$hasSizes) {
                    $widest = $sizes ? max($sizes) : 0;
                    $sizesAttr = $widest > 0
                        ? ' sizes="(max-width: ' . $widest . 'px) 100vw, ' . $widest . 'px"'
                        : ' sizes="100vw"';
                }
                break;
        }

        return '<img src="' . $defaultUrl . '" srcset="' . $srcset . '"' . $sizesAttr . $extraAttrs . $loadAttr . ' alt="' . e($alt) . '">';
    }
}

if (!function_exists('vela_image_preload')) {
    /**
     * Generate a <link rel="preload"> tag for an above-fold hero/LCP image.
     *
     * Place this in the <head> section (via @push('head') or similar) alongside
     * a vela_image(..., loading: 'preload') call in the body. Together they
     * give the browser the earliest possible signal to fetch the LCP image.
     *
     * @param string $src   Image URL or path
     * @param array  $sizes Responsive widths (should match the vela_image call)
     * @param string $mode  'fit' or 'crop'
     */
    function vela_image_preload(string $src, array $sizes = [400, 800, 1200], string $mode = 'fit'): string
    {
        $relativePath = vela_image_relative_path($src);
        if (!config('vela.images.enabled', true) || $relativePath === null) {
            return '<link rel="preload" as="image" href="' . e($src) . '">';
        }

        $optimizer = app(\VelaBuild\Core\Services\ImageOptimizer::class);
        $srcsetParts = [];
        foreach ($sizes as $width) {
            $url = $optimizer->generateUrl($relativePath, $width, 0, $mode);
            $srcsetParts[] = $url . ' ' . $width . 'w';
        }

        return '<link rel="preload" as="image" imagesrcset="' . implode(', ', $srcsetParts) . '" imagesizes="auto">';
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

                // An SVG has no pixels to resample: the optimizer cannot open
                // it, so the request 500s and the picture renders as a broken
                // icon. It is already the smallest and sharpest form it has —
                // leave it exactly as it is.
                if (preg_match('#\.svgz?($|[?\#])#i', $src)) {
                    return $m[0];
                }

                if (preg_match('#^https?://#', $src)) {
                    $host = parse_url($src, PHP_URL_HOST);
                    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
                    if ($host && $appHost && $host !== $appHost) {
                        return $m[0];
                    }
                }

                $alt = $attrs['alt'] ?? '';
                unset($attrs['src'], $attrs['alt'], $attrs['srcset'], $attrs['loading'], $attrs['sizes']);

                return vela_image($src, $alt, [640, 960, 1280, 1920], 'fit', $attrs);
            },
            $html
        );
    }
}

if (!function_exists('vela_image_dimensions')) {
    /**
     * Intrinsic [width, height] of an image relative to base_path(), or [0, 0]
     * when it can't be read. Memoised per request — a page can reference the
     * same logo or thumbnail dozens of times.
     */
    function vela_image_dimensions(string $relativePath): array
    {
        static $cache = [];

        if (!array_key_exists($relativePath, $cache)) {
            $info = @getimagesize(base_path($relativePath));
            $cache[$relativePath] = $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
        }

        return $cache[$relativePath];
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

        if (!str_starts_with($src, '/') && !str_starts_with($src, 'http')) {
            return is_file($basePath . '/' . $src) ? $src : null;
        }

        if (str_starts_with($src, 'http')) {
            $parsed = parse_url($src, PHP_URL_PATH);
            if ($parsed === null || $parsed === false) {
                return null;
            }
            $src = $parsed;
        }

        $publicBase = rtrim(parse_url(config('app.url', ''), PHP_URL_PATH) ?? '', '/');
        if ($publicBase === '') {
            $publicBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        }
        if ($publicBase !== '' && str_starts_with($src, $publicBase)) {
            $src = substr($src, strlen($publicBase));
        }
        $src = preg_replace('#/+#', '/', $src);

        if (preg_match('#^/storage/(.+)$#', $src, $m)) {
            $relative = 'storage/app/public/' . $m[1];
            return is_file($basePath . '/' . $relative) ? $relative : null;
        }

        $publicPath = 'public' . $src;
        if (is_file($basePath . '/' . $publicPath)) {
            return $publicPath;
        }

        if (str_starts_with($src, $basePath)) {
            return ltrim(substr($src, strlen($basePath)), '/');
        }

        return null;
    }
}
