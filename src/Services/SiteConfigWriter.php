<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;

class SiteConfigWriter
{
    /**
     * Rebuild the static site config cache from DB values.
     *
     * Called after any settings change (admin UI or MCP API) to ensure
     * the cached PHP file reflects the latest DB state.
     */
    /**
     * Push the written file's values into the runtime config.
     *
     * The provider does this once at boot, which is enough for a normal
     * request. It is not enough for the process that just changed a setting:
     * its config still holds what was loaded at boot, so anything rendered
     * afterwards in that process — a page snapshot an observer rebuilds, most
     * of all — comes out with the theme that was just replaced, and a visitor
     * is served it. Both callers share this so the mapping cannot drift.
     */
    public static function apply(?array $siteConfig = null): void
    {
        if ($siteConfig === null) {
            $path = storage_path('app/vela-site.php');
            if (! file_exists($path)) {
                return;
            }
            $siteConfig = include $path;
        }

        if (! is_array($siteConfig)) {
            return;
        }

        if (! empty($siteConfig['site_name'])) {
            config(['app.name' => $siteConfig['site_name']]);
        }
        if (! empty($siteConfig['site_tagline'])) {
            config(['vela.site.tagline' => $siteConfig['site_tagline']]);
        }
        if (! empty($siteConfig['primary_language'])) {
            config(['vela.primary_language' => $siteConfig['primary_language']]);
        }
        if (! empty($siteConfig['active_languages'])) {
            $languages = json_decode((string) $siteConfig['active_languages'], true);

            // The site's own languages decide what the locale middleware will
            // accept, so a primary language missing from them is ignored.
            if (is_array($languages) && $languages) {
                $available = config('vela.available_languages', []);
                $enabled = [];

                foreach ($languages as $code) {
                    if (isset($available[$code])) {
                        $enabled[$code] = $available[$code];
                    }
                }

                if ($enabled) {
                    config(['vela.available_languages' => $enabled]);
                }
            }
        }
        // write() has always recorded this, but nothing ever read it back into
        // the config — so the site description a user typed in Settings never
        // reached the page that needed it.
        if (! empty($siteConfig['site_description'])) {
            config(['vela.site.description' => $siteConfig['site_description']]);
        }
        if (! empty($siteConfig['active_template'])) {
            config(['vela.template.active' => $siteConfig['active_template']]);
        }
        // Cleared CSS has to reach the config as an empty string, or the site
        // keeps serving the stylesheet that was just deleted.
        if (array_key_exists('custom_css_global', $siteConfig)) {
            config(['vela.site.custom_css_global' => $siteConfig['custom_css_global']]);
        }
        if (! empty($siteConfig['theme']) && is_array($siteConfig['theme'])) {
            foreach ($siteConfig['theme'] as $key => $value) {
                config(['vela.theme.' . substr($key, 6) => $value]); // strip the 'theme_' prefix
            }
        }
        if (isset($siteConfig['visibility_mode'])) {
            config(['vela.visibility.mode' => $siteConfig['visibility_mode']]);
            config(['vela.visibility.noindex' => ! empty($siteConfig['visibility_noindex'])]);
            config(['vela.visibility.block_ai' => ! empty($siteConfig['visibility_block_ai'])]);
            config(['vela.visibility.holding_page' => ! empty($siteConfig['visibility_holding_page'])]);
            config(['vela.visibility.holding_page_id' => $siteConfig['visibility_holding_page_id'] ?? '']);
            config(['vela.visibility.holding_page_slug' => $siteConfig['visibility_holding_page_slug'] ?? '']);
        }
        if (isset($siteConfig['x402_enabled'])) {
            config(['vela.x402.enabled' => (bool) $siteConfig['x402_enabled']]);
        }
        foreach (['mode', 'pay_to', 'network', 'description'] as $option) {
            if (! empty($siteConfig['x402_' . $option])) {
                config(['vela.x402.' . $option => $siteConfig['x402_' . $option]]);
            }
        }
        if (isset($siteConfig['x402_price_usd'])) {
            config(['vela.x402.price_usd' => $siteConfig['x402_price_usd']]);
        }
        if (isset($siteConfig['gdpr_enabled'])) {
            config(['vela.gdpr.enabled' => (bool) $siteConfig['gdpr_enabled']]);
        }
        if (! empty($siteConfig['gdpr_privacy_url'])) {
            config(['vela.gdpr.privacy_url' => $siteConfig['gdpr_privacy_url']]);
        }
    }

