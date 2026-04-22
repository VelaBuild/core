<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Services\CacheTagger;

/**
 * Terminable middleware that writes the accumulated Cache-Tag header onto
 * the response, IF the response is cacheable (2xx, GET, not already tagged).
 *
 * We also always register `site` so a full-site purge has one tag to target.
 */
class EmitCacheTags
{
    public function __construct(
        private CacheTagger $tagger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only annotate cacheable responses. Skip POST/PATCH/DELETE, non-2xx,
        // and anything already carrying its own Cache-Tag (e.g. an upstream
        // proxy emitted one).
        if (!$this->shouldEmit($request, $response)) {
            return $response;
        }

        // Default `site` tag on every public response — cheap, gives us a
        // one-shot "purge everything Vela served" toggle.
        $this->tagger->add('site');

        $header = $this->tagger->header();
        if ($header !== null) {
            $response->headers->set('Cache-Tag', $header);
        }

        return $response;
    }

    private function shouldEmit(Request $request, Response $response): bool
    {
        if (!in_array(strtoupper($request->method()), ['GET', 'HEAD'], true)) {
            return false;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return false;
        }
        if ($response->headers->has('Cache-Tag')) {
            return false;
        }
        return true;
    }
}
