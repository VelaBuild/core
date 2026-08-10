<?php

namespace VelaBuild\Core;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class VelaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Helper functions — loaded defensively in addition to composer's
        // `files` autoload entry, so path-repo installs that haven't run
        // `composer dump-autoload` after a core upgrade don't 500 on calls
        // to functions declared in these files. Each file is `function_exists`-guarded internally.
        require_once __DIR__.'/Helpers/cache_tag.php';
        require_once __DIR__.'/Helpers/links.php';

        // Deep-merge: host app's config/vela.php wins on conflict, package fills
        // in everything else. Laravel's default mergeConfigFrom is shallow,
        // which would drop package-default nested arrays (e.g. asset bundles)
        // the moment a host app defines ANY value under the same top-level key.
        $packageConfig = require __DIR__.'/../config/vela.php';
        $appConfig = $this->app['config']->get('vela', []);
        $this->app['config']->set('vela', array_replace_recursive($packageConfig, $appConfig));

        $this->app->singleton(\VelaBuild\Core\Vela::class, function ($app) {
            return new \VelaBuild\Core\Vela(
                new \VelaBuild\Core\Registries\BlockRegistry(),
                new \VelaBuild\Core\Registries\MenuRegistry(),
                new \VelaBuild\Core\Registries\TemplateRegistry(),
                new \VelaBuild\Core\Registries\WidgetRegistry(),
                new \VelaBuild\Core\Registries\ToolRegistry(),
                new \VelaBuild\Core\Registries\ProfileMenuRegistry(),
                new \VelaBuild\Core\Registries\FrontMenuRegistry(),
                new \VelaBuild\Core\Registries\SettingsNavRegistry()
            );
        });

        $this->app->singleton(\VelaBuild\Core\Services\AiProviderManager::class, function ($app) {
            return new \VelaBuild\Core\Services\AiProviderManager();
        });

        $this->app->singleton(\VelaBuild\Core\Services\AssetBundler::class);

        $this->app->singleton(\VelaBuild\Core\Services\MenuRenderer::class);

        $this->app->singleton(\VelaBuild\Core\Services\ToolSettingsService::class, function ($app) {
            return new \VelaBuild\Core\Services\ToolSettingsService();
        });

        // Singleton so events queued in a controller survive to the layout
        // partial where they're flushed into window.__velaTrack.
        $this->app->singleton(\VelaBuild\Core\Services\TrackingService::class, function ($app) {
            return new \VelaBuild\Core\Services\TrackingService(
                $app->make(\VelaBuild\Core\Services\ToolSettingsService::class)
            );
        });

        // One instance per HTTP request — Laravel re-boots the container per
        // request so singleton semantics are what we want. Collects
        // Cloudflare Cache-Tag entries from controllers/views; emitted as a
        // single header by the EmitCacheTags middleware at request end.
        $this->app->singleton(\VelaBuild\Core\Services\CacheTagger::class);

        $this->app->singleton(\VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService::class, function ($app) {
            return new \VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService();
        });

        config([
            'auth.guards.vela' => [
                'driver' => 'session',
                'provider' => 'vela_users',
            ],
            'auth.providers.vela_users' => [
                'driver' => 'eloquent',
                'model' => \VelaBuild\Core\Models\VelaUser::class,
            ],
            'auth.passwords.vela_users' => [
                'provider' => 'vela_users',
                'table' => 'vela_password_resets',
                'expire' => 60,
                'throttle' => 60,
            ],
        ]);
    }

    public function boot(): void
    {
        // Load site config from static file (0 DB queries, safe permissions)
        $siteConfigPath = storage_path('app/vela-site.php');
        if (file_exists($siteConfigPath)) {
            $siteConfig = include $siteConfigPath;
            if (is_array($siteConfig)) {
                if (! empty($siteConfig['site_name'])) {
                    config(['app.name' => $siteConfig['site_name']]);
                }
                if (! empty($siteConfig['site_tagline'])) {
                    config(['vela.site.tagline' => $siteConfig['site_tagline']]);
                }
                if (! empty($siteConfig['active_template'])) {
                    config(['vela.template.active' => $siteConfig['active_template']]);
                }
                if (! empty($siteConfig['custom_css_global'])) {
                    config(['vela.site.custom_css_global' => $siteConfig['custom_css_global']]);
                }
                if (! empty($siteConfig['theme']) && is_array($siteConfig['theme'])) {
                    foreach ($siteConfig['theme'] as $key => $value) {
                        $optionName = substr($key, 6); // strip 'theme_' prefix
                        config(["vela.theme.{$optionName}" => $value]);
                    }
                }
                // Site visibility settings
                if (isset($siteConfig['visibility_mode'])) {
                    config(['vela.visibility.mode' => $siteConfig['visibility_mode']]);
                    config(['vela.visibility.noindex' => !empty($siteConfig['visibility_noindex'])]);
                    config(['vela.visibility.block_ai' => !empty($siteConfig['visibility_block_ai'])]);
                    config(['vela.visibility.holding_page' => !empty($siteConfig['visibility_holding_page'])]);
                    config(['vela.visibility.holding_page_id' => $siteConfig['visibility_holding_page_id'] ?? '']);
                    config(['vela.visibility.holding_page_slug' => $siteConfig['visibility_holding_page_slug'] ?? '']);
                }
                // x402 AI Payment settings
                if (isset($siteConfig['x402_enabled'])) {
                    config(['vela.x402.enabled' => (bool) $siteConfig['x402_enabled']]);
                }
                if (!empty($siteConfig['x402_mode'])) {
                    config(['vela.x402.mode' => $siteConfig['x402_mode']]);
                }
                if (!empty($siteConfig['x402_pay_to'])) {
                    config(['vela.x402.pay_to' => $siteConfig['x402_pay_to']]);
                }
                if (isset($siteConfig['x402_price_usd'])) {
                    config(['vela.x402.price_usd' => $siteConfig['x402_price_usd']]);
                }
                if (!empty($siteConfig['x402_network'])) {
                    config(['vela.x402.network' => $siteConfig['x402_network']]);
                }
                if (!empty($siteConfig['x402_description'])) {
                    config(['vela.x402.description' => $siteConfig['x402_description']]);
                }
                // GDPR: DB values override .env defaults when admin has set them
                if (isset($siteConfig['gdpr_enabled'])) {
                    config(['vela.gdpr.enabled' => (bool) $siteConfig['gdpr_enabled']]);
                }
                if (! empty($siteConfig['gdpr_privacy_url'])) {
                    config(['vela.gdpr.privacy_url' => $siteConfig['gdpr_privacy_url']]);
                }
            }
        }

        // Load marketplace license cache
        $licenseConfigPath = storage_path('app/vela-licenses.php');
        if (file_exists($licenseConfigPath)) {
            $this->app->instance('vela.licenses', include $licenseConfigPath);
        } else {
            $this->app->instance('vela.licenses', []);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vela');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vela');

        // Share navigation data across every registered template's layout view.
        // Templates auto-discover from core + app-local, so we build the view
        // list dynamically. We cover three resolution paths the helper uses:
        //   1. Host app views (resources/views/templates/{name}/layout.blade.php)
        //   2. Package namespace (vela::templates.{name}.layout)
        //   3. The template's own registered namespace (e.g. vela-site::layout)
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);
        $layoutViews = ['vela::templates.*.layout', 'templates.*.layout'];
        foreach ($vela->templates()->all() as $template) {
            if (! empty($template['namespace'])) {
                $layoutViews[] = $template['namespace'] . '::layout';
            }
        }
        \Illuminate\Support\Facades\View::composer(
            $layoutViews,
            \VelaBuild\Core\View\Composers\TemplateComposer::class
        );

        // GA4 tag injection via view composer
        \Illuminate\Support\Facades\View::composer('vela::layouts.public', function ($view) {
            $settings = app(\VelaBuild\Core\Services\ToolSettingsService::class);
            $view->with('gaTrackingId', $settings->get('ga_measurement_id'));
        });

        // Register routes
        $this->registerHomeRedirect();
        $this->registerAdminRoutes();
        $this->registerMcpRoutes();
        $this->registerContentApiRoutes();
        $this->registerAgentDiscoveryRoutes();
        $this->registerWebhookRoutes();
        $this->registerAuthRoutes();

        // Laravel's built-in ResetPassword + VerifyEmail notifications call
        // route('password.reset', …) and route('verification.verify', …),
        // but our auth routes live under the `vela.auth.*` prefix. Register
        // URL builders so these notifications point at our prefixed routes
        // without throwing RouteNotFoundException.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return route('vela.auth.password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
        \Illuminate\Auth\Notifications\VerifyEmail::createUrlUsing(function ($notifiable) {
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'vela.auth.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id'   => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
        // Image optimization routes (outside locale group)
        Route::middleware('web')->group(function () {
            Route::get('/imgp/{config}', [\VelaBuild\Core\Http\Controllers\ImageController::class, 'webp'])
                ->name('vela.image.webp')
                ->where('config', '[A-Za-z0-9_\-+=]+');
            Route::get('/imgr/{config}', [\VelaBuild\Core\Http\Controllers\ImageController::class, 'resize'])
                ->name('vela.image.resize')
                ->where('config', '[A-Za-z0-9_\-+=]+');
            Route::get('/api/csrf-token', function () {
                return response()->json(['token' => csrf_token()]);
            })->name('vela.api.csrf-token');
            // PWA manifest — default locale at /manifest.json, others at /{locale}/manifest.json
            Route::get('/manifest.json', [\VelaBuild\Core\Http\Controllers\Public\ManifestController::class, 'show'])
                ->name('vela.manifest');
            Route::get('/{locale}/manifest.json', [\VelaBuild\Core\Http\Controllers\Public\ManifestController::class, 'show'])
                ->name('vela.manifest.localized')
                ->where('locale', '[a-z]{2}');
            Route::get('/sw.js', [\VelaBuild\Core\Http\Controllers\Public\ServiceWorkerController::class, 'show'])
                ->name('vela.sw');
            Route::get('/offline', [\VelaBuild\Core\Http\Controllers\Public\OfflineController::class, 'show'])
                ->name('vela.offline');
            Route::get('/robots.txt', [\VelaBuild\Core\Http\Controllers\Public\RobotsController::class, 'show'])
                ->name('vela.robots');
        });

        // Public routes must load LAST to avoid catch-all swallowing host routes
        $this->app->booted(function () {
            $this->registerPublicRoutes();
        });

        // Middleware aliases (Laravel 11 style)
        $router = $this->app->make(\Illuminate\Routing\Router::class);
        $router->aliasMiddleware('vela.auth', \VelaBuild\Core\Http\Middleware\VelaAuthenticate::class);
        $router->aliasMiddleware('vela.2fa', \VelaBuild\Core\Http\Middleware\VelaTwoFactor::class);
        $router->aliasMiddleware('vela.gates', \VelaBuild\Core\Http\Middleware\VelaAuthGates::class);
        $router->aliasMiddleware('vela.locale', \VelaBuild\Core\Http\Middleware\VelaSetLocale::class);
        $router->aliasMiddleware('vela.template', \VelaBuild\Core\Http\Middleware\VelaSetTemplate::class);
        $router->aliasMiddleware('vela.visibility', \VelaBuild\Core\Http\Middleware\VelaSiteVisibility::class);
        $router->aliasMiddleware('vela.x402', \VelaBuild\Core\Http\Middleware\VelaX402Payment::class);
        $router->aliasMiddleware('vela.mcp', \VelaBuild\Core\Http\Middleware\VelaMcpAuth::class);
        $router->aliasMiddleware('vela.show-to-edit', \VelaBuild\Core\Http\Middleware\VelaRedirectShowToEdit::class);

        $router->pushMiddlewareToGroup('web', \VelaBuild\Core\Http\Middleware\AgentDiscovery::class);

        // Cloudflare Cache-Tag emission for every public (web) response.
        // Pushed onto the `web` group so host apps don't need to register
        // the middleware themselves. Non-GET / non-2xx responses are
        // skipped by the middleware itself.
        $router->pushMiddlewareToGroup('web', \VelaBuild\Core\Http\Middleware\EmitCacheTags::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \VelaBuild\Core\Commands\VelaInstall::class,
                \VelaBuild\Core\Commands\VelaDoctor::class,
                \VelaBuild\Core\Commands\QueueWork::class,
                \VelaBuild\Core\Commands\ProcessContentImages::class,
                \VelaBuild\Core\Commands\FindMissingTranslations::class,
                \VelaBuild\Core\Commands\Translate::class,
                \VelaBuild\Core\Commands\GenerateMissingCategoryImages::class,
                \VelaBuild\Core\Commands\SetupGraphics::class,
                \VelaBuild\Core\Commands\ResetContent::class,
                \VelaBuild\Core\Commands\FixTextBlocks::class,
                \VelaBuild\Core\Commands\VelaPublish::class,
                \VelaBuild\Core\Commands\GenerateStatic::class,
                \VelaBuild\Core\Commands\ImportContent::class,
                \VelaBuild\Core\Commands\CleanupImageCache::class,
                \VelaBuild\Core\Commands\CreateContent::class,
                \VelaBuild\Core\Commands\CustomizeTemplate::class,
                \VelaBuild\Core\Commands\GenerateImage::class,
                \VelaBuild\Core\Commands\AiWizard::class,
                \VelaBuild\Core\Commands\ThemeCheck::class,
                \VelaBuild\Core\Commands\DesignToSite::class,
                \VelaBuild\Core\Commands\GenerateThemeScreenshots::class,
                \VelaBuild\Core\Commands\AppInit::class,
                \VelaBuild\Core\Commands\AppBuild::class,
                \VelaBuild\Core\Commands\BuildAssets::class,
                \VelaBuild\Core\Commands\RunChecks::class,
                \VelaBuild\Core\Commands\PackageInstallCommand::class,
                \VelaBuild\Core\Commands\PackageRemoveCommand::class,
                \VelaBuild\Core\Commands\PackageUpdateCommand::class,
                \VelaBuild\Core\Commands\PackageListCommand::class,
                \VelaBuild\Core\Commands\SafeModeCommand::class,
                \VelaBuild\Core\Commands\RepairContentJson::class,
            ]);
        }

        // Admin UI anonymous Blade components — <x-vela::edit-page>, etc.
        // Lives in core/resources/views/components/. Used by admin edit
        // pages for consistent layout + design-system look.
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(
            __DIR__.'/../resources/views/components',
            'vela'
        );

        \Illuminate\Support\Facades\Blade::directive('velaAssets', function ($expression) {
            return "<?php echo app(\\VelaBuild\\Core\\Services\\AssetBundler::class)->tags([{$expression}]); ?>";
        });

        // @velaMenu('primary')                                — render with default partial
        // @velaMenu('primary', 'vela::partials.my-menu')      — custom partial
        // @velaMenu('primary', null, ['linkClass' => '...'])  — pass extra view vars
        \Illuminate\Support\Facades\Blade::directive('velaMenu', function ($expression) {
            return "<?php echo app(\\VelaBuild\\Core\\Services\\MenuRenderer::class)->render({$expression}); ?>";
        });

        // Publishable assets
        $this->publishes([
            __DIR__.'/../config/vela.php' => config_path('vela.php'),
        ], 'vela-config');

        // NOTE: there is intentionally no `vela-views` publish tag.
        // Admin views must NEVER be forked into the host app — Laravel's
        // view loader silently prefers `resources/views/vendor/vela/...`
        // over the package, so any forked admin view becomes a frozen
        // shadow that hides future package updates (broken nav entries,
        // missed security patches, etc.).
        // Custom public templates live in `resources/views/templates/`
        // (auto-discovered by core), not in vendor/vela. Plugins extend
        // the admin via the registries on \VelaBuild\Core\Vela.
        // `php artisan vela:checks` flags any leftover vendor/vela/admin
        // forks so they can be removed.

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/vela'),
        ], 'vela-lang');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/vela'),
        ], 'vela-assets');

        // Error pages: publish to the host app's `resources/views/errors/`
        // because Laravel's exception handler looks there (not in package
        // namespaces) when rendering {code}.blade.php. `vela:install` runs
        // this automatically; host apps can also re-publish with
        // `vendor:publish --tag=vela-errors --force` to refresh.
        $this->publishes([
            __DIR__.'/../resources/views/errors' => resource_path('views/errors'),
        ], 'vela-errors');

        // Register defaults
        $this->registerDefaultBlocks();
        $this->registerDefaultMenuItems();
        $this->registerDefaultSettingsItems();
        $this->registerDefaultTemplates();
        $this->registerDefaultWidgets();
        $this->registerDefaultTools();

        // Observer
        \VelaBuild\Core\Models\Category::observe(\VelaBuild\Core\Observers\CategoryObserver::class);
        \VelaBuild\Core\Models\Page::observe(\VelaBuild\Core\Observers\PageObserver::class);
        \VelaBuild\Core\Models\Content::observe(\VelaBuild\Core\Observers\ContentObserver::class);

        // Cloudflare Cache-Tag invalidation. Hooked via Eloquent model
        // events (not Model::observe) so one shared observer class handles
        // multiple models without needing per-model wrapper classes. Jobs
        // dispatch after the HTTP response so admin saves feel instant.
        $cachePurge = new \VelaBuild\Core\Observers\CachePurgeObserver();
        \VelaBuild\Core\Models\Page::saved(      fn ($m) => $cachePurge->savedPage($m));
        \VelaBuild\Core\Models\Page::deleted(    fn ($m) => $cachePurge->deletedPage($m));
        \VelaBuild\Core\Models\Content::saved(   fn ($m) => $cachePurge->savedContent($m));
        \VelaBuild\Core\Models\Content::deleted( fn ($m) => $cachePurge->deletedContent($m));
        \VelaBuild\Core\Models\PageRow::saved(   fn ($m) => $cachePurge->savedPageRow($m));
        \VelaBuild\Core\Models\PageRow::deleted( fn ($m) => $cachePurge->deletedPageRow($m));
        \VelaBuild\Core\Models\PageBlock::saved( fn ($m) => $cachePurge->savedPageBlock($m));
        \VelaBuild\Core\Models\PageBlock::deleted(fn ($m) => $cachePurge->deletedPageBlock($m));
    }

    protected function registerHomeRedirect(): void
    {
        Route::middleware('web')->get('/home', function () {
            if (session('status')) {
                return redirect()->route('vela.admin.home')->with('status', session('status'));
            }
            return redirect()->route('vela.admin.home');
        });
    }

    protected function registerMcpRoutes(): void
    {
        Route::group([
            'prefix' => 'api/mcp',
            'as' => 'vela.api.mcp.',
            'middleware' => ['vela.mcp'],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }

    protected function registerContentApiRoutes(): void
    {
        Route::group([
            'prefix' => 'api/content',
            'as' => 'vela.api.content.',
        ], function () {
            $c = \VelaBuild\Core\Http\Controllers\Api\ContentApiController::class;
            Route::get('/', [$c, 'posts'])->name('posts');
            Route::get('/pages', [$c, 'pages'])->name('pages');
            Route::get('/pages/{slug}', [$c, 'page'])->name('page');
            Route::get('/posts', [$c, 'posts'])->name('posts.list');
            Route::get('/posts/{slug}', [$c, 'post'])->name('post');
            Route::get('/categories', [$c, 'categories'])->name('categories');
            Route::get('/search', [$c, 'search'])->name('search');
            Route::post('/render', [$c, 'render'])->name('render');
        });
    }

    protected function registerAgentDiscoveryRoutes(): void
    {
        Route::middleware('web')->group(function () {
            $c = \VelaBuild\Core\Http\Controllers\Public\AgentDiscoveryController::class;
            Route::get('/.well-known/api-catalog', [$c, 'apiCatalog'])->name('vela.well-known.api-catalog');
            Route::get('/.well-known/mcp/server-card.json', [$c, 'mcpServerCard'])->name('vela.well-known.mcp-server-card');
            Route::get('/.well-known/agent-skills/index.json', [$c, 'agentSkillsIndex'])->name('vela.well-known.agent-skills');
        });
    }

    protected function registerWebhookRoutes(): void
    {
        Route::middleware('web')
            ->post('/webhook/repostra', [\VelaBuild\Core\Http\Controllers\Public\RepostraWebhookController::class, 'handle'])
            ->name('vela.webhook.repostra')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

        Route::middleware('web')
            ->post('/webhook/marketplace', [\VelaBuild\Core\Http\Controllers\Public\MarketplaceWebhookController::class, 'handle'])
            ->name('vela.webhook.marketplace')
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
    }

    protected function registerAdminRoutes(): void
    {
        Route::group([
            'prefix' => config('vela.admin_prefix', 'admin'),
            'as' => 'vela.admin.',
            'middleware' => config('vela.middleware.admin', ['web', 'vela.auth', 'vela.2fa', 'vela.gates', 'vela.show-to-edit']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
        });
    }

    protected function registerAuthRoutes(): void
    {
        Route::group([
            'prefix' => config('vela.auth_prefix', 'vela'),
            'as' => 'vela.auth.',
            'middleware' => ['web'],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');
        });
    }

    protected function registerPublicRoutes(): void
    {
        if (config('vela.enable_public_routes', true)) {
            Route::group([
                'prefix' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::setLocale(),
                'as' => 'vela.public.',
                'middleware' => ['web', 'vela.template', 'vela.x402', 'vela.visibility'],
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/public.php');
            });
        }
    }

    protected function registerDefaultBlocks(): void
    {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);

        $vela->registerBlock('text', [
            'label' => 'vela::global.block_type_text',
            'icon' => 'fas fa-font',
            'view' => 'vela::public.pages.blocks.text',
            'editor' => null,
            'defaults' => ['content' => ['blocks' => []], 'settings' => []],
        ]);

        $vela->registerBlock('image', [
            'label' => 'vela::global.block_type_image',
            'icon' => 'fas fa-image',
            'view' => 'vela::public.pages.blocks.image',
            'editor' => null,
            'defaults' => ['content' => ['url' => '', 'alt' => '', 'caption' => ''], 'settings' => []],
        ]);

        $vela->registerBlock('video', [
            'label' => 'vela::global.block_type_video',
            'icon' => 'fas fa-video',
            'view' => 'vela::public.pages.blocks.video',
            'editor' => null,
            'defaults' => ['content' => ['url' => '', 'provider' => 'youtube'], 'settings' => []],
        ]);

        $vela->registerBlock('html', [
            'label' => 'vela::global.block_type_html',
            'icon' => 'fas fa-code',
            'view' => 'vela::public.pages.blocks.html',
            'editor' => null,
            'defaults' => ['content' => ['html' => ''], 'settings' => []],
        ]);

        $vela->registerBlock('accordion', [
            'label' => 'vela::global.block_type_accordion',
            'icon' => 'fas fa-list',
            'view' => 'vela::public.pages.blocks.accordion',
            'editor' => null,
            'defaults' => ['content' => ['items' => []], 'settings' => []],
            // Shown by list_block_types so the AI knows what one item holds —
            // an empty defaults array tells it nothing, so it guesses a shape
            // the view never reads and the block renders blank.
            'content_example' => ['items' => [['title' => 'Question?', 'body' => 'Answer text.']]],
        ]);

        $vela->registerBlock('contact_form', [
            'label' => 'vela::global.block_type_contact_form',
            'icon' => 'fas fa-envelope',
            'view' => 'vela::public.pages.blocks.contact_form',
            'editor' => null,
            'defaults' => ['content' => ['title' => '', 'email' => ''], 'settings' => []],
        ]);

        $vela->registerBlock('carousel', [
            'label' => 'vela::global.carousel',
            'icon' => 'fas fa-images',
            'view' => 'vela::public.pages.blocks.carousel',
            'editor' => null,
            'defaults' => ['content' => ['slides' => []], 'settings' => []],
            'content_example' => ['slides' => [['image_url' => 'https://example.com/slide.jpg', 'caption' => 'Caption', 'link' => '/target-page']]],
        ]);

        $vela->registerBlock('gallery', [
            'label' => 'vela::global.gallery',
            'icon' => 'fas fa-th',
            'view' => 'vela::public.pages.blocks.gallery',
            'editor' => null,
            'defaults' => ['content' => ['images' => []], 'settings' => []],
            'content_example' => ['images' => [['url' => 'https://example.com/photo.jpg', 'alt' => 'Alt text', 'caption' => 'Caption']]],
        ]);

        $vela->registerBlock('testimonials', [
            'label' => 'vela::global.testimonials',
            'icon' => 'fas fa-quote-left',
            'view' => 'vela::public.pages.blocks.testimonials',
            'editor' => null,
            'defaults' => ['content' => ['items' => []], 'settings' => []],
            'content_example' => ['items' => [['quote' => 'They fixed it same day.', 'name' => 'Jane Doe', 'title' => 'Homeowner', 'photo_url' => 'https://example.com/jane.jpg']]],
        ]);

        $vela->registerBlock('icon_box', [
            'label' => 'vela::global.icon_box',
            'icon' => 'fas fa-star',
            'view' => 'vela::public.pages.blocks.icon_box',
            'editor' => null,
            'defaults' => ['content' => ['items' => []], 'settings' => []],
            // One icon_box block holds ALL the boxes — do not add one block per
            // box in separate columns.
            'content_example' => ['items' => [['icon' => 'fas fa-wrench', 'title' => 'Emergency Repairs', 'description' => 'Same-day callouts.']]],
        ]);

        $vela->registerBlock('categories_grid', [
            'label' => 'vela::global.categories_grid',
            'icon' => 'fas fa-th-large',
            'view' => 'vela::public.pages.blocks.categories_grid',
            'editor' => null,
            'defaults' => ['content' => [], 'settings' => ['columns' => 3, 'max_count' => 12, 'show_post_count' => true]],
        ]);

        $vela->registerBlock('posts_grid', [
            'label' => 'vela::global.posts_grid',
            'icon' => 'fas fa-newspaper',
            'view' => 'vela::public.pages.blocks.posts_grid',
            'editor' => null,
            'defaults' => ['content' => [], 'settings' => ['columns' => 3, 'max_count' => 12, 'order_by' => 'newest']],
        ]);

        $vela->registerBlock('hero', [
            'label' => 'vela::global.block_type_hero',
            'icon' => 'fas fa-flag',
            'view' => 'vela::public.pages.blocks.hero',
            'editor' => null,
            'defaults' => [
                'content' => ['title' => '', 'subtitle' => '', 'primary_button_text' => '', 'primary_button_url' => '', 'secondary_button_text' => '', 'secondary_button_url' => ''],
                'settings' => ['background_overlay' => 'rgba(0,0,0,0.4)', 'text_alignment' => 'center', 'min_height' => '80vh'],
            ],
        ]);

        $vela->registerBlock('cta', [
            'label' => 'vela::global.block_type_cta',
            'icon' => 'fas fa-bullhorn',
            'view' => 'vela::public.pages.blocks.cta',
            'editor' => null,
            'defaults' => [
                // `note` is read by the cta view (small print under the buttons)
                // and must be declared here — the defaults are what add_block /
                // update_block validate incoming content against.
                'content' => ['heading' => '', 'description' => '', 'note' => '', 'primary_button_text' => '', 'primary_button_url' => '', 'secondary_button_text' => '', 'secondary_button_url' => ''],
                'settings' => ['text_alignment' => 'center'],
            ],
        ]);

        $vela->registerBlock('app_download', [
            'label' => 'vela::global.block_type_app_download',
            'icon' => 'fas fa-download',
            'view' => 'vela::public.pages.blocks.app_download',
            'editor' => null,
            'defaults' => [
                'content' => ['heading' => '', 'description' => ''],
                'settings' => ['text_alignment' => 'center'],
            ],
        ]);

        $vela->registerBlock('code', [
            'label' => 'vela::global.block_type_code',
            'icon' => 'fas fa-code',
            'view' => 'vela::public.pages.blocks.code',
            'editor' => null,
            'defaults' => [
                'content' => ['code' => '', 'filename' => '', 'caption' => ''],
                'settings' => ['language' => 'bash', 'theme' => 'dark', 'show_copy' => true],
            ],
        ]);

        $vela->registerBlock('pricing_tiers', [
            'label' => 'vela::global.block_type_pricing_tiers',
            'icon' => 'fas fa-tags',
            'view' => 'vela::public.pages.blocks.pricing_tiers',
            'editor' => null,
            'defaults' => [
                'content' => ['tiers' => []],
                'settings' => ['columns' => 3],
            ],
            'content_example' => ['tiers' => [[
                'name' => 'Standard', 'price' => '1500', 'price_currency' => 'THB', 'period' => 'per visit',
                'description' => 'Callout and basic repair.', 'features' => ['Same-day callout', '90-day warranty'],
                'cta_text' => 'Book now', 'cta_url' => '/contact-us', 'featured' => false,
            ]]],
        ]);
    }

    protected function registerDefaultMenuItems(): void
    {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);

        $vela->registerMenuItem('home', [
            'label' => 'vela::global.dashboard',
            'icon' => 'fas fa-home',
            'route' => 'vela.admin.home',
            'gate' => null,
            'group' => 'general',
            'order' => 1,
        ]);

        $vela->registerMenuItem('pages', [
            'label' => 'vela::global.pages',
            'icon' => 'fas fa-file',
            'route' => 'vela.admin.pages.index',
            'gate' => 'page_access',
            'group' => 'content',
            'order' => 10,
        ]);

        $vela->registerMenuItem('contents', [
            'label' => 'vela::global.articles',
            'icon' => 'fas fa-newspaper',
            'route' => 'vela.admin.contents.index',
            'gate' => 'article_access',
            'group' => 'content',
            'order' => 20,
        ]);

        $vela->registerMenuItem('media', [
            'label' => 'vela::global.media_library',
            'icon' => 'fas fa-images',
            'route' => 'vela.admin.media.index',
            'gate' => 'article_access',
            'group' => 'content',
            'order' => 25,
        ]);

        $vela->registerMenuItem('categories', [
            'label' => 'vela::global.categories',
            'icon' => 'fas fa-tags',
            'route' => 'vela.admin.categories.index',
            'gate' => 'category_access',
            'group' => 'content',
            'order' => 30,
        ]);

        $vela->registerMenuItem('form-submissions', [
            'label' => 'vela::global.form_submissions',
            'icon' => 'fas fa-inbox',
            'route' => 'vela.admin.form-submissions.index',
            'gate' => 'form_submission_access',
            'group' => 'content',
            'order' => 60,
        ]);

        $vela->registerMenuItem('comments', [
            'label' => 'vela::global.comments',
            'icon' => 'fas fa-comments',
            'route' => 'vela.admin.comments.index',
            'gate' => 'comment_access',
            'group' => 'content',
            'order' => 70,
        ]);

        $vela->registerMenuItem('users', [
            'label' => 'vela::global.users',
            'icon' => 'fas fa-users',
            'route' => 'vela.admin.users.index',
            'gate' => 'user_access',
            'group' => 'admin',
            'order' => 10,
        ]);

        $vela->registerMenuItem('configs', [
            'label' => 'vela::global.settings',
            'icon' => 'fas fa-cog',
            'route' => 'vela.admin.settings.index',
            'gate' => 'config_access',
            'group' => 'admin',
            'order' => 40,
        ]);

        $vela->registerMenuItem('marketplace', [
            'label' => 'Marketplace',
            'icon' => 'fas fa-store',
            'route' => 'vela.admin.marketplace.index',
            'gate' => 'marketplace_browse',
            'group' => 'admin',
            'order' => 35,
            'children' => [
                [
                    'label' => 'Browse',
                    'route' => 'vela.admin.marketplace.index',
                    'gate' => 'marketplace_browse',
                ],
                [
                    'label' => 'Installed Packages',
                    'route' => 'vela.admin.packages.index',
                    'gate' => 'marketplace_browse',
                ],
            ],
        ]);

        // Profile dropdown menu items
        $vela->registerProfileMenuItem('profile', [
            'label' => 'vela::global.my_profile',
            'icon' => 'fas fa-user',
            'route' => 'vela.auth.profile.password.edit',
            'gate' => 'profile_password_edit',
            'order' => 10,
        ]);

        $vela->registerProfileMenuItem('logout', [
            'label' => 'vela::global.logout',
            'icon' => 'fas fa-sign-out-alt',
            'route' => '#logout',
            'order' => 900,
            'divider_before' => true,
        ]);
    }

    /**
     * Default entries for the Settings dropdown / page-head / index card grid.
     * Plugins extend by calling Vela::registerSettingsItem() — the admin
     * Blade files must NEVER be forked.
     */
    protected function registerDefaultSettingsItems(): void
    {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);

        $vela->registerSettingsItem('general', [
            'label'       => __('vela::pwa.settings_general'),
            'description' => __('vela::pwa.settings_general_desc'),
            'icon'        => 'fas fa-globe',
            'route'       => ['vela.admin.settings.group', 'general'],
            'order'       => 10,
        ]);
        $vela->registerSettingsItem('appearance', [
            'label'       => __('vela::pwa.settings_appearance'),
            'description' => __('vela::pwa.settings_appearance_desc'),
            'icon'        => 'fas fa-palette',
            'route'       => ['vela.admin.settings.group', 'appearance'],
            'order'       => 20,
        ]);
        $vela->registerSettingsItem('customcss', [
            'label'       => trans('vela::global.custom_css_js'),
            'description' => trans('vela::global.custom_css_js_desc'),
            'icon'        => 'fas fa-code',
            'route'       => ['vela.admin.settings.group', 'customcss'],
            'order'       => 30,
        ]);
        $vela->registerSettingsItem('pwa', [
            'label'       => __('vela::pwa.settings_pwa'),
            'description' => __('vela::pwa.settings_pwa_desc'),
            'icon'        => 'fas fa-mobile-alt',
            'route'       => ['vela.admin.settings.group', 'pwa'],
            'order'       => 40,
        ]);
        $vela->registerSettingsItem('app', [
            'label'       => __('vela::pwa.settings_app'),
            'description' => __('vela::pwa.settings_app_desc'),
            'icon'        => 'fas fa-tablet-alt',
            'route'       => ['vela.admin.settings.group', 'app'],
            'order'       => 50,
        ]);
        $vela->registerSettingsItem('visibility', [
            'label'       => __('vela::visibility.settings_title'),
            'description' => __('vela::visibility.settings_desc'),
            'icon'        => 'fas fa-eye',
            'route'       => ['vela.admin.settings.group', 'visibility'],
            'order'       => 60,
        ]);
        $vela->registerSettingsItem('gdpr', [
            'label'       => __('vela::gdpr.settings_title'),
            'description' => __('vela::gdpr.settings_desc'),
            'icon'        => 'fas fa-shield-alt',
            'route'       => ['vela.admin.settings.group', 'gdpr'],
            'order'       => 70,
        ]);
        $vela->registerSettingsItem('languages', [
            'label'       => __('vela::global.languages'),
            'description' => __('vela::global.languages_desc'),
            'icon'        => 'fas fa-language',
            'route'       => ['vela.admin.settings.group', 'languages'],
            'order'       => 80,
            'hidden'      => true, // shown in dropdown but not on the card grid
        ]);
        $vela->registerSettingsItem('mcp', [
            'label'       => __('vela::mcp.settings_title'),
            'description' => __('vela::mcp.settings_desc'),
            'icon'        => 'fas fa-plug',
            'route'       => ['vela.admin.settings.group', 'mcp'],
            'order'       => 90,
        ]);
        $vela->registerSettingsItem('tracking', [
            'label'       => __('Tracking'),
            'description' => __('GA4, GTM, Meta Pixel + Conversions API, Google Ads.'),
            'icon'        => 'fas fa-bullseye',
            'route'       => 'vela.admin.settings.tracking.index',
            'order'       => 100,
        ]);
        $vela->registerSettingsItem('design-system', [
            'label'       => __('Design System'),
            'description' => __('Files, colour palette, fonts — used by the block editor and the AI.'),
            'icon'        => 'fas fa-swatchbook',
            'route'       => 'vela.admin.settings.design-system.index',
            'order'       => 110,
        ]);
        $vela->registerSettingsItem('menus', [
            'label'       => __('Menus'),
            'description' => __('Configure header, footer, and other navigation slots declared by your theme.'),
            'icon'        => 'fas fa-bars',
            'route'       => 'vela.admin.settings.menus.index',
            'order'       => 120,
        ]);

        $vela->registerSettingsItem('translations', [
            'label'       => __('Translations'),
            'description' => __('Per-locale completeness across pages, articles, categories, and lang files. AI-translate any missing item with one click.'),
            'icon'        => 'fas fa-language',
            'route'       => 'vela.admin.translations.manager',
            'gate'        => 'translation_access',
            'order'       => 125,
        ]);

        // AI Settings — was added separately by the host app. Now lives in
        // core registry so it shows everywhere consistently.
        $vela->registerSettingsItem('ai-settings', [
            'label'       => __('AI Settings'),
            'description' => __('Provider keys, default models, and chatbot behaviour.'),
            'icon'        => 'fas fa-robot',
            'route'       => 'vela.admin.ai-settings.index',
            'gate'        => 'config_edit',
            'order'       => 130,
        ]);

        // Plugin entries — only show when the plugin is loaded. We register
        // them here too so the registry is the single source of truth; the
        // SettingsNavRegistry filters out entries whose routes don't exist.
        $vela->registerSettingsItem('store', [
            'label'       => __('Store'),
            'description' => __('Currency, tax, inventory, and Stripe payments.'),
            'icon'        => 'fas fa-store',
            'route'       => 'vela.admin.store.settings.index',
            'order'       => 200,
        ]);
        $vela->registerSettingsItem('marketplace', [
            'label'       => __('Marketplace'),
            'description' => __('Marketplace publisher / buyer settings.'),
            'icon'        => 'fas fa-cube',
            'route'       => 'vela.admin.marketplace.settings.index',
            'order'       => 210,
        ]);
    }

    protected function registerDefaultTemplates(): void
    {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);

        // Standard theme-option conventions (use these names across templates):
        //   hero_image (image)       - Hero/banner background image
        //   logo_image (image)       - Site logo override
        //   show_hero  (toggle)      - Show/hide hero section
        //   show_cta   (toggle)      - Show/hide CTA section
        //   primary_color (color)    - Primary brand color (links, buttons, accents)
        //   secondary_color (color)  - Secondary/muted color
        //   background_color (color) - Page background color
        //   footer_copyright (text)  - Copyright text override
        //
        // Templates self-register by living under resources/views/templates/<name>/
        // with an optional template.json manifest (label, namespace, description,
        // category, options). Core's bundled templates come first; the app's own
        // resources/views/templates/ directory is scanned after so site-local
        // templates appear alongside without any manual provider code.

        $vela->templates()->autoDiscover(__DIR__ . '/../resources/views/templates');
        $vela->templates()->autoDiscover(resource_path('views/templates'));

        // Default slot every theme is expected to provide. Templates can
        // override or add more via `menus` in their template.json.
        $vela->registerFrontMenuSlot('primary', [
            'label'          => __('vela::menus.slot_primary'),
            'description'    => __('vela::menus.slot_primary_description'),
            'auto_add_pages' => true,
        ]);
        $vela->registerFrontMenuSlot('footer_quick_links', [
            'label'       => __('vela::menus.slot_footer_quick_links'),
            'description' => __('vela::menus.slot_footer_quick_links_description'),
        ]);

        // Pull theme-declared slots from each template.json's `menus` key.
        foreach ($vela->templates()->all() as $tplName => $tpl) {
            foreach (($tpl['menus'] ?? []) as $slotKey => $slotConfig) {
                $vela->registerFrontMenuSlot($slotKey, array_merge(
                    is_array($slotConfig) ? $slotConfig : ['label' => (string) $slotConfig],
                    ['template' => $tplName]
                ));
            }
        }
    }

    protected function registerDefaultWidgets(): void
    {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);

        $vela->registerWidget('stats', [
            'label' => 'vela::global.site_overview',
            'icon' => 'fas fa-chart-bar',
            'view' => 'vela::admin.widgets.stats',
            'width' => 'col-12',
            'order' => 10,
            'data' => function () {
                return [
                    ['label' => __('vela::global.articles'), 'count' => \VelaBuild\Core\Models\Content::count(), 'icon' => 'fas fa-newspaper', 'color' => 'primary', 'route' => 'vela.admin.contents.index'],
                    ['label' => __('vela::global.pages'), 'count' => \VelaBuild\Core\Models\Page::count(), 'icon' => 'fas fa-file', 'color' => 'success', 'route' => 'vela.admin.pages.index'],
                    ['label' => __('vela::global.categories'), 'count' => \VelaBuild\Core\Models\Category::count(), 'icon' => 'fas fa-tags', 'color' => 'warning', 'route' => 'vela.admin.categories.index'],
                    ['label' => __('vela::global.users'), 'count' => \VelaBuild\Core\Models\VelaUser::count(), 'icon' => 'fas fa-users', 'color' => 'info', 'route' => 'vela.admin.users.index'],
                    ['label' => __('vela::global.comments'), 'count' => \VelaBuild\Core\Models\Comment::count(), 'icon' => 'fas fa-comments', 'color' => 'secondary', 'route' => 'vela.admin.comments.index'],
                    ['label' => __('vela::global.ideas'), 'count' => \VelaBuild\Core\Models\Idea::count(), 'icon' => 'fas fa-lightbulb', 'color' => 'dark', 'route' => 'vela.admin.ideas.index'],
                ];
            },
        ]);

        $vela->registerWidget('recent_content', [
            'label' => 'vela::global.recent_content',
            'icon' => 'fas fa-newspaper',
            'view' => 'vela::admin.widgets.recent_content',
            'width' => 'col-md-6',
            'order' => 20,
            'data' => function () {
                return \VelaBuild\Core\Models\Content::with('author')
                    ->latest()
                    ->take(5)
                    ->get();
            },
        ]);

        $vela->registerWidget('recent_comments', [
            'label' => 'vela::global.recent_comments',
            'icon' => 'fas fa-comments',
            'view' => 'vela::admin.widgets.recent_comments',
            'width' => 'col-md-6',
            'order' => 30,
            'data' => function () {
                return \VelaBuild\Core\Models\Comment::with('user')
                    ->latest()
                    ->take(5)
                    ->get();
            },
        ]);

        $vela->registerWidget('recent_users', [
            'label' => 'vela::global.recent_users',
            'icon' => 'fas fa-user-plus',
            'view' => 'vela::admin.widgets.recent_users',
            'gate' => 'user_access',
            'width' => 'col-md-6',
            'order' => 40,
            'data' => function () {
                return \VelaBuild\Core\Models\VelaUser::with('roles')
                    ->latest()
                    ->take(5)
                    ->get();
            },
        ]);

        $vela->registerWidget('recent_ideas', [
            'label' => 'vela::global.recent_ideas',
            'icon' => 'fas fa-lightbulb',
            'view' => 'vela::admin.widgets.recent_ideas',
            'gate' => 'idea_access',
            'width' => 'col-md-6',
            'order' => 50,
            'data' => function () {
                return \VelaBuild\Core\Models\Idea::latest()
                    ->take(5)
                    ->get();
            },
        ]);
    }

    protected function registerDefaultTools(): void
    {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);
        $settings = $this->app->make(\VelaBuild\Core\Services\ToolSettingsService::class);

        $vela->registerTool('google-analytics', [
            'label' => 'vela::tools.analytics.title',
            'description' => 'vela::tools.analytics.description',
            'icon' => 'fab fa-google',
            'route' => 'vela.admin.tools.google-analytics',
            'gate' => 'tools_access',
            'config_gate' => 'admin_tools_access',
            'category' => 'analytics',
            'status' => function () use ($settings) {
                if (!$settings->hasKey('ga_measurement_id')) return 'not_configured';
                return 'connected';
            },
        ]);

        $vela->registerTool('search-console', [
            'label' => 'vela::tools.search_console.title',
            'description' => 'vela::tools.search_console.description',
            'icon' => 'fab fa-google',
            'route' => 'vela.admin.tools.search-console',
            'gate' => 'tools_access',
            'config_gate' => 'admin_tools_access',
            'category' => 'analytics',
            'status' => function () use ($settings) {
                if (!$settings->hasKey('gsc_site_url')) return 'not_configured';
                return 'connected';
            },
        ]);

        $vela->registerTool('pagespeed', [
            'label' => 'vela::tools.pagespeed.title',
            'description' => 'vela::tools.pagespeed.description',
            'icon' => 'fas fa-tachometer-alt',
            'route' => 'vela.admin.tools.pagespeed',
            'gate' => 'tools_access',
            'category' => 'seo',
            'status' => fn () => 'connected',
        ]);

        $vela->registerTool('email-tester', [
            'label' => 'vela::tools.email.title',
            'description' => 'vela::tools.email.description',
            'icon' => 'fas fa-envelope',
            'route' => 'vela.admin.tools.email-tester',
            'gate' => 'tools_access',
            'category' => 'infrastructure',
            'status' => function () {
                $driver = config('mail.default');
                if (!$driver || $driver === 'log') return 'not_configured';
                return 'connected';
            },
        ]);

        $vela->registerTool('w3c-validator', [
            'label' => 'vela::tools.w3c.title',
            'description' => 'vela::tools.w3c.description',
            'icon' => 'fas fa-check-circle',
            'route' => 'vela.admin.tools.w3c-validator',
            'gate' => 'tools_access',
            'category' => 'seo',
            'status' => fn () => 'connected',
        ]);

        $vela->registerTool('cloudflare', [
            'label' => 'vela::tools.cloudflare.title',
            'description' => 'vela::tools.cloudflare.description',
            'icon' => 'fas fa-cloud',
            'route' => 'vela.admin.tools.cloudflare',
            'gate' => 'admin_tools_access',
            'category' => 'infrastructure',
            'status' => function () use ($settings) {
                if ($settings->get('cf_last_error')) return 'error';
                if (!$settings->hasKey('cf_api_token') || !$settings->hasKey('cf_zone_id')) return 'not_configured';
                return 'connected';
            },
        ]);

        $vela->registerTool('repostra', [
            'label' => 'vela::tools.repostra.title',
            'description' => 'vela::tools.repostra.description',
            'icon' => 'fas fa-rss',
            'route' => 'vela.admin.tools.repostra',
            'gate' => 'tools_access',
            'config_gate' => 'admin_tools_access',
            'category' => 'content',
            'status' => function () use ($settings) {
                if (!$settings->hasKey('repostra_webhook_secret')) return 'not_configured';
                return 'connected';
            },
        ]);

        $vela->registerTool('reviews', [
            'label' => 'vela::tools.reviews.title',
            'description' => 'vela::tools.reviews.description',
            'icon' => 'fas fa-star',
            'route' => 'vela.admin.tools.reviews',
            'gate' => 'tools_access',
            'category' => 'content',
            'status' => function () {
                $count = \VelaBuild\Core\Models\Review::count();
                return $count > 0 ? 'connected' : 'not_configured';
            },
        ]);

        // Register Tools menu item (just above Settings in admin group)
        $vela->registerMenuItem('tools', [
            'label' => 'vela::global.tools',
            'icon' => 'fas fa-toolbox',
            'route' => 'vela.admin.tools.index',
            'gate' => 'tools_access',
            'group' => 'admin',
            'order' => 30,
        ]);

        // Register review blocks
        $vela->registerBlock('review-summary', [
            'label' => 'vela::global.review_summary',
            'icon' => 'fas fa-star-half-alt',
            'view' => 'vela::public.pages.blocks.review-summary',
            'editor' => null,
            'defaults' => ['content' => [], 'settings' => ['min_rating' => 1]],
        ]);

        $vela->registerBlock('review-carousel', [
            'label' => 'vela::global.review_carousel',
            'icon' => 'fas fa-star',
            'view' => 'vela::public.pages.blocks.review-carousel',
            'editor' => null,
            'defaults' => ['content' => [], 'settings' => ['max_count' => 10, 'min_rating' => 1]],
        ]);

        $vela->registerBlock('review-grid', [
            'label' => 'vela::global.review_grid',
            'icon' => 'fas fa-th',
            'view' => 'vela::public.pages.blocks.review-grid',
            'editor' => null,
            'defaults' => ['content' => [], 'settings' => ['max_count' => 12, 'columns' => 3, 'min_rating' => 1]],
        ]);
    }
}
