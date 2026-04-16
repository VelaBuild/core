<?php

return [
    // Settings pages
    'settings_general'        => '常规',
    'settings_general_desc'   => '网站名称、描述和基本信息。',
    'settings_appearance'     => '外观',
    'settings_appearance_desc'=> '主题颜色和 CSS 自定义。',
    'settings_pwa'            => '渐进式网页应用',
    'settings_pwa_desc'       => '可安装应用、离线支持和分享。',
    'back_to_settings'        => '返回设置',
    'manage'                  => '管理',
    'save'                    => '保存设置',
    'settings_saved'          => '设置保存成功。',

    // General settings
    'site_name'               => '网站名称',
    'site_niche'              => '网站定位',
    'site_description'        => '网站描述',

    // PWA settings
    'enable_pwa'              => '启用 PWA',
    'manifest_settings'       => '清单设置',
    'app_name'                => '应用名称',
    'app_name_help'           => '安装时显示的完整名称。若为空则默认使用网站名称。',
    'short_name'              => '简短名称',
    'short_name_help'         => '显示在主屏幕上。最多 12 个字符。',
    'pwa_description'         => '应用描述',
    'display_mode'            => '显示模式',
    'theme_color'             => '主题颜色',
    'background_color'        => '背景颜色',
    'icon_settings'           => '应用图标',
    'current_icon'            => '当前图标（所有尺寸自动生成）',
    'upload_icon'             => '上传图标',
    'icon_requirements'       => 'PNG、JPG 或 WebP 格式。最小 512x512 像素。建议使用正方形图片。',
    'icons_generated'         => '图标生成成功。',
    'icon_generation_failed'  => '部分图标尺寸无法生成。',
    'offline_settings'        => '离线与缓存',
    'enable_offline'          => '启用离线支持',
    'precache_urls'           => '预缓存 URL',
    'precache_urls_help'      => '首次访问时要缓存的 URL 列表（逗号分隔）。首页始终会被缓存。',

    // Offline page
    'offline_title'           => '您已离线',
    'offline_heading'         => '无网络连接',
    'offline_message'         => '请检查您的网络连接后重试。',
    'try_again'               => '重试',

    // Install prompt
    'install_prompt'          => '安装此应用以获得更好的体验',
    'install_button'          => '安装',
    'dismiss_button'          => '暂不',

    // Share
    'share'                   => '分享',

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
