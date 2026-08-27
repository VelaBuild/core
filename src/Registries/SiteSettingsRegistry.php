<?php

namespace VelaBuild\Core\Registries;

/**
 * The settings this site knows about, grouped as the admin presents them.
 *
 * Kept here rather than on the controller that renders them so that code
 * outside the HTTP layer can ask whether a key is a real setting. Anything
 * writing settings needs that question answered: a key with no row yet is
 * not necessarily invented — a freshly installed site stores a row only for
 * what someone has already saved.
 */
class SiteSettingsRegistry
{
    public const GROUPS = [
        'general' => ['site_name', 'site_niche', 'site_tagline', 'site_description'],
        'pwa' => ['pwa_enabled', 'pwa_name', 'pwa_short_name', 'pwa_description', 'pwa_display', 'pwa_theme_color', 'pwa_background_color', 'pwa_icon_source', 'pwa_precache_urls', 'pwa_offline_enabled', 'sw_version'],
        'app' => ['app_ios_url', 'app_android_url', 'app_name', 'app_custom_scheme'],
        'gdpr' => ['gdpr_enabled', 'gdpr_privacy_url'],
        'visibility' => ['visibility_mode', 'visibility_noindex', 'visibility_block_ai', 'visibility_holding_page', 'visibility_holding_page_id',
            'x402_enabled', 'x402_mode', 'x402_pay_to', 'x402_price_usd', 'x402_network', 'x402_description',
            'content_signal_ai_train', 'content_signal_search', 'content_signal_ai_input'],
    ];

    /**
     * Every settable key, without its grouping.
     */
    public static function keys(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function knows(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
