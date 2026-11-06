<?php

namespace VelaBuild\Core\Http;

/**
 * The part of public/index.php that answers before Laravel boots.
 *
 * Published pages are pre-rendered to resources/static, and serving one should
 * not cost a framework boot. That logic used to live in the starter's
 * public/index.php — three hundred lines every site carried its own copy of,
 * so a fix to it never reached a site that had already been installed. It
 * lives here now, and the starter's front controller only requires this file
 * and calls handle().
 *
 * This file is required by path, before Composer's autoloader exists: it must
 * not use anything outside itself — no helpers, no other classes, no
 * framework. Everything it reads is the request superglobals, the .env file
 * and storage/app/vela-site.php (the lightweight site config cache).
 */
final class StaticFrontController
{
    /** First URL segments that always go to Laravel. */
    private const EXCLUDE = ['admin', 'vela', 'login', 'logout', 'register', 'password',
        'home', 'profile', 'two-factor', 'imgp', 'imgr', 'api', 'page-form',
        'storage', 'vendor', 'livewire', 'horizon', 'telescope'];

    /** First URL segments a holding page never redirects. */
    private const HOLD_ALLOW = ['admin', 'vela', 'login', 'logout', 'register', 'password',
        'storage', 'vendor', 'imgp', 'imgr', 'api', 'manifest.json', 'sw.js',
        'offline', 'robots.txt', 'two-factor'];

