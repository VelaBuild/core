<?php

return [
    // Settings pages
    'settings_general'        => 'Allgemein',
    'settings_general_desc'   => 'Websitename, Beschreibung und grundlegende Informationen.',
    'settings_appearance'     => 'Erscheinungsbild',
    'settings_appearance_desc'=> 'Theme-Farben und CSS-Anpassung.',
    'settings_pwa'            => 'Progressive Web App',
    'settings_pwa_desc'       => 'Installierbare App, Offline-Unterstützung und Teilen.',
    'back_to_settings'        => 'Zurück zu den Einstellungen',
    'manage'                  => 'Verwalten',
    'save'                    => 'Einstellungen speichern',
    'settings_saved'          => 'Einstellungen erfolgreich gespeichert.',

    // General settings
    'site_name'               => 'Websitename',
    'site_niche'              => 'Website-Nische',
    'site_description'        => 'Website-Beschreibung',

    // PWA settings
    'enable_pwa'              => 'PWA aktivieren',
    'manifest_settings'       => 'Manifest-Einstellungen',
    'app_name'                => 'App-Name',
    'app_name_help'           => 'Vollständiger Name, der bei der Installation angezeigt wird. Standardmäßig wird der Websitename verwendet, wenn leer.',
    'short_name'              => 'Kurzname',
    'short_name_help'         => 'Wird auf dem Startbildschirm angezeigt. Maximal 12 Zeichen.',
    'pwa_description'         => 'App-Beschreibung',
    'display_mode'            => 'Anzeigemodus',
    'theme_color'             => 'Theme-Farbe',
    'background_color'        => 'Hintergrundfarbe',
    'icon_settings'           => 'App-Symbol',
    'current_icon'            => 'Aktuelles Symbol (alle Größen werden automatisch generiert)',
    'upload_icon'             => 'Symbol hochladen',
    'icon_requirements'       => 'PNG, JPG oder WebP. Mindestens 512x512 Pixel. Quadratische Bilder empfohlen.',
    'icons_generated'         => 'Symbole erfolgreich generiert.',
    'icon_generation_failed'  => 'Einige Symbolgrößen konnten nicht generiert werden.',
    'offline_settings'        => 'Offline & Caching',
    'enable_offline'          => 'Offline-Unterstützung aktivieren',
    'precache_urls'           => 'URLs vorab zwischenspeichern',
    'precache_urls_help'      => 'Kommagetrennte Liste von URLs, die beim ersten Besuch zwischengespeichert werden. Die Startseite wird immer zwischengespeichert.',

    // Offline page
    'offline_title'           => 'Sie sind offline',
    'offline_heading'         => 'Keine Internetverbindung',
    'offline_message'         => 'Bitte überprüfen Sie Ihre Verbindung und versuchen Sie es erneut.',
    'try_again'               => 'Erneut versuchen',

    // Install prompt
    'install_prompt'          => 'Installieren Sie diese App für ein besseres Erlebnis',
    'install_button'          => 'Installieren',
    'dismiss_button'          => 'Nicht jetzt',

    // Share
    'share'                   => 'Teilen',

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
