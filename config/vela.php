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
    | Public Content API
    |--------------------------------------------------------------------------
    |
    | Read-only JSON API for searching, listing, and fetching published content.
    | Can also be toggled from admin Settings. Default: enabled.
    |
    */

    'public_api_enabled' => env('VELA_PUBLIC_API', true),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'admin' => ['web', 'vela.auth', 'vela.2fa', 'vela.gates', 'vela.locale', 'vela.show-to-edit'],
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
            // The chat job runs after the response inside the web process, so
            // PHP's max_execution_time still applies to it. One image
            // generation alone takes ~40s, well past the usual 30s default.
            'max_execution_seconds' => env('AI_CHAT_MAX_EXECUTION_SECONDS', 600),
            'backup_retention' => 5,
            // Hard cap on the tool-call loop per user message. Real tasks
            // (multi-file edits, test/revise cycles) can chain dozens of calls.
            'max_tool_iterations' => env('AI_CHAT_MAX_TOOL_ITERATIONS', 50),
            // Output token cap per AI call. 4096 (provider default) cuts off
            // long-form writes mid-sentence and triggers MAX_TOKENS bail outs.
            'max_output_tokens' => env('AI_CHAT_MAX_OUTPUT_TOKENS', 16384),
            // Context-window management. Conversation history (esp. large tool
            // results) is trimmed to max_input_tokens before each call; the
            // provider also clamps output so input + output never exceeds
            // context_limit (the model window). Prevents the whole request from
            // 400ing on length, which otherwise replays the same error reply.
            'max_input_tokens' => env('AI_CHAT_MAX_INPUT_TOKENS', 150000),
            'context_limit' => env('AI_CHAT_CONTEXT_LIMIT', 200000),
            // Per-tool-result cap (characters). One huge payload — a fetched
            // page, a file dump, a base64 screenshot — can blow the context
            // window on its own; the model keeps the head + a truncation note.
            'max_tool_result_chars' => env('AI_CHAT_MAX_TOOL_RESULT_CHARS', 24000),
            // Anthropic chat model id. Override via env when Anthropic retires a
            // model (use the exact id, no date suffix — e.g. claude-sonnet-4-6).
            'anthropic_model' => env('AI_CHAT_ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            // Anthropic prompt caching: caches the system prompt + tool schemas
            // + conversation prefix (re-sent on every tool-loop call) so repeat
            // reads bill at ~10% instead of full price. Biggest cost lever.
            'prompt_caching' => env('AI_CHAT_PROMPT_CACHING', true),
            // Inject the provider's native web-search tool (Gemini google_search,
            // Claude web_search_20250305) so the model can ground responses in
            // fresh web results without a custom backend. Falls back to the
            // app-level web_search tool (Brave/Tavily/Serper) when disabled or
            // when the provider has no native option (OpenAI Chat Completions).
            'native_search' => env('AI_CHAT_NATIVE_SEARCH', true),
            'native_search_max_uses' => env('AI_CHAT_NATIVE_SEARCH_MAX_USES', 5),
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

        // How automatic regeneration runs after an edit (page/post/category
        // save, AI CSS change). Default (false): run after the HTTP response
        // is flushed, in the same PHP process — so the front-end reflects
        // edits out of the box, with no separate queue worker to manage.
        // Set true (VELA_STATIC_REGEN_QUEUE=true) only when you run a real
        // queue worker / Horizon and want the heavy regen off web processes.
        'regen_queue' => env('VELA_STATIC_REGEN_QUEUE', false),
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
        'mode' => 'sitewide',                  // sitewide or per_page
        'pay_to' => '',                        // Your wallet address (receives USDC)
        'price_usd' => '0.01',                 // Price per request in USD (default for sitewide or per-page fallback)
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
        // Stable secret used to sign /imgp/ and /imgr/ URLs. MUST match
        // between dev and prod when using committed static cache, otherwise
        // baked-in signed URLs won't validate on prod. Leave null to fall
        // back to APP_KEY (fine for single-env sites).
        'signing_key' => env('VELA_IMAGE_SIGNING_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset Bundling
    |--------------------------------------------------------------------------
    |
    | Combine + minify CSS/JS into hashed bundles that ship with the static
    | cache. Built via `php artisan vela:assets:build` (runs automatically at
    | static cache regeneration time).
    |
    | Each bundle is a named group of source files, combined into one file
    | named `{bundle}-{sha256[:12]}.{css,js}` and written to output_dir.
    | The manifest maps logical names to hashed filenames so the
    | `@velaAssets('name')` directive can emit the right <link>/<script>.
    |
    | Host apps can add or override bundles in their own config/vela.php.
    |
    */

    'assets' => [

        'enabled'  => env('VELA_ASSETS_BUNDLE', true),
        'minify'   => env('VELA_ASSETS_MINIFY', env('APP_ENV', 'production') !== 'local'),

        'output_dir'  => public_path('vendor/vela/bundles'),
        'public_path' => '/vendor/vela/bundles',
        'manifest'    => public_path('vendor/vela/bundles/manifest.json'),

        'bundles' => [

            // Shared CSS/JS loaded on every public page (any template).
            'public' => [
                'css' => [
                    'public/vendor/vela/css/page-blocks.css',
                ],
                'js' => [],
            ],

            // Per-template bundles — layouts include these in addition to `public`.
            'template-corporate' => [
                'css' => [
                    'public/vendor/vela/css/corporate/style.css',
                    'public/vendor/vela/css/corporate/style-deferred.css',
                ],
            ],

            'template-editorial' => [
                'css' => [
                    'public/vendor/vela/css/editorial/style.css',
                    'public/vendor/vela/css/editorial/style-deferred.css',
                ],
            ],

            'template-dark' => [
                'css' => [
                    'public/vendor/vela/css/dark/style.css',
                    'public/vendor/vela/css/dark/style-deferred.css',
                ],
            ],

            'template-modern' => [
                'css' => [
                    'public/vendor/vela/css/modern/style.css',
                    'public/vendor/vela/css/modern/style-deferred.css',
                ],
            ],

            'template-default' => [
                'css' => [
                    'public/vendor/vela/css/premium.css',
                ],
            ],

            // `minimal` has no extra CSS beyond `public`.

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Browser Rendering (Cloudflare Workers)
    |--------------------------------------------------------------------------
    |
    | Cloudflare Browser Rendering for screenshots, PDFs, and server-side
    | rendering. Used by AI coding tools for image-to-site workflows and
    | by the Content API /render endpoint.
    |
    */

    'browser_rendering' => [
        'url' => env('CLOUDFLARE_BROWSER_RENDERING_URL'),
    ],

    'marketplace' => [
        // URL is hard-coded in MarketplaceSettingsService::API_URL — there's
        // one canonical Vela marketplace. Per-site env values cover only the
        // auth credentials.
        'cache_ttl' => env('VELA_MARKETPLACE_CACHE_TTL', 3600),
        'webhook_secret' => env('VELA_MARKETPLACE_WEBHOOK_SECRET'),
    ],

];
