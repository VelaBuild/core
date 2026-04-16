<?php

return [
    // Settings pages
    'settings_general'        => 'Generelt',
    'settings_general_desc'   => 'Hjemmesidenavn, beskrivelse og grundlæggende oplysninger.',
    'settings_appearance'     => 'Udseende',
    'settings_appearance_desc'=> 'Temafarver og CSS-tilpasning.',
    'settings_pwa'            => 'Progressive Web App',
    'settings_pwa_desc'       => 'Installerbar app, offline-understøttelse og deling.',
    'back_to_settings'        => 'Tilbage til indstillinger',
    'manage'                  => 'Administrer',
    'save'                    => 'Gem indstillinger',
    'settings_saved'          => 'Indstillinger gemt.',

    // General settings
    'site_name'               => 'Hjemmesidenavn',
    'site_niche'              => 'Hjemmesideniche',
    'site_description'        => 'Hjemmesidebeskrivelse',

    // PWA settings
    'enable_pwa'              => 'Aktiver PWA',
    'manifest_settings'       => 'Manifest-indstillinger',
    'app_name'                => 'Appnavn',
    'app_name_help'           => 'Fuldt navn vist ved installation. Bruger hjemmesidenavnet som standard, hvis tomt.',
    'short_name'              => 'Kort navn',
    'short_name_help'         => 'Vises på startskærmen. Maks. 12 tegn.',
    'pwa_description'         => 'Appbeskrivelse',
    'display_mode'            => 'Visningstilstand',
    'theme_color'             => 'Temafarve',
    'background_color'        => 'Baggrundsfarve',
    'icon_settings'           => 'Appikon',
    'current_icon'            => 'Nuværende ikon (alle størrelser genereres automatisk)',
    'upload_icon'             => 'Upload ikon',
    'icon_requirements'       => 'PNG, JPG eller WebP. Minimum 512x512 pixels. Kvadratiske billeder anbefales.',
    'icons_generated'         => 'Ikoner genereret.',
    'icon_generation_failed'  => 'Nogle ikonstørrelser kunne ikke genereres.',
    'offline_settings'        => 'Offline og caching',
    'enable_offline'          => 'Aktiver offline-understøttelse',
    'precache_urls'           => 'Forhåndscache URL\'er',
    'precache_urls_help'      => 'Kommasepareret liste over URL\'er der caches ved første besøg. Startsiden caches altid.',

    // Offline page
    'offline_title'           => 'Du er offline',
    'offline_heading'         => 'Ingen internetforbindelse',
    'offline_message'         => 'Kontroller venligst din forbindelse og prøv igen.',
    'try_again'               => 'Prøv igen',

    // Install prompt
    'install_prompt'          => 'Installer denne app for en bedre oplevelse',
    'install_button'          => 'Installer',
    'dismiss_button'          => 'Ikke nu',

    // Share
    'share'                   => 'Del',

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
