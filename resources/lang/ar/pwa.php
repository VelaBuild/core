<?php

return [
    // Settings pages
    'settings_general'        => 'عام',
    'settings_general_desc'   => 'اسم الموقع والوصف والمعلومات الأساسية.',
    'settings_appearance'     => 'المظهر',
    'settings_appearance_desc'=> 'ألوان القالب وتخصيص CSS.',
    'settings_pwa'            => 'تطبيق الويب التقدمي',
    'settings_pwa_desc'       => 'تطبيق قابل للتثبيت، ودعم العمل دون اتصال، والمشاركة.',
    'back_to_settings'        => 'العودة إلى الإعدادات',
    'manage'                  => 'إدارة',
    'save'                    => 'حفظ الإعدادات',
    'settings_saved'          => 'تم حفظ الإعدادات بنجاح.',

    // General settings
    'site_name'               => 'اسم الموقع',
    'site_niche'              => 'مجال الموقع',
    'site_description'        => 'وصف الموقع',

    // PWA settings
    'enable_pwa'              => 'تفعيل PWA',
    'manifest_settings'       => 'إعدادات الملف التعريفي',
    'app_name'                => 'اسم التطبيق',
    'app_name_help'           => 'الاسم الكامل المعروض عند التثبيت. يُستخدم اسم الموقع افتراضياً إذا تُرك فارغاً.',
    'short_name'              => 'الاسم المختصر',
    'short_name_help'         => 'يُعرض على الشاشة الرئيسية. بحد أقصى 12 حرفاً.',
    'pwa_description'         => 'وصف التطبيق',
    'display_mode'            => 'وضع العرض',
    'theme_color'             => 'لون القالب',
    'background_color'        => 'لون الخلفية',
    'icon_settings'           => 'أيقونة التطبيق',
    'current_icon'            => 'الأيقونة الحالية (يتم توليد جميع الأحجام تلقائياً)',
    'upload_icon'             => 'رفع أيقونة',
    'icon_requirements'       => 'PNG أو JPG أو WebP. الحد الأدنى 512×512 بكسل. يُنصح باستخدام صور مربعة.',
    'icons_generated'         => 'تم توليد الأيقونات بنجاح.',
    'icon_generation_failed'  => 'تعذر توليد بعض أحجام الأيقونات.',
    'offline_settings'        => 'العمل دون اتصال والتخزين المؤقت',
    'enable_offline'          => 'تفعيل دعم العمل دون اتصال',
    'precache_urls'           => 'عناوين URL للتخزين المسبق',
    'precache_urls_help'      => 'قائمة عناوين URL مفصولة بفواصل للتخزين عند أول زيارة. يتم دائماً تخزين الصفحة الرئيسية.',

    // Offline page
    'offline_title'           => 'أنت غير متصل بالإنترنت',
    'offline_heading'         => 'لا يوجد اتصال بالإنترنت',
    'offline_message'         => 'يرجى التحقق من اتصالك والمحاولة مرة أخرى.',
    'try_again'               => 'حاول مرة أخرى',

    // Install prompt
    'install_prompt'          => 'ثبّت هذا التطبيق للحصول على تجربة أفضل',
    'install_button'          => 'تثبيت',
    'dismiss_button'          => 'ليس الآن',

    // Share
    'share'                   => 'مشاركة',

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
