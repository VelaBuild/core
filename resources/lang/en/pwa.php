<?php

return [
    // Settings pages
    'settings_general'        => 'General',
    'settings_general_desc'   => 'Site name, description, and basic information.',
    'settings_appearance'     => 'Appearance',
    'settings_appearance_desc'=> 'Theme colors and CSS customization.',
    'settings_pwa'            => 'Progressive Web App',
    'settings_pwa_desc'       => 'Installable app, offline support, and sharing.',
    'back_to_settings'        => 'Back to Settings',
    'manage'                  => 'Manage',
    'save'                    => 'Save Settings',
    'settings_saved'          => 'Settings saved successfully.',

    // General settings
    'site_name'               => 'Site Name',
    'site_niche'              => 'Site Niche',
    'site_description'        => 'Site Description',

    // PWA settings
    'enable_pwa'              => 'Enable PWA',
    'manifest_settings'       => 'Manifest Settings',
    'app_name'                => 'App Name',
    'app_name_help'           => 'Full name shown when installing. Defaults to site name if empty.',
    'short_name'              => 'Short Name',
    'short_name_help'         => 'Shown on home screen. Max 12 characters.',
    'pwa_description'         => 'App Description',
    'display_mode'            => 'Display Mode',
    'theme_color'             => 'Theme Color',
    'background_color'        => 'Background Color',
    'icon_settings'           => 'App Icon',
    'current_icon'            => 'Current icon (all sizes auto-generated)',
    'upload_icon'             => 'Upload Icon',
    'icon_requirements'       => 'PNG, JPG, or WebP. Minimum 512x512 pixels. Square images recommended.',
    'icons_generated'         => 'Icons generated successfully.',
    'icon_generation_failed'  => 'Some icon sizes could not be generated.',
    'offline_settings'        => 'Offline & Caching',
    'enable_offline'          => 'Enable Offline Support',
    'precache_urls'           => 'Pre-cache URLs',
    'precache_urls_help'      => 'Comma-separated list of URLs to cache on first visit. Homepage is always cached.',

    // Offline page
    'offline_title'           => 'You are offline',
    'offline_heading'         => 'No internet connection',
    'offline_message'         => 'Please check your connection and try again.',
    'try_again'               => 'Try Again',

    // Install prompt
    'install_prompt'          => 'Install this app for a better experience',
    'install_button'          => 'Install',
    'dismiss_button'          => 'Not now',

    // Share
    'share'                   => 'Share',

    // App settings
    'settings_app'            => 'Native App',
    'settings_app_desc'       => 'App store links, native app configuration, and build commands.',
    'app_settings_info'       => 'Configure your native app store links here. Use the Artisan commands below to generate and build a Capacitor project for Android and iOS.',
    'app_store_links'         => 'App Store Links',
    'app_ios_url'             => 'Apple App Store URL',
    'app_android_url'         => 'Google Play Store URL',
    'app_configuration'       => 'App Configuration',
    'app_display_name'        => 'App Display Name',
    'app_name_placeholder'    => 'Defaults to PWA name if empty',
    'app_name_help_text'      => 'The name displayed under the app icon. If empty, the PWA name or site name will be used.',
    'app_custom_scheme'       => 'Custom URL Scheme',
    'app_custom_scheme_help'  => 'A custom URL scheme for deep linking (e.g., myapp://). Optional.',
    'app_cli_commands'        => 'CLI Commands',
    'app_init_command'        => 'Initialize Capacitor project',
    'app_build_command'       => 'Build native app',
];