    /** AI agents the x402 middleware may want to charge, so never served from cache. */
    private const AI_BOTS = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai',
        'Google-Extended', 'CCBot', 'PerplexityBot', 'Bytespider', 'Amazonbot', 'Cohere-ai',
        'AI2Bot', 'Applebot-Extended', 'FacebookBot', 'Diffbot', 'PetalBot'];

    /**
     * Answer the request from disk if it can be, and exit; otherwise return and
     * let the front controller boot Laravel.
     */
    public static function handle(string $basePath): void
    {
        $answer = self::resolve($basePath, $_SERVER, $_COOKIE);
        if ($answer === null) {
            return;
        }

        foreach ($answer['headers'] as $name => $value) {
            header($name . ': ' . $value, true, $answer['status']);
        }
        if (isset($answer['file'])) {
            readfile($answer['file']);
        }
        exit;
    }

    /**
     * What to send, without sending it: null when Laravel should handle the
     * request, or ['status' => int, 'headers' => [...], 'file' => ?string].
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $cookies
     * @return array{status: int, headers: array<string, string>, file?: string}|null
     */
    public static function resolve(string $basePath, array $server, array $cookies): ?array
    {
        $env = self::envValues($basePath . '/.env', ['VELA_CACHE', 'APP_NAME']);
        $cache = true;
        if (array_key_exists('VELA_CACHE', $env)) {
            $cache = !in_array(strtolower($env['VELA_CACHE']), ['false', '0', 'off', 'no', ''], true);
        }

        $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $rawPath = rawurldecode((string) strtok((string) ($server['REQUEST_URI'] ?? '/'), '?'));

        $siteConfigFile = $basePath . '/storage/app/vela-site.php';
        if (is_file($siteConfigFile)) {
            $siteConf = @include $siteConfigFile;
            if (is_array($siteConf)) {
                if (!empty($siteConf['visibility_holding_page']) && !empty($siteConf['visibility_holding_page_slug'])) {
                    $redirect = self::holdingPageRedirect($siteConf, $scriptDir, $rawPath, $env['APP_NAME'] ?? '', $cookies);
                    if ($redirect === false) {
                        // Probably a signed-in admin: Laravel's middleware decides.
                        $cache = false;
                    } elseif ($redirect !== null) {
                        return [
                            'status'  => 302,
                            'headers' => ['Location' => $redirect, 'Cache-Control' => 'no-cache, no-store'],
                        ];
                    }
                }

                if (!empty($siteConf['x402_enabled']) && !empty($siteConf['x402_pay_to'])) {
                    $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
                    foreach (self::AI_BOTS as $bot) {
                        if (stripos($ua, $bot) !== false) {
                            $cache = false;
                            break;
                        }
                    }
                }
            }
        }

        if (!$cache || ($server['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return null;
        }

        $file = self::staticFileFor($basePath . '/resources/static', $scriptDir, $rawPath);
        if ($file === null) {
            return null;
        }

        $headers = [
            'Content-Type'  => 'text/html; charset=UTF-8',
            // Browsers always re-check (the CDN answers); the CDN may keep the page an hour
            // (publishing purges it sooner) and serve it stale if the origin is down.
            'Cache-Control' => 'public, max-age=0, s-maxage=3600, stale-while-revalidate=60, stale-if-error=86400',
            'X-Static'      => 'true',
        ];
        // Cloudflare Cache-Tag — written as a `.tags` sidecar by the static-site
        // generator, so purge-by-tag works for pages served without Laravel.
        $tagsFile = substr($file, 0, -5) . '.tags';
        if (is_file($tagsFile)) {
            $tags = trim((string) file_get_contents($tagsFile));
            if ($tags !== '') {
                $headers['Cache-Tag'] = $tags;
            }
        }

        return ['status' => 200, 'headers' => $headers, 'file' => $file];
    }

    /**
     * The holding page URL to redirect to, null to carry on, or false when a
     * session cookie says the visitor may be an admin (let Laravel decide).
     *
     * @param  array<string, mixed>  $siteConf
     * @param  array<string, mixed>  $cookies
     */
    private static function holdingPageRedirect(array $siteConf, string $scriptDir, string $rawPath, string $appName, array $cookies): string|false|null
    {
        $holdSlug = (string) $siteConf['visibility_holding_page_slug'];
        $reqPath = ($scriptDir !== '' && strpos($rawPath, $scriptDir) === 0)
            ? trim(substr($rawPath, strlen($scriptDir)), '/')
            : trim($rawPath, '/');

        // Strip a locale prefix (2-char or zh-Hans style) for comparison.
        $pathForMatch = trim((string) preg_replace('#^[a-z]{2}(-[A-Za-z]{2,4})?(/|$)#', '', $reqPath), '/');
        $first = explode('/', $reqPath)[0] ?? '';

        $sessionName = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($appName, '"\'') ?: 'laravel')) . '_session';
        $hasSession = isset($cookies[$sessionName]);

        $isHoldingPage = $pathForMatch === $holdSlug || $reqPath === $holdSlug;
        $isAllowedPath = in_array($first, self::HOLD_ALLOW, true);
        // The privacy page must always be reachable (GDPR).
        $privacySlug = ltrim((string) ($siteConf['gdpr_privacy_url'] ?? '/privacy'), '/');
        $isPrivacyPage = $pathForMatch === $privacySlug || $reqPath === $privacySlug;

        if (!$isHoldingPage && !$isAllowedPath && !$isPrivacyPage && !$hasSession) {
            return $scriptDir . '/' . $holdSlug;
        }

        return $hasSession ? false : null;
    }

    /** The pre-rendered file for this URL, if there is one inside $base. */
    private static function staticFileFor(string $base, string $scriptDir, string $rawPath, bool $localeOnly = false): ?string
    {
        // Strip the base path so the app works in subdirectories.
        $uri = ($scriptDir !== '' && strpos($rawPath, $scriptDir) === 0) ? substr($rawPath, strlen($scriptDir)) : $rawPath;
        $uri = rtrim($uri, '/') ?: '/';

        $segments = explode('/', ltrim($uri, '/'));
        $first = $segments[0] ?? '';
        if (in_array($first, self::EXCLUDE, true)) {
            return null;
        }

        // Locale prefix: "th", "pt-BR", "zh-Hans". The subtag must be cased like
        // one (region upper-case, script title-case): a page slug such as
        // "ai-chat" or "my-site" is not a locale. A two-letter first segment
        // with no translation file falls back to being an ordinary page below.
        $locale = null;
        $slugSegments = $segments;
        if (preg_match('/^[a-z]{2}(-([A-Z]{2}|[A-Z][a-z]{3}))?$/', $first)) {
            $locale = $first;
            array_shift($slugSegments);
        }
        $path = implode('/', $slugSegments);
        if ($locale !== null && !$localeOnly) {
            // Try it as a locale first; if that page has no translation, it's a page slug.
            $localised = self::staticFileFor($base, $scriptDir, $rawPath, true);
            if ($localised !== null) {
                return $localised;
            }
            $locale = null;
            $path = implode('/', $segments);
        }

        if ($uri === '/') {
            $file = $base . '/home/index.html';
        } elseif ($locale !== null && $path === '') {
            $file = $base . '/home/translations/' . $locale . '.html';
        } elseif ($locale !== null) {
            if (strpos($path, 'posts/') === 0) {
                $file = $base . '/posts/' . substr($path, 6) . '/translations/' . $locale . '.html';
            } elseif ($path === 'posts') {
                $file = $base . '/posts/translations/' . $locale . '.html';
            } elseif (strpos($path, 'categories/') === 0) {
                $file = $base . '/categories/' . substr($path, 11) . '/translations/' . $locale . '.html';
            } elseif ($path === 'categories') {
                $file = $base . '/categories/translations/' . $locale . '.html';
            } else {
                $file = $base . '/pages/' . $path . '/translations/' . $locale . '.html';
            }
        } elseif ($path === 'posts') {
            $file = $base . '/posts/index.html';
        } elseif (strpos($path, 'posts/') === 0) {
            $file = $base . '/posts/' . substr($path, 6) . '/index.html';
        } elseif ($path === 'categories') {
            $file = $base . '/categories/index.html';
        } elseif (strpos($path, 'categories/') === 0) {
            $file = $base . '/categories/' . substr($path, 11) . '/index.html';
        } else {
            $file = $base . '/pages/' . $path . '/index.html';
        }

        // Path traversal protection.
        $real = realpath($file);
        $baseReal = realpath($base);
        if ($real === false || $baseReal === false
            || strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0
            || substr($real, -5) !== '.html'
            || !is_file($real)
        ) {
            return null;
        }

        return $real;
    }

    /**
     * A lightweight read of a few keys from .env — no framework, no dotenv.
     * The last assignment of a key wins, as in the original front controller.
     *
     * @param  string[]  $keys
     * @return array<string, string>
     */
    private static function envValues(string $file, array $keys): array
    {
        if (!is_file($file)) {
            return [];
        }
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            foreach ($keys as $key) {
                if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $line)) {
                    $value = trim(explode('=', $line, 2)[1] ?? '');
                    $values[$key] = $key === 'APP_NAME' ? trim($value, '"\'') : $value;
                }
            }
        }

        return $values;
    }
}
