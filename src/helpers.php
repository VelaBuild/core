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
