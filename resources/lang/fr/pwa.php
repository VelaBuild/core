<?php

return [
    // Settings pages
    'settings_general'        => 'Général',
    'settings_general_desc'   => 'Nom du site, description et informations de base.',
    'settings_appearance'     => 'Apparence',
    'settings_appearance_desc'=> 'Couleurs du thème et personnalisation CSS.',
    'settings_pwa'            => 'Application Web Progressive',
    'settings_pwa_desc'       => 'Application installable, support hors ligne et partage.',
    'back_to_settings'        => 'Retour aux paramètres',
    'manage'                  => 'Gérer',
    'save'                    => 'Enregistrer les paramètres',
    'settings_saved'          => 'Paramètres enregistrés avec succès.',

    // General settings
    'site_name'               => 'Nom du site',
    'site_niche'              => 'Niche du site',
    'site_description'        => 'Description du site',

    // PWA settings
    'enable_pwa'              => 'Activer le PWA',
    'manifest_settings'       => 'Paramètres du manifeste',
    'app_name'                => 'Nom de l\'application',
    'app_name_help'           => 'Nom complet affiché lors de l\'installation. Par défaut, le nom du site si vide.',
    'short_name'              => 'Nom court',
    'short_name_help'         => 'Affiché sur l\'écran d\'accueil. Maximum 12 caractères.',
    'pwa_description'         => 'Description de l\'application',
    'display_mode'            => 'Mode d\'affichage',
    'theme_color'             => 'Couleur du thème',
    'background_color'        => 'Couleur d\'arrière-plan',
    'icon_settings'           => 'Icône de l\'application',
    'current_icon'            => 'Icône actuelle (toutes tailles générées automatiquement)',
    'upload_icon'             => 'Téléverser l\'icône',
    'icon_requirements'       => 'PNG, JPG ou WebP. Minimum 512x512 pixels. Images carrées recommandées.',
    'icons_generated'         => 'Icônes générées avec succès.',
    'icon_generation_failed'  => 'Certaines tailles d\'icônes n\'ont pas pu être générées.',
    'offline_settings'        => 'Hors ligne et mise en cache',
    'enable_offline'          => 'Activer le support hors ligne',
    'precache_urls'           => 'URLs à pré-mettre en cache',
    'precache_urls_help'      => 'Liste d\'URLs séparées par des virgules à mettre en cache lors de la première visite. La page d\'accueil est toujours mise en cache.',

    // Offline page
    'offline_title'           => 'Vous êtes hors ligne',
    'offline_heading'         => 'Pas de connexion Internet',
    'offline_message'         => 'Veuillez vérifier votre connexion et réessayer.',
    'try_again'               => 'Réessayer',

    // Install prompt
    'install_prompt'          => 'Installez cette application pour une meilleure expérience',
    'install_button'          => 'Installer',
    'dismiss_button'          => 'Pas maintenant',

    // Share
    'share'                   => 'Partager',

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