    public function write(): void
    {
        $config = [
            'site_name' => VelaConfig::where('key', 'site_name')->value('value') ?? '',
            'site_niche' => VelaConfig::where('key', 'site_niche')->value('value') ?? '',
            'site_tagline' => VelaConfig::where('key', 'site_tagline')->value('value') ?? '',
            'site_description' => VelaConfig::where('key', 'site_description')->value('value') ?? '',
            'active_template' => VelaConfig::where('key', 'active_template')->value('value') ?? '',
            'custom_css_global' => VelaConfig::where('key', 'custom_css_global')->value('value') ?? '',
        ];

        // Settings → Languages writes these, and nothing carried them any
        // further: the choice was saved, the site went on serving English,
        // and the page kept reporting lang="en". They are read back in
        // apply() below.
        $primaryLanguage = VelaConfig::where('key', 'primary_language')->value('value');
        if ($primaryLanguage) {
            $config['primary_language'] = $primaryLanguage;
        }

        $activeLanguages = VelaConfig::where('key', 'active_languages')->value('value');
        if ($activeLanguages) {
            $config['active_languages'] = $activeLanguages;
        }

        // Include all theme options
        $config['theme'] = VelaConfig::where('key', 'like', 'theme_%')
            ->pluck('value', 'key')
            ->toArray();

        // Site visibility settings
        $visibilityMode = VelaConfig::where('key', 'visibility_mode')->value('value');
        if ($visibilityMode !== null) {
            $config['visibility_mode'] = $visibilityMode;
            $config['visibility_noindex'] = VelaConfig::where('key', 'visibility_noindex')->value('value') === '1';
            $config['visibility_block_ai'] = VelaConfig::where('key', 'visibility_block_ai')->value('value') === '1';
            $config['visibility_holding_page'] = VelaConfig::where('key', 'visibility_holding_page')->value('value') === '1';
            $holdingId = VelaConfig::where('key', 'visibility_holding_page_id')->value('value') ?? '';
            $config['visibility_holding_page_id'] = $holdingId;
            $config['visibility_holding_page_slug'] = $holdingId
                ? (Page::where('id', $holdingId)->value('slug') ?? '')
                : '';
        }

        // x402 AI Payment settings
        $x402Enabled = VelaConfig::where('key', 'x402_enabled')->value('value');
        if ($x402Enabled !== null) {
            $config['x402_enabled'] = $x402Enabled === '1';
            $config['x402_mode'] = VelaConfig::where('key', 'x402_mode')->value('value') ?? 'sitewide';
            $config['x402_pay_to'] = VelaConfig::where('key', 'x402_pay_to')->value('value') ?? '';
            $config['x402_price_usd'] = VelaConfig::where('key', 'x402_price_usd')->value('value') ?? '0.01';
            $config['x402_network'] = VelaConfig::where('key', 'x402_network')->value('value') ?? 'base';
            $config['x402_description'] = VelaConfig::where('key', 'x402_description')->value('value') ?? '';
        }

        // GDPR settings (DB overrides .env when set by admin)
        $gdprEnabled = VelaConfig::where('key', 'gdpr_enabled')->value('value');
        if ($gdprEnabled !== null) {
            $config['gdpr_enabled'] = $gdprEnabled === '1';
        }
        $gdprPrivacyUrl = VelaConfig::where('key', 'gdpr_privacy_url')->value('value');
        if ($gdprPrivacyUrl !== null) {
            $config['gdpr_privacy_url'] = $gdprPrivacyUrl;
        }

        // Use json_encode for the data, wrapped in a PHP return statement.
        // This avoids var_export() which can be exploited if values contain
        // crafted strings that break out of the PHP array syntax.
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $content = "<?php\n\nreturn json_decode('" . addcslashes($json, "'\\") . "', true);\n";

        $path = storage_path('app/vela-site.php');
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
