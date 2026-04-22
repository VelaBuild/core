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
