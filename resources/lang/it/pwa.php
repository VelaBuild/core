<?php

return [
    // Settings pages
    'settings_general'        => 'Generale',
    'settings_general_desc'   => 'Nome del sito, descrizione e informazioni di base.',
    'settings_appearance'     => 'Aspetto',
    'settings_appearance_desc'=> 'Colori del tema e personalizzazione CSS.',
    'settings_pwa'            => 'Progressive Web App',
    'settings_pwa_desc'       => 'App installabile, supporto offline e condivisione.',
    'back_to_settings'        => 'Torna alle impostazioni',
    'manage'                  => 'Gestisci',
    'save'                    => 'Salva impostazioni',
    'settings_saved'          => 'Impostazioni salvate con successo.',

    // General settings
    'site_name'               => 'Nome sito',
    'site_niche'              => 'Nicchia sito',
    'site_description'        => 'Descrizione sito',

    // PWA settings
    'enable_pwa'              => 'Abilita PWA',
    'manifest_settings'       => 'Impostazioni manifest',
    'app_name'                => 'Nome app',
    'app_name_help'           => 'Nome completo mostrato durante l\'installazione. Se vuoto, utilizza il nome del sito.',
    'short_name'              => 'Nome breve',
    'short_name_help'         => 'Mostrato nella schermata home. Massimo 12 caratteri.',
    'pwa_description'         => 'Descrizione app',
    'display_mode'            => 'Modalità di visualizzazione',
    'theme_color'             => 'Colore tema',
    'background_color'        => 'Colore sfondo',
    'icon_settings'           => 'Icona app',
    'current_icon'            => 'Icona corrente (tutte le dimensioni generate automaticamente)',
    'upload_icon'             => 'Carica icona',
    'icon_requirements'       => 'PNG, JPG o WebP. Minimo 512x512 pixel. Si consigliano immagini quadrate.',
    'icons_generated'         => 'Icone generate con successo.',
    'icon_generation_failed'  => 'Alcune dimensioni delle icone non hanno potuto essere generate.',
    'offline_settings'        => 'Offline e cache',
    'enable_offline'          => 'Abilita supporto offline',
    'precache_urls'           => 'URL da pre-cache',
    'precache_urls_help'      => 'Elenco di URL separati da virgola da memorizzare alla prima visita. La homepage è sempre memorizzata.',

    // Offline page
    'offline_title'           => 'Sei offline',
    'offline_heading'         => 'Nessuna connessione internet',
    'offline_message'         => 'Controlla la tua connessione e riprova.',
    'try_again'               => 'Riprova',

    // Install prompt
    'install_prompt'          => 'Installa questa app per un\'esperienza migliore',
    'install_button'          => 'Installa',
    'dismiss_button'          => 'Non ora',

    // Share
    'share'                   => 'Condividi',

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
