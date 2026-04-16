<?php

return [
    // Settings pages
    'settings_general'        => 'ทั่วไป',
    'settings_general_desc'   => 'ชื่อเว็บไซต์ คำอธิบาย และข้อมูลพื้นฐาน',
    'settings_appearance'     => 'การแสดงผล',
    'settings_appearance_desc'=> 'สีธีมและการปรับแต่ง CSS',
    'settings_pwa'            => 'Progressive Web App',
    'settings_pwa_desc'       => 'แอปที่ติดตั้งได้ รองรับออฟไลน์ และการแชร์',
    'back_to_settings'        => 'กลับไปยังการตั้งค่า',
    'manage'                  => 'จัดการ',
    'save'                    => 'บันทึกการตั้งค่า',
    'settings_saved'          => 'บันทึกการตั้งค่าเรียบร้อยแล้ว',

    // General settings
    'site_name'               => 'ชื่อเว็บไซต์',
    'site_niche'              => 'หัวข้อหลักของเว็บไซต์',
    'site_description'        => 'คำอธิบายเว็บไซต์',

    // PWA settings
    'enable_pwa'              => 'เปิดใช้งาน PWA',
    'manifest_settings'       => 'การตั้งค่า Manifest',
    'app_name'                => 'ชื่อแอป',
    'app_name_help'           => 'ชื่อเต็มที่แสดงเมื่อติดตั้ง หากเว้นว่างจะใช้ชื่อเว็บไซต์',
    'short_name'              => 'ชื่อย่อ',
    'short_name_help'         => 'แสดงบนหน้าจอหลัก ไม่เกิน 12 ตัวอักษร',
    'pwa_description'         => 'คำอธิบายแอป',
    'display_mode'            => 'โหมดการแสดงผล',
    'theme_color'             => 'สีธีม',
    'background_color'        => 'สีพื้นหลัง',
    'icon_settings'           => 'ไอคอนแอป',
    'current_icon'            => 'ไอคอนปัจจุบัน (สร้างทุกขนาดอัตโนมัติ)',
    'upload_icon'             => 'อัปโหลดไอคอน',
    'icon_requirements'       => 'PNG, JPG หรือ WebP ขนาดขั้นต่ำ 512x512 พิกเซล แนะนำให้ใช้รูปภาพสี่เหลี่ยมจัตุรัส',
    'icons_generated'         => 'สร้างไอคอนเรียบร้อยแล้ว',
    'icon_generation_failed'  => 'ไม่สามารถสร้างไอคอนบางขนาดได้',
    'offline_settings'        => 'ออฟไลน์และการแคช',
    'enable_offline'          => 'เปิดใช้งานรองรับออฟไลน์',
    'precache_urls'           => 'URL ที่แคชล่วงหน้า',
    'precache_urls_help'      => 'รายการ URL ที่คั่นด้วยลูกน้ำเพื่อแคชตั้งแต่การเข้าชมครั้งแรก หน้าแรกจะถูกแคชเสมอ',

    // Offline page
    'offline_title'           => 'คุณอยู่ในโหมดออฟไลน์',
    'offline_heading'         => 'ไม่มีการเชื่อมต่ออินเทอร์เน็ต',
    'offline_message'         => 'กรุณาตรวจสอบการเชื่อมต่อของคุณแล้วลองอีกครั้ง',
    'try_again'               => 'ลองอีกครั้ง',

    // Install prompt
    'install_prompt'          => 'ติดตั้งแอปนี้เพื่อประสบการณ์ที่ดียิ่งขึ้น',
    'install_button'          => 'ติดตั้ง',
    'dismiss_button'          => 'ไม่ใช่ตอนนี้',

    // Share
    'share'                   => 'แชร์',

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
