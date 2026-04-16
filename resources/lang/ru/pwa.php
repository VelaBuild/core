<?php

return [
    // Settings pages
    'settings_general'         => 'Основные',
    'settings_general_desc'    => 'Название сайта, описание и основная информация.',
    'settings_appearance'      => 'Оформление',
    'settings_appearance_desc' => 'Цвета темы и настройка CSS.',
    'settings_pwa'             => 'Прогрессивное веб-приложение',
    'settings_pwa_desc'        => 'Устанавливаемое приложение, офлайн-поддержка и общий доступ.',
    'back_to_settings'         => 'Вернуться к настройкам',
    'manage'                   => 'Управление',
    'save'                     => 'Сохранить настройки',
    'settings_saved'           => 'Настройки успешно сохранены.',

    // General settings
    'site_name'                => 'Название сайта',
    'site_niche'               => 'Тематика сайта',
    'site_description'         => 'Описание сайта',

    // PWA settings
    'enable_pwa'               => 'Включить PWA',
    'manifest_settings'        => 'Настройки манифеста',
    'app_name'                 => 'Название приложения',
    'app_name_help'            => 'Полное название, отображаемое при установке. По умолчанию используется название сайта.',
    'short_name'               => 'Краткое название',
    'short_name_help'          => 'Отображается на домашнем экране. Максимум 12 символов.',
    'pwa_description'          => 'Описание приложения',
    'display_mode'             => 'Режим отображения',
    'theme_color'              => 'Цвет темы',
    'background_color'         => 'Цвет фона',
    'icon_settings'            => 'Иконка приложения',
    'current_icon'             => 'Текущая иконка (все размеры генерируются автоматически)',
    'upload_icon'              => 'Загрузить иконку',
    'icon_requirements'        => 'PNG, JPG или WebP. Минимум 512×512 пикселей. Рекомендуются квадратные изображения.',
    'icons_generated'          => 'Иконки успешно сгенерированы.',
    'icon_generation_failed'   => 'Некоторые размеры иконок не удалось сгенерировать.',
    'offline_settings'         => 'Офлайн и кэширование',
    'enable_offline'           => 'Включить офлайн-поддержку',
    'precache_urls'            => 'URL для предварительного кэширования',
    'precache_urls_help'       => 'Список URL через запятую для кэширования при первом посещении. Главная страница кэшируется всегда.',

    // Offline page
    'offline_title'            => 'Вы офлайн',
    'offline_heading'          => 'Нет подключения к интернету',
    'offline_message'          => 'Проверьте подключение и попробуйте снова.',
    'try_again'                => 'Попробовать снова',

    // Install prompt
    'install_prompt'           => 'Установите это приложение для лучшего опыта',
    'install_button'           => 'Установить',
    'dismiss_button'           => 'Не сейчас',

    // Share
    'share'                    => 'Поделиться',

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
