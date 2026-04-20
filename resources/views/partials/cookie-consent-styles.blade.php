{{--
    Cookie Consent styles — included from each template's <head> so the CSS
    is valid HTML5 and loads before the banner paints (no FOUC).

    Paired with vela::partials.cookie-consent (banner + JS), which is
    included from scripts-footer near </body>.
--}}
@if(config('vela.gdpr.enabled'))
<style>
    /* ── Consent bar (no overlay — site remains interactive) ── */
    #vela-consent { position: fixed; bottom: 0; left: 0; right: 0; z-index: 99999; pointer-events: none; }
    #vela-consent.vc-visible { pointer-events: auto; }

    .vc-bar {
        background: #fff;
        box-shadow: 0 -4px 24px rgba(0,0,0,.12);
        transform: translateY(100%);
        transition: transform .35s cubic-bezier(.4,0,.2,1);
        max-height: 90vh; overflow-y: auto;
    }
    #vela-consent.vc-visible .vc-bar { transform: translateY(0); }

    .vc-inner { max-width: 720px; margin: 0 auto; padding: 24px 24px 20px; }

    .vc-text p {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 14px; line-height: 1.6; color: #374151; margin: 0;
    }

    /* ── Buttons ── */
    .vc-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; align-items: center; }
    .vc-btn {
        font-family: inherit; font-size: 14px; font-weight: 600;
        padding: 10px 20px; border-radius: 8px; border: none;
        cursor: pointer; transition: background .15s ease, transform .1s ease;
        white-space: nowrap;
    }
    .vc-btn:active { transform: scale(.97); }
    .vc-btn-accept { background: #4f46e5; color: #fff; }
    .vc-btn-accept:hover { background: #4338ca; }
    .vc-btn-necessary { background: #f1f5f9; color: #475569; }
    .vc-btn-necessary:hover { background: #e2e8f0; }
    .vc-btn-manage { background: transparent; color: #4f46e5; padding: 10px 12px; }
    .vc-btn-manage:hover { background: #f1f5f9; }

    /* ── Category panel ── */
    .vc-categories { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 16px; }
    .vc-category { padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
    .vc-category:last-of-type { border-bottom: none; }
    .vc-category-header { display: flex; gap: 14px; align-items: flex-start; }
    .vc-category-header strong { font-size: 14px; color: #1e293b; display: block; }
    .vc-category-header p { font-size: 13px; color: #64748b; margin: 2px 0 0; line-height: 1.4; }
    .vc-category-actions { padding-top: 12px; }

    /* ── Toggle switch ── */
    .vc-switch { position: relative; display: inline-block; width: 40px; min-width: 40px; height: 22px; margin-top: 2px; }
    .vc-switch input { opacity: 0; width: 0; height: 0; }
    .vc-slider {
        position: absolute; cursor: pointer; inset: 0;
        background: #d1d5db; border-radius: 22px; transition: background .2s ease;
    }
    .vc-slider::before {
        content: ''; position: absolute; height: 16px; width: 16px;
        left: 3px; bottom: 3px; background: #fff; border-radius: 50%;
        transition: transform .2s ease;
    }
    .vc-switch input:checked + .vc-slider { background: #4f46e5; }
    .vc-switch input:checked + .vc-slider::before { transform: translateX(18px); }
    .vc-switch-locked .vc-slider { cursor: not-allowed; opacity: .6; }
    .vc-switch-locked input:checked + .vc-slider { background: #22c55e; opacity: .7; }

    /* ── Privacy link (inline with buttons, right-aligned) ── */
    .vc-privacy-link { margin-left: auto; font-size: 13px; color: #6b7280; text-decoration: underline; white-space: nowrap; }
    .vc-privacy-link:hover { color: #4f46e5; }

    /* ── Responsive ── */
    @media (max-width: 600px) {
        .vc-inner { padding: 20px 16px 16px; }
        .vc-actions { flex-direction: column; }
        .vc-btn { width: 100%; text-align: center; }
        .vc-privacy-link { margin-left: 0; text-align: center; }
    }
</style>
@endif
