<script>
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js', { scope: '/' });
    });

    // Handle install prompt
    let deferredPrompt;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;

        // Don't show if user previously dismissed
        if (localStorage.getItem('pwa-install-dismissed')) return;

        // Create install banner
        const banner = document.createElement('div');
        banner.id = 'pwa-install-banner';
        banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#1f2937;color:white;padding:1rem;display:flex;justify-content:center;align-items:center;gap:1rem;z-index:9999;font-family:-apple-system,BlinkMacSystemFont,sans-serif;font-size:0.9rem;';
        banner.innerHTML = '<span>{{ __("vela::pwa.install_prompt") }}</span>' +
            '<button id="pwa-install-btn" style="background:white;color:#1f2937;border:none;padding:0.5rem 1rem;border-radius:0.25rem;cursor:pointer;font-weight:600;">{{ __("vela::pwa.install_button") }}</button>' +
            '<button id="pwa-dismiss-btn" style="background:transparent;color:white;border:1px solid rgba(255,255,255,0.3);padding:0.5rem 1rem;border-radius:0.25rem;cursor:pointer;">{{ __("vela::pwa.dismiss_button") }}</button>';
        document.body.appendChild(banner);

        document.getElementById('pwa-install-btn').addEventListener('click', function() {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function() { banner.remove(); });
        });

        document.getElementById('pwa-dismiss-btn').addEventListener('click', function() {
            banner.remove();
            localStorage.setItem('pwa-install-dismissed', '1');
        });
    });
}
</script>
