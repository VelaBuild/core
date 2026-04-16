<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route Prefixes
    |--------------------------------------------------------------------------
    */

    'admin_prefix' => 'admin',
    'auth_prefix' => 'vela',

    /*
    |--------------------------------------------------------------------------
    | Public Routes
    |--------------------------------------------------------------------------
    |
    | Set to false to disable the package's public-facing routes (home, posts,
    | categories, pages) so the host application can define its own.
    |
    */

    'enable_public_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'admin' => ['web', 'vela.auth', 'vela.2fa', 'vela.gates', 'vela.locale'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Date & Time Formats
    |--------------------------------------------------------------------------
    */

    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    */

    'primary_language' => 'en',

    'available_languages' => [
        'en'      => 'English',
        'de'      => 'German',
        'ru'      => 'Russian',
        'fr'      => 'French',
        'nl'      => 'Dutch',
        'it'      => 'Italian',
        'ar'      => 'Arabic',
        'dk'      => 'Danish',
        'zh-Hans' => 'Chinese',
        'th'      => 'Thai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    'registration_default_role' => '2',

    /*
    |--------------------------------------------------------------------------
    | Template
    |--------------------------------------------------------------------------
    */

    'template' => [
        'active' => env('SITE_TEMPLATE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Services
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
        'default_text_provider' => env('AI_TEXT_PROVIDER', 'openai'),
        'default_image_provider' => env('AI_IMAGE_PROVIDER', 'gemini'),
        'site_context' => [
            'name' => env('SITE_NAME', 'My Website'),
            'niche' => env('SITE_NICHE', 'general'),
            'description' => env('SITE_DESCRIPTION', ''),
        ],
        'chat' => [
            'rate_limit' => env('AI_CHAT_RATE_LIMIT', 50),
            'max_conversation_messages' => 50,
            'max_undo_depth' => 10,
            'backup_retention' => 5,
        ],
        'figma' => [
            'access_token' => env('FIGMA_ACCESS_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Static Site Generation
    |--------------------------------------------------------------------------
    */

    'static' => [
        'enabled' => env('VELA_CACHE', env('VELA_STATIC_ENABLED', true)),
        'path' => resource_path('static'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Site Visibility
    |--------------------------------------------------------------------------
    |
    | Controls search engine indexing, AI crawler access, and holding pages.
    | Defaults to 'public' — all restrictions are managed via admin settings.
    |
    */

    'visibility' => [
        'mode' => 'public', // 'public' or 'restricted'
        'noindex' => false,
        'block_ai' => false,
        'holding_page' => false,
        'holding_page_id' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | x402 AI Payment
    |--------------------------------------------------------------------------
    |
    | Require AI agents to pay for content access using the x402 protocol.
    | Works independently of the public/restricted visibility mode.
    | Regular browsers are never affected.
    |
    | @see https://x402.org
    |
    */

    'x402' => [
        'enabled' => false,
        'pay_to' => '',                        // Your wallet address (receives USDC)
        'price_usd' => '0.01',                 // Price per request in USD
        'network' => 'base',                   // base, ethereum, polygon, arbitrum, optimism
        'facilitator_url' => 'https://x402.org/facilitator',
        'description' => 'Access to website content',
    ],

    /*
    |--------------------------------------------------------------------------
    | GDPR / Cookie Consent
    |--------------------------------------------------------------------------
    |
    | When enabled, a cookie consent banner is shown to visitors and analytics
    | scripts are blocked until the user grants consent. Set VELA_GDPR=true
    | in .env to activate. The banner is NOT shown until you turn this on.
    |
    */

    'gdpr' => [
        'enabled' => env('VELA_GDPR', false),
        'privacy_url' => env('VELA_PRIVACY_URL', '/privacy'),
        'cookie_name' => 'vela_consent',
        'cookie_lifetime' => 365, // days
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Optimization
    |--------------------------------------------------------------------------
    */

    'images' => [
        'enabled' => env('VELA_IMAGES_ENABLED', true),
        'cache_path' => storage_path('app/image-cache'),
        'max_width' => 2000,
        'max_height' => 2000,
        'quality' => 85,
        'allowed_source_paths' => ['storage/app/public', 'public'],
        'default_sizes' => [400, 800, 1200],
    ],

];
