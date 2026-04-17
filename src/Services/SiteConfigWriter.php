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
