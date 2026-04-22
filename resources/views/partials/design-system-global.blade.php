{{-- Expose the design-system palette + fonts to admin JS so existing
     colour inputs and font selectors can show them as presets without
     per-view wiring. Rendered from the admin layout, not public pages. --}}
@php
    try {
        $__ds = app(\VelaBuild\Core\Services\DesignSystem::class);
        $__dsPalette = $__ds->palette();
        $__dsFonts   = $__ds->fonts();
    } catch (\Throwable $__dsErr) {
        // Don't break admin rendering if the filesystem isn't writable yet.
        $__dsPalette = ['name' => 'Default', 'entries' => []];
        $__dsFonts   = ['entries' => []];
    }
@endphp
<script>
window.__velaDesignSystem = {
    palette: @json($__dsPalette),
    fonts:   @json($__dsFonts),
};

// Auto-enhance every <input type="color"> on the page with a palette row.
// Idempotent — marks inputs as enhanced so re-running (e.g. after dynamic
// DOM updates in the page editor) doesn't double up.
(function () {
    var palette = (window.__velaDesignSystem.palette.entries || []);
    if (!palette.length) return;

    function enhance(input) {
        if (!input || input.dataset.velaPalette === '1') return;
        input.dataset.velaPalette = '1';

        var row = document.createElement('div');
        row.className = 'vela-palette-swatches';
        row.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;';

        palette.forEach(function (c) {
            var sw = document.createElement('button');
            sw.type = 'button';
            sw.title = c.name + ' · ' + c.hex;
            sw.setAttribute('aria-label', 'Use ' + c.name);
            sw.style.cssText =
                'width:20px;height:20px;border-radius:4px;cursor:pointer;' +
                'border:1px solid rgba(0,0,0,0.15);padding:0;background:' + c.hex + ';';
            sw.addEventListener('click', function () {
                input.value = c.hex;
                // Dispatch both events so form bindings and Livewire-style
                // listeners both notice the change.
                input.dispatchEvent(new Event('input',  { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            row.appendChild(sw);
        });

        // Insert after the input (or its group wrapper if one exists).
        var host = input.closest('.input-group') || input;
        host.parentNode.insertBefore(row, host.nextSibling);
    }

    function scan(root) {
        (root || document).querySelectorAll('input[type="color"]').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }

    // The page editor builds colour inputs on the fly. Observe the DOM so
    // newly-inserted inputs pick up the palette too.
    if ('MutationObserver' in window) {
        var mo = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes && m.addedNodes.forEach(function (n) {
                    if (n.nodeType === 1) {
                        if (n.matches && n.matches('input[type="color"]')) enhance(n);
                        if (n.querySelectorAll) scan(n);
                    }
                });
            });
        });
        mo.observe(document.body, { childList: true, subtree: true });
    }

    // Expose a helper for explicit use in custom pickers.
    window.Vela = window.Vela || {};
    window.Vela.designSystem = {
        palette: function () { return window.__velaDesignSystem.palette; },
        fonts:   function () { return window.__velaDesignSystem.fonts; },
    };
})();
</script>
