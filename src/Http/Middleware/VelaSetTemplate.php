<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Services\PermissionGates;

class VelaSetTemplate
{
    /**
     * The active template normally comes from storage/app/vela-site.php at boot.
     *
     * The one exception is theme preview: the appearance screen puts a theme in
     * the session, and pages loaded inside the preview frame render in that
     * theme instead. Without it the preview only held for the one URL the modal
     * opened — the first link clicked inside the frame dropped the visitor back
     * into the installed theme, which read as the preview failing.
     *
     * It stops at the edge of that frame. Applying it to the whole session made
     * the admin's own view of the site change theme in every other tab, which
     * reads as the live site having been switched — the one thing a preview
     * must never look like it did.
     */
    public function handle(Request $request, Closure $next)
    {
        // A design build writes four views nothing ever renders in its theme —
        // the article listing, an article, the topics listing, a topic — since
        // only its own preview page wears that theme. They ship unseen and
        // appear for the first time after someone keeps the design. This asks
        // for any public page in the design's theme, so the build can at least
        // check they render, and a person can look before deciding.
        if ($request->filled('design_preview')) {
            $frame = app(\VelaBuild\Core\Services\DesignPreviewFrame::class);

            // Held to a secret rather than a permission: the build fetches
            // these pages itself over HTTP, with no session to be gated by,
            // and a design nobody has decided to keep should still not be on
            // show to whoever guesses a query string.
            if ($frame->theme() !== null && $frame->matches($request->query('design_preview'))) {
                $frame->activate();

                $response = $next($request);

                // Whoever opens the link sees a site that is not the one it
                // will be until they say so, and nothing else on the page says
                // that. The way out is dropping the link, not an admin route
                // this viewer may not be able to reach.
                return $this->addPreviewBar(
                    $response,
                    $frame->theme(),
                    $request->fullUrlWithoutQuery('design_preview')
                );
            }
        }

        $preview = $request->session()->get('vela_preview_template');

        if (! $preview || ! $this->isPreviewFrame($request)) {
            return $next($request);
        }

        // Only whoever may change the theme gets to see the preview; for
        // everyone else — and after the theme is gone — the session key is
        // dead weight, so drop it.
        //
        // Public routes carry neither the admin guard nor the gate
        // definitions the admin group's middleware sets up, so the permission
        // has to be resolved here from the `vela` guard directly.
        $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();

        if (auth('vela')->check()) {
            Auth::shouldUse('vela');
            app(PermissionGates::class)->register();
        }

        if (! auth('vela')->check() || Gate::denies('config_access') || ! array_key_exists($preview, $templates)) {
            $request->session()->forget('vela_preview_template');

            return $next($request);
        }

        config(['vela.template.active' => $preview]);

        $response = $next($request);

        return $this->addPreviewBar($response, $templates[$preview]['label'] ?? $preview);
    }

    /**
     * Is this page being loaded inside the preview frame?
     *
     * Browsers label the destination of every navigation: a frame says
     * `iframe`, a tab says `document`. That is the whole difference between
     * looking at a preview and looking at the site, and it needs no marker of
     * ours in the URL, which links inside the previewed page would drop anyway.
     * A browser too old to send the header (Safari before 16.4) keeps the
     * behaviour it had before the preview existed.
     */
    protected function isPreviewFrame(Request $request): bool
    {
        return in_array($request->headers->get('Sec-Fetch-Dest'), ['iframe', 'frame'], true);
    }

    /**
     * Say which theme is on screen and offer the way out, appended after the
     * page's own markup so it works whatever theme is being previewed.
     */
    protected function addPreviewBar($response, string $label, ?string $exitUrl = null)
    {
        if (! method_exists($response, 'getContent') || ! $response instanceof \Illuminate\Http\Response) {
            return $response;
        }

        $type = $response->headers->get('Content-Type', 'text/html');
        if (! str_contains($type, 'html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return $response;
        }

        $bar = view('vela::public.partials.theme-preview-bar', [
            'themeLabel' => $label,
            // A preview reached by a link rather than by the appearance screen
            // leaves it by dropping the link, not by clearing a session key it
            // never set — and its viewer may not be able to reach an admin
            // route at all.
            'exitUrl'    => $exitUrl ?: route('vela.admin.settings.appearance.previewExit'),
        ])->render();

        $response->setContent(preg_replace('#</body>#i', $bar . '</body>', $content, 1));

        return $response;
    }
}
