<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Models\AiActionLog;

class FetchUrlTool extends BaseTool
{
    private const MAX_BYTES = 512 * 1024;
    private const TIMEOUT = 15;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = (string) ($parameters['url'] ?? '');
        if ($url === '') {
            return ['error' => 'url parameter is required'];
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['error' => 'Only http(s) URLs are allowed'];
        }

        // Block fetches against private / loopback / link-local hosts so the
        // tool can't be used for SSRF against internal services. This site
        // itself is the exception: reading the page a visitor would get is
        // the one reliable way to check a change actually landed, and on a
        // local or intranet install that address is private by definition.
        $host = $parts['host'] ?? '';
        if ($this->isPrivateHost($host) && !$this->isThisSite($parts)) {
            return ['error' => 'Refusing to fetch from a private/loopback address'];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withUserAgent('VelaBuild-AI-Helper/1.0')
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        } catch (\Throwable $e) {
            return ['error' => 'Fetch failed: ' . $e->getMessage()];
        }

        $body = (string) $response->body();
        $size = strlen($body);
        $truncated = false;
        if ($size > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
            $truncated = true;
        }

        return [
            'success'      => true,
            'url'          => $url,
            'status'       => $response->status(),
            'content_type' => $response->header('content-type'),
            'size'         => $size,
            'truncated'    => $truncated,
            'body'         => $body,
        ];
    }

    /**
     * Is this URL the site the chatbot is running inside?
     *
     * Host and port both have to match what the app is configured as, so a
     * loopback address on some other port — a database admin panel, another
     * app on the same machine — stays blocked.
     */
    private function isThisSite(array $parts): bool
    {
        $own = parse_url((string) config('app.url'));
        if (empty($own['host'])) {
            return false;
        }

        $ownPort = $own['port'] ?? (($own['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        return strcasecmp($parts['host'] ?? '', $own['host']) === 0 && $port === $ownPort;
    }

    private function isPrivateHost(string $host): bool
    {
        if ($host === '') {
            return true;
        }
        $lower = strtolower($host);
        if (in_array($lower, ['localhost', 'localhost.localdomain', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }
        if (str_ends_with($lower, '.local') || str_ends_with($lower, '.localhost') || str_ends_with($lower, '.test')) {
            return true;
        }
        // Resolve to IP and reject private/reserved ranges
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (!$ip || $ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        return false;
    }
}
