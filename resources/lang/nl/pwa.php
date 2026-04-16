<?php

return [
    // Settings pages
    'settings_general'        => 'Algemeen',
    'settings_general_desc'   => 'Sitenaam, beschrijving en basisinformatie.',
    'settings_appearance'     => 'Uiterlijk',
    'settings_appearance_desc'=> 'Themakleuren en CSS-aanpassing.',
    'settings_pwa'            => 'Progressive Web App',
    'settings_pwa_desc'       => 'Installeerbare app, offline ondersteuning en delen.',
    'back_to_settings'        => 'Terug naar instellingen',
    'manage'                  => 'Beheren',
    'save'                    => 'Instellingen opslaan',
    'settings_saved'          => 'Instellingen succesvol opgeslagen.',

    // General settings
    'site_name'               => 'Sitenaam',
    'site_niche'              => 'Siteniche',
    'site_description'        => 'Sitebeschrijving',

    // PWA settings
    'enable_pwa'              => 'PWA inschakelen',
    'manifest_settings'       => 'Manifestinstellingen',
    'app_name'                => 'Appnaam',
    'app_name_help'           => 'Volledige naam weergegeven bij installatie. Standaard sitenaam als leeg.',
    'short_name'              => 'Korte naam',
    'short_name_help'         => 'Weergegeven op startscherm. Maximaal 12 tekens.',
    'pwa_description'         => 'Appbeschrijving',
    'display_mode'            => 'Weergavemodus',
    'theme_color'             => 'Themakleur',
    'background_color'        => 'Achtergrondkleur',
    'icon_settings'           => 'App-pictogram',
    'current_icon'            => 'Huidig pictogram (alle formaten automatisch gegenereerd)',
    'upload_icon'             => 'Pictogram uploaden',
    'icon_requirements'       => 'PNG, JPG of WebP. Minimaal 512x512 pixels. Vierkante afbeeldingen aanbevolen.',
    'icons_generated'         => 'Pictogrammen succesvol gegenereerd.',
    'icon_generation_failed'  => 'Sommige pictogramformaten konden niet worden gegenereerd.',
    'offline_settings'        => 'Offline en caching',
    'enable_offline'          => 'Offline ondersteuning inschakelen',
    'precache_urls'           => 'URL\'s vooraf cachen',
    'precache_urls_help'      => 'Kommagescheiden lijst van URL\'s om bij eerste bezoek te cachen. De startpagina wordt altijd gecached.',

    // Offline page
    'offline_title'           => 'U bent offline',
    'offline_heading'         => 'Geen internetverbinding',
    'offline_message'         => 'Controleer uw verbinding en probeer het opnieuw.',
    'try_again'               => 'Opnieuw proberen',

    // Install prompt
    'install_prompt'          => 'Installeer deze app voor een betere ervaring',
    'install_button'          => 'Installeren',
    'dismiss_button'          => 'Niet nu',

    // Share
    'share'                   => 'Delen',

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
