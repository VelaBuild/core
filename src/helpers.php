<?php

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('vela_config')) {
    /**
     * Safely read a VelaConfig key. Returns $default if the DB/table is
     * unavailable — lets public endpoints keep rendering on no-DB deploys
     * that rely on the static cache.
     */
    function vela_config(string $key, $default = null)
    {
        try {
            $value = \VelaBuild\Core\Models\VelaConfig::where('key', $key)->value('value');
            return $value ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('vela_registration_enabled')) {
    /**
     * Whether anyone may create an account at the register page.
     *
     * Off unless an administrator turns it on in Settings > Visibility; the
     * saved setting wins over VELA_REGISTRATION_ENABLED, which only supplies
     * the default for a site that has never saved one.
     */
    function vela_registration_enabled(): bool
    {
        $saved = vela_config('registration_enabled');

        if ($saved !== null) {
            return $saved === '1';
        }

        return (bool) config('vela.registration_enabled', false);
    }
}

if (!function_exists('vela_asset')) {
    /**
     * URL of a published core asset that changes when the file does.
     *
     * A bare asset() URL never changes, so a browser keeps the copy it cached:
     * after an update the admin ran new page-editor.js against old
     * vela-admin.css, and a block editor's previews and choice drawings came
     * out unstyled — every option looked the same. Guarded, because on a fresh
     * install the published file is not there yet.
     */
    function vela_asset(string $path): string
    {
        $file = public_path($path);

        return asset($path) . '?v=' . (is_file($file) ? filemtime($file) : config('vela.version', '1'));
    }
}

if (!function_exists('renderMarkdown')) {
    // Template views define this in a trailing @php block, but PHP does not hoist
    // functions inside `if` blocks — the first render fails because the helper
    // is referenced above the definition. Declaring it here makes it available
    // at parse time, and the template's guarded re-declaration becomes a no-op.
    function renderMarkdown($text)
    {
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/`(.*?)`/', '<code class="bg-gray-100 px-1 py-0.5 rounded text-sm">$1</code>', $text);
        return $text;
    }
}

if (!function_exists('vela_feature')) {
    /**
     * Whether the site's plan includes a feature. Self-hosted (no VELA_FEATURES
     * set): always true. Hosted: only names the control plane listed.
     */
    function vela_feature(string $name): bool
    {
        $raw = config('vela.hosting.features');
        if ($raw === null) {
            return true;
        }

        return in_array($name, array_filter(array_map('trim', explode(',', (string) $raw))), true);
    }
}

if (!function_exists('vela_demo_mode')) {
    /**
     * Whether this is a public demo site. Not vela_feature(): that is true for
     * every name on self-hosted installs, and demo mode must be asked for.
     */
    function vela_demo_mode(): bool
    {
        if (config('vela.demo.enabled')) {
            return true;
        }
        $raw = config('vela.hosting.features');

        return $raw !== null && in_array('demo', array_map('trim', explode(',', (string) $raw)), true);
    }
}

if (!function_exists('vela_demo_next_reset')) {
    /** When the demo is next reset: the next multiple of reset_hours after 00:00 UTC. */
    function vela_demo_next_reset(): \Illuminate\Support\Carbon
    {
        $every = max(1, min(24, (int) config('vela.demo.reset_hours', 3)));
        $now = now('UTC');

        return $now->copy()->startOfDay()->addHours((intdiv($now->hour, $every) + 1) * $every);
    }
}

if (!function_exists('vela_seat_limit')) {
    /** CMS seats the plan includes, or null for no limit (self-hosted). */
    function vela_seat_limit(): ?int
    {
        $raw = config('vela.hosting.seats');

        return ($raw === null || $raw === '') ? null : max(1, (int) $raw);
    }
}

