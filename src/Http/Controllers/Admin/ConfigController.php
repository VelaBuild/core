<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Helpers\MetaTagsHelper;
use VelaBuild\Core\Services\PwaIconGenerator;

class ConfigController extends Controller
{
    private const GROUP_KEYS = [
        'general' => ['site_name', 'site_niche', 'site_tagline', 'site_description'],
        'pwa' => ['pwa_enabled', 'pwa_name', 'pwa_short_name', 'pwa_description', 'pwa_display', 'pwa_theme_color', 'pwa_background_color', 'pwa_icon_source', 'pwa_precache_urls', 'pwa_offline_enabled', 'sw_version'],
        'app' => ['app_ios_url', 'app_android_url', 'app_name', 'app_custom_scheme'],
        'gdpr' => ['gdpr_enabled', 'gdpr_privacy_url'],
        'visibility' => ['visibility_mode', 'visibility_noindex', 'visibility_block_ai', 'visibility_holding_page', 'visibility_holding_page_id',
            'x402_enabled', 'x402_mode', 'x402_pay_to', 'x402_price_usd', 'x402_network', 'x402_description',
            'content_signal_ai_train', 'content_signal_search', 'content_signal_ai_input'],
    ];

    public function index(Request $request)
    {
        abort_if(Gate::denies('config_access'), 403);

        return view('vela::admin.settings.index');
    }

    public function group(Request $request, string $group)
    {
        abort_if(Gate::denies('config_access'), 403);

        if (! in_array($group, ['general', 'appearance', 'pwa', 'customcss', 'app', 'gdpr', 'visibility', 'mcp', 'languages'])) {
            abort(404);
        }

        if ($group === 'languages') {
            $allLanguages = config('vela.available_languages', []);
            $activeJson = VelaConfig::where('key', 'active_languages')->value('value');
            $activeLanguages = $activeJson ? json_decode($activeJson, true) : array_keys($allLanguages);
            $primaryLanguage = VelaConfig::where('key', 'primary_language')->value('value')
                ?? config('vela.primary_language', 'en');

            return view('vela::admin.settings.languages', compact('allLanguages', 'activeLanguages', 'primaryLanguage'));
        }

        if ($group === 'mcp') {
            $mcp = app(\VelaBuild\Core\Services\McpSettingsService::class);
            $status = $mcp->getStatus();
            return view('vela::admin.settings.mcp', compact('status'));
        }

        if ($group === 'visibility') {
            $keys = self::GROUP_KEYS['visibility'];
            $settings = VelaConfig::whereIn('key', $keys)->pluck('value', 'key')->toArray();

            $pages = Page::orderBy('title')
                ->select('id', 'title', 'slug', 'status')
                ->get();

            return view('vela::admin.settings.visibility', compact('settings', 'pages'));
        }

        if ($group === 'gdpr') {
            $keys = self::GROUP_KEYS['gdpr'];
            $settings = VelaConfig::whereIn('key', $keys)->pluck('value', 'key')->toArray();

            // Resolve effective values: DB value if set, else env/config default
            $gdprEnabled = $settings['gdpr_enabled'] ?? null;
            $gdprPrivacyUrl = $settings['gdpr_privacy_url'] ?? null;
            $envGdpr = env('VELA_GDPR');
            $envPrivacyUrl = env('VELA_PRIVACY_URL');

            $effectiveEnabled = $gdprEnabled !== null ? ($gdprEnabled === '1') : config('vela.gdpr.enabled', false);
            $effectivePrivacyUrl = $gdprPrivacyUrl ?? config('vela.gdpr.privacy_url', '/privacy');

            // Check if the privacy page exists
            $privacySlug = ltrim($effectivePrivacyUrl, '/');
            $privacyPageExists = Page::where('slug', $privacySlug)->where('status', 'published')->exists();

            return view('vela::admin.settings.gdpr', compact(
                'settings', 'effectiveEnabled', 'effectivePrivacyUrl',
                'envGdpr', 'envPrivacyUrl', 'privacyPageExists', 'privacySlug'
            ));
        }

        if ($group === 'appearance') {
            $cssConfigs = VelaConfig::where('key', 'like', 'css_%')->pluck('value', 'key')->toArray();
            $activeTemplate = VelaConfig::where('key', 'active_template')->value('value') ?? config('vela.template.active', 'default');
            $settings = array_merge($cssConfigs, ['active_template' => $activeTemplate]);

            $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();

            // Theme options for active template
            $templateDef = $templates[$activeTemplate] ?? [];
            $themeOptions = $templateDef['options'] ?? [];
            $themeValues = VelaConfig::where('key', 'like', 'theme_%')->pluck('value', 'key')->toArray();

            return view("vela::admin.settings.{$group}", compact('settings', 'templates', 'themeOptions', 'themeValues'));
        } elseif ($group === 'customcss') {
            $globalCss = VelaConfig::where('key', 'custom_css_global')->value('value') ?? '';
            $pagesWithCss = Page::where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('custom_css')->where('custom_css', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('custom_js')->where('custom_js', '!=', '');
                });
            })->select('id', 'title', 'slug', 'custom_css', 'custom_js')->get();

            return view('vela::admin.settings.customcss', compact('globalCss', 'pagesWithCss'));
        } else {
            $keys = self::GROUP_KEYS[$group] ?? [];
            $settings = VelaConfig::whereIn('key', $keys)->pluck('value', 'key')->toArray();
        }

        return view("vela::admin.settings.{$group}", compact('settings'));
    }

    public function updateGroup(Request $request, string $group)
    {
        abort_if(Gate::denies('config_edit'), 403);

        if (! in_array($group, ['general', 'appearance', 'pwa', 'customcss', 'app', 'gdpr', 'visibility', 'mcp', 'languages'])) {
            abort(404);
        }

        if ($group === 'languages') {
            $allLanguages = array_keys(config('vela.available_languages', []));

            $request->validate([
                'active_languages' => 'required|array|min:1',
                'active_languages.*' => 'string|in:' . implode(',', $allLanguages),
                'primary_language' => 'required|string|in:' . implode(',', $allLanguages),
            ]);

            $active = $request->input('active_languages', []);
            $primary = $request->input('primary_language', 'en');

            if (! in_array($primary, $active)) {
                $active[] = $primary;
            }

            VelaConfig::updateOrCreate(['key' => 'active_languages'], ['value' => json_encode(array_values($active))]);
            VelaConfig::updateOrCreate(['key' => 'primary_language'], ['value' => $primary]);

            $this->writeSiteConfig();

            return redirect()->back()->with('success', __('vela::global.languages_saved'));
        }

        if ($group === 'mcp') {
            $mcpController = app(\VelaBuild\Core\Http\Controllers\Admin\McpSettingsController::class);
            return $mcpController->update($request);
        }

        if ($group === 'visibility') {
            $mode = $request->input('visibility_mode', 'public');
            VelaConfig::updateOrCreate(['key' => 'visibility_mode'], ['value' => $mode]);

            if ($mode === 'restricted') {
                $noindex = $request->boolean('visibility_noindex');
                $blockAi = $request->boolean('visibility_block_ai');
                $holdingPage = $request->boolean('visibility_holding_page');

                // If restricted with no sub-options, default noindex on
                if (!$noindex && !$blockAi && !$holdingPage) {
                    $noindex = true;
                }

                VelaConfig::updateOrCreate(['key' => 'visibility_noindex'], ['value' => $noindex ? '1' : '0']);
                VelaConfig::updateOrCreate(['key' => 'visibility_block_ai'], ['value' => $blockAi ? '1' : '0']);
                VelaConfig::updateOrCreate(['key' => 'visibility_holding_page'], ['value' => $holdingPage ? '1' : '0']);
                VelaConfig::updateOrCreate(['key' => 'visibility_holding_page_id'], [
                    'value' => $holdingPage ? ($request->input('visibility_holding_page_id', '') ?: '') : '',
                ]);
            } else {
                // Public mode — clear all restrictions
                VelaConfig::updateOrCreate(['key' => 'visibility_noindex'], ['value' => '0']);
                VelaConfig::updateOrCreate(['key' => 'visibility_block_ai'], ['value' => '0']);
                VelaConfig::updateOrCreate(['key' => 'visibility_holding_page'], ['value' => '0']);
                VelaConfig::updateOrCreate(['key' => 'visibility_holding_page_id'], ['value' => '']);
            }

            // x402 AI Payment — independent of public/restricted mode
            VelaConfig::updateOrCreate(['key' => 'x402_enabled'], ['value' => $request->boolean('x402_enabled') ? '1' : '0']);
            VelaConfig::updateOrCreate(['key' => 'x402_mode'], ['value' => $request->input('x402_mode', 'sitewide')]);
            if ($request->filled('x402_pay_to')) {
                $request->validate([
                    'x402_pay_to' => 'string|max:255',
                    'x402_price_usd' => 'numeric|min:0.001|max:1000',
                    'x402_network' => 'in:base,ethereum,polygon,arbitrum,optimism',
                    'x402_description' => 'nullable|string|max:500',
                    'x402_mode' => 'in:sitewide,per_page',
                ]);
            }
            VelaConfig::updateOrCreate(['key' => 'x402_pay_to'], ['value' => $request->input('x402_pay_to', '')]);
            VelaConfig::updateOrCreate(['key' => 'x402_price_usd'], ['value' => $request->input('x402_price_usd', '0.01')]);
            VelaConfig::updateOrCreate(['key' => 'x402_network'], ['value' => $request->input('x402_network', 'base')]);
            VelaConfig::updateOrCreate(['key' => 'x402_description'], ['value' => $request->input('x402_description', '')]);

            // Content Signals (robots.txt directives)
            $signals = ['ai_train', 'search', 'ai_input'];
            foreach ($signals as $key) {
                $dbKey = 'content_signal_' . $key;
                $default = $key === 'search' ? 'yes' : 'no';
                VelaConfig::updateOrCreate(['key' => $dbKey], ['value' => $request->input($dbKey, $default)]);
            }

            $this->writeSiteConfig();
            $this->writeRobotsTxt();

            // Clear static HTML cache — meta tags and holding page behaviour
            // are baked into cached files, so they must be regenerated
            $staticPath = config('vela.static.path', resource_path('static'));
            foreach (['home', 'posts', 'categories', 'pages'] as $dir) {
                $path = $staticPath . '/' . $dir;
                if (is_dir($path)) {
                    $this->deleteStaticDirectory($path);
                }
            }

            return redirect()->back()->with('success', __('vela::visibility.settings_saved'));
        }

        if ($group === 'gdpr') {
            $request->validate([
                'gdpr_enabled' => 'nullable|boolean',
                'gdpr_privacy_url' => 'nullable|string|max:255',
            ]);

            VelaConfig::updateOrCreate(['key' => 'gdpr_enabled'], ['value' => $request->boolean('gdpr_enabled') ? '1' : '0']);

            $privacyUrl = $request->input('gdpr_privacy_url', '/privacy');
            if ($privacyUrl && ! str_starts_with($privacyUrl, '/')) {
                $privacyUrl = '/' . $privacyUrl;
            }
            VelaConfig::updateOrCreate(['key' => 'gdpr_privacy_url'], ['value' => $privacyUrl ?: '/privacy']);

            $this->writeSiteConfig();

            return redirect()->back()->with('success', __('vela::gdpr.settings_saved'));
        }

        if ($group === 'general') {
            $request->validate([
                'site_name' => 'nullable|string|max:255',
                'site_niche' => 'nullable|string|max:255',
                'site_tagline' => 'nullable|string|max:500',
                'site_description' => 'nullable|string|max:1000',
            ]);
            foreach (['site_name', 'site_niche', 'site_tagline', 'site_description'] as $key) {
                VelaConfig::updateOrCreate(['key' => $key], ['value' => $request->input($key, '')]);
            }
            $this->writeSiteConfig();
        } elseif ($group === 'appearance') {
            $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();
            $oldTemplate = VelaConfig::where('key', 'active_template')->value('value') ?? config('vela.template.active', 'default');
            $newTemplate = $request->input('active_template');

            foreach ($request->except(['_token', '_theme_options']) as $key => $value) {
                if ($key === 'active_template') {
                    if (array_key_exists($value, $templates)) {
                        VelaConfig::updateOrCreate(['key' => $key], ['value' => $value]);
                    }
                } elseif (str_starts_with($key, 'css_')) {
                    VelaConfig::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
                } elseif (str_starts_with($key, 'theme_') && ! $request->hasFile($key)) {
                    VelaConfig::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
                }
            }

            // Only process toggles and image uploads from the theme options form
            if ($request->has('_theme_options')) {
                // Handle unchecked toggles (not sent in form)
                $activeSlug = $newTemplate ?? $oldTemplate;
                $templateDef = $templates[$activeSlug] ?? [];
                foreach (($templateDef['options'] ?? []) as $optKey => $optDef) {
                    if ($optDef['type'] === 'toggle') {
                        $formKey = 'theme_' . $optKey;
                        if (! $request->has($formKey)) {
                            VelaConfig::updateOrCreate(['key' => $formKey], ['value' => '0']);
                        }
                    }
                }

                // Handle theme image uploads
                foreach ($request->allFiles() as $key => $file) {
                    if (str_starts_with($key, 'theme_') && $file->isValid()) {
                        $oldPath = VelaConfig::where('key', $key)->value('value');
                        if ($oldPath && str_starts_with($oldPath, 'storage/theme/')) {
                            Storage::disk('public')->delete(str_replace('storage/', '', $oldPath));
                        }
                        $path = $file->store('theme', 'public');
                        VelaConfig::updateOrCreate(['key' => $key], ['value' => 'storage/' . $path]);
                    }
                }
            }

            $this->writeSiteConfig();

            // Clear static HTML cache when template changes
            if ($newTemplate !== $oldTemplate && array_key_exists($newTemplate, $templates)) {
                $staticPath = config('vela.static.path', resource_path('static'));
                foreach (['home', 'posts', 'categories', 'pages'] as $dir) {
                    $path = $staticPath . '/' . $dir;
                    if (is_dir($path)) {
                        $this->deleteStaticDirectory($path);
                    }
                }
            }
        } elseif ($group === 'customcss') {
            VelaConfig::updateOrCreate(
                ['key' => 'custom_css_global'],
                ['value' => $request->input('custom_css_global', '')]
            );
            $this->writeSiteConfig();
        } elseif ($group === 'pwa') {
            $request->validate([
                'pwa_enabled' => 'boolean',
                'pwa_name' => 'nullable|string|max:255',
                'pwa_short_name' => 'nullable|string|max:12',
                'pwa_description' => 'nullable|string|max:500',
                'pwa_display' => 'nullable|in:standalone,fullscreen,minimal-ui,browser',
                'pwa_theme_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'pwa_background_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'pwa_precache_urls' => 'nullable|string|max:2000',
                'pwa_offline_enabled' => 'boolean',
                'pwa_icon' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            ]);

            $pwaKeys = ['pwa_enabled', 'pwa_name', 'pwa_short_name', 'pwa_description', 'pwa_display', 'pwa_theme_color', 'pwa_background_color', 'pwa_precache_urls', 'pwa_offline_enabled'];
            foreach ($pwaKeys as $key) {
                if ($request->has($key)) {
                    VelaConfig::updateOrCreate(['key' => $key], ['value' => $request->input($key, '')]);
                }
            }

            if ($request->hasFile('pwa_icon')) {
                $file = $request->file('pwa_icon');
                $dimensions = getimagesize($file->getPathname());
                if ($dimensions && $dimensions[0] >= 512 && $dimensions[1] >= 512) {
                    $sourcePath = storage_path('app/public/pwa-icons/source.png');
                    $dir = dirname($sourcePath);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $file->move($dir, 'source.png');

                    $generator = new PwaIconGenerator;
                    $result = $generator->generate($sourcePath);

                    VelaConfig::updateOrCreate(['key' => 'pwa_icon_source'], ['value' => $sourcePath]);

                    if (! $result['success']) {
                        session()->flash('error', __('vela::pwa.icon_generation_failed'));
                    }
                } else {
                    session()->flash('error', __('vela::global.image_min_512'));
                }
            }

            $cacheDir = storage_path('app/pwa');
            if (is_dir($cacheDir)) {
                foreach (glob("{$cacheDir}/manifest-*.json") as $file) {
                    unlink($file);
                }
            }

            $currentVersion = (int) (VelaConfig::where('key', 'sw_version')->value('value') ?? 0);
            VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => (string) ($currentVersion + 1)]);
        } elseif ($group === 'app') {
            $request->validate([
                'app_ios_url' => 'nullable|url|max:500',
                'app_android_url' => 'nullable|url|max:500',
                'app_name' => 'nullable|string|max:255',
                'app_custom_scheme' => 'nullable|string|max:50',
            ]);

            foreach (self::GROUP_KEYS['app'] as $key) {
                VelaConfig::updateOrCreate(['key' => $key], ['value' => $request->input($key, '')]);
            }
        }

        return redirect()->back()->with('success', __('vela::pwa.settings_saved'));
    }

    public function uploadIcon(Request $request)
    {
        abort_if(Gate::denies('config_edit'), 403);

        $request->validate([
            'pwa_icon' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $file = $request->file('pwa_icon');
        $dimensions = getimagesize($file->getPathname());

        if (! $dimensions || $dimensions[0] < 512 || $dimensions[1] < 512) {
            return response()->json(['error' => 'Image must be at least 512x512 pixels'], 422);
        }

        $sourcePath = storage_path('app/public/pwa-icons/source.png');
        $dir = dirname($sourcePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file->move($dir, 'source.png');

        $generator = new PwaIconGenerator;
        $result = $generator->generate($sourcePath);

        VelaConfig::updateOrCreate(['key' => 'pwa_icon_source'], ['value' => $sourcePath]);

        if ($result['success']) {
            return response()->json(['success' => true, 'generated' => $result['generated']]);
        }

        return response()->json(['error' => __('vela::pwa.icon_generation_failed'), 'details' => $result['errors']], 500);
    }

    public function previewTemplate(string $template)
    {
        abort_if(Gate::denies('config_access'), 403);

        $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();
        if (! array_key_exists($template, $templates)) {
            abort(404);
        }

        config(['vela.template.active' => $template]);

        $homePage = Page::where('slug', 'home')
            ->where('status', 'published')
            ->with(['rows.blocks'])
            ->first();

        if ($homePage) {
            $page = $homePage;
            return view(vela_template_view('page'), compact('page'));
        }

        $latestPosts = Content::where('status', 'published')
            ->where('type', 'post')
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->limit(6)
            ->get();

        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $featuredPosts = Content::where('status', 'published')
            ->where('type', 'post')
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->limit(3)
            ->get();

        $metaTags = MetaTagsHelper::forHome();

        return view(vela_template_view('home'), compact('latestPosts', 'categories', 'featuredPosts', 'metaTags'));
    }

    public function installHomepage(Request $request)
    {
        abort_if(Gate::denies('config_edit'), 403);

        $request->validate([
            'template' => 'required|string',
            'mode'     => 'required|in:replace,new_page',
        ]);

        $template = $request->input('template');
        $mode     = $request->input('mode');

        $templates   = app(\VelaBuild\Core\Vela::class)->templates()->all();
        $templateDef = $templates[$template] ?? null;

        if (! $templateDef || ! $templateDef['path']) {
            return redirect()->back()->with('error', __('vela::global.homepage_template_not_found'));
        }

        $jsonPath = $templateDef['path'] . '/home-template.json';
        if (! file_exists($jsonPath)) {
            return redirect()->back()->with('error', __('vela::global.homepage_template_not_found'));
        }

        $rowsData = json_decode(file_get_contents($jsonPath), true);
        if (! is_array($rowsData)) {
            return redirect()->back()->with('error', __('vela::global.homepage_template_invalid'));
        }

        DB::transaction(function () use ($mode, $rowsData) {
            if ($mode === 'replace') {
                $page = Page::where('slug', 'home')->first();
                if ($page) {
                    foreach ($page->rows as $row) {
                        $row->blocks()->delete();
                    }
                    $page->rows()->delete();
                    $page->update(['title' => 'Home', 'status' => 'published']);
                } else {
                    $page = Page::create([
                        'title'        => 'Home',
                        'slug'         => 'home',
                        'locale'       => config('vela.primary_language', 'en'),
                        'status'       => 'published',
                        'order_column' => 0,
                    ]);
                }
            } else {
                $slug    = 'home';
                $counter = 1;
                while (Page::where('slug', $slug)->exists()) {
                    $slug = 'home-' . $counter++;
                }
                $page = Page::create([
                    'title'        => 'Home' . ($counter > 1 ? " ({$counter})" : ''),
                    'slug'         => $slug,
                    'locale'       => config('vela.primary_language', 'en'),
                    'status'       => 'draft',
                    'order_column' => 0,
                ]);
            }

            foreach ($rowsData as $rowOrder => $rowData) {
                $pageRow = PageRow::create([
                    'page_id'          => $page->id,
                    'name'             => $rowData['name'] ?? null,
                    'css_class'        => $rowData['css_class'] ?? null,
                    'background_color' => $rowData['background_color'] ?? null,
                    'background_image' => $rowData['background_image'] ?? null,
                    'text_color'       => $rowData['text_color'] ?? null,
                    'text_alignment'   => $rowData['text_alignment'] ?? null,
                    'padding'          => $rowData['padding'] ?? null,
                    'width'            => in_array($rowData['width'] ?? null, ['full', 'contained'], true) ? $rowData['width'] : 'contained',
                    'order_column'     => $rowData['order'] ?? $rowOrder,
                ]);

                foreach ($rowData['blocks'] ?? [] as $blockOrder => $blockData) {
                    $pageRow->blocks()->create([
                        'column_index'     => $blockData['column_index'] ?? 0,
                        'column_width'     => $blockData['column_width'] ?? 12,
                        'order_column'     => $blockData['order'] ?? $blockOrder,
                        'type'             => $blockData['type'],
                        'content'          => $blockData['content'] ?? null,
                        'settings'         => $blockData['settings'] ?? null,
                        'background_color' => $blockData['background_color'] ?? null,
                        'background_image' => $blockData['background_image'] ?? null,
                        'text_color'       => $blockData['text_color'] ?? null,
                        'text_alignment'   => $blockData['text_alignment'] ?? null,
                        'padding'          => $blockData['padding'] ?? null,
                    ]);
                }
            }
        });

        // Clear all caches that could serve stale homepage content
        $staticPath    = config('vela.static.path', resource_path('static'));
        $homeCachePath = $staticPath . '/home/index.html';
        if (is_file($homeCachePath)) {
            unlink($homeCachePath);
        }
        // Clear locale-specific static home translations
        $homeTransDir = $staticPath . '/home/translations';
        if (is_dir($homeTransDir)) {
            foreach (glob($homeTransDir . '/*.html') as $file) {
                unlink($file);
            }
        }
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        \Illuminate\Support\Facades\Artisan::call('cache:clear');

        $msg = $mode === 'replace'
            ? __('vela::global.homepage_installed_replaced')
            : __('vela::global.homepage_installed_new');

        return redirect()->back()->with('success', $msg);
    }

    private function writeSiteConfig(): void
    {
        app(\VelaBuild\Core\Services\SiteConfigWriter::class)->write();
    }

    public function installPrivacyPage(Request $request)
    {
        abort_if(Gate::denies('config_edit'), 403);

        $slug = $request->input('slug', 'privacy');
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?: 'privacy';

        // Check including soft-deleted pages (unique constraint covers trashed rows)
        $existing = Page::withTrashed()->where('slug', $slug)->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                return redirect()->back()->with('success', __('vela::gdpr.privacy_page_restored'));
            }
            return redirect()->back()->with('error', __('vela::gdpr.privacy_page_exists'));
        }

        // Load privacy page template from JSON
        $jsonPath = __DIR__ . '/../../../../resources/defaults/default-privacy.json';
        if (!file_exists($jsonPath)) {
            return redirect()->back()->with('error', 'Privacy template file not found.');
        }

        $rowsData = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($rowsData)) {
            return redirect()->back()->with('error', 'Privacy template file is invalid.');
        }

        // Replace placeholders
        $siteName = VelaConfig::where('key', 'site_name')->value('value')
            ?: config('app.name', 'Our Website');
        $date = now()->format('F j, Y');

        $json = json_encode($rowsData);
        $json = str_replace(['{{SITE_NAME}}', '{{DATE}}'], [$siteName, $date], $json);
        $rowsData = json_decode($json, true);

        DB::transaction(function () use ($slug, $rowsData) {
            $page = Page::create([
                'title'        => __('vela::gdpr.privacy_page_title'),
                'slug'         => $slug,
                'locale'       => config('vela.primary_language', 'en'),
                'status'       => 'published',
                'order_column' => 99,
            ]);

            foreach ($rowsData as $rowOrder => $rowData) {
                $pageRow = PageRow::create([
                    'page_id'          => $page->id,
                    'name'             => $rowData['name'] ?? null,
                    'css_class'        => $rowData['css_class'] ?? null,
                    'background_color' => $rowData['background_color'] ?? null,
                    'background_image' => $rowData['background_image'] ?? null,
                    'order_column'     => $rowData['order'] ?? $rowOrder,
                ]);

                foreach ($rowData['blocks'] ?? [] as $blockOrder => $blockData) {
                    $pageRow->blocks()->create([
                        'column_index'     => $blockData['column_index'] ?? 0,
                        'column_width'     => $blockData['column_width'] ?? 12,
                        'order_column'     => $blockData['order'] ?? $blockOrder,
                        'type'             => $blockData['type'],
                        'content'          => $blockData['content'] ?? null,
                        'settings'         => $blockData['settings'] ?? null,
                        'background_color' => $blockData['background_color'] ?? null,
                        'background_image' => $blockData['background_image'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->back()->with('success', __('vela::gdpr.privacy_page_created'));
    }

    private function writeRobotsTxt(): void
    {
        $settings = VelaConfig::whereIn('key', [
            'visibility_mode', 'visibility_noindex', 'visibility_block_ai',
            'content_signal_ai_train', 'content_signal_search', 'content_signal_ai_input',
        ])->pluck('value', 'key')->toArray();

        $mode = $settings['visibility_mode'] ?? 'public';
        $noindex = ($settings['visibility_noindex'] ?? '0') === '1';
        $blockAi = ($settings['visibility_block_ai'] ?? '0') === '1';

        $lines = [];

        if ($mode === 'restricted' && $noindex) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        if ($mode === 'restricted' && $blockAi) {
            $bots = ['GPTBot','ChatGPT-User','Google-Extended','CCBot','anthropic-ai',
                'ClaudeBot','Claude-Web','Bytespider','Diffbot','FacebookBot',
                'Applebot-Extended','PerplexityBot','Amazonbot','Cohere-ai','AI2Bot','Scrapy','img2dataset'];
            foreach ($bots as $bot) {
                $lines[] = "User-agent: {$bot}";
                $lines[] = 'Disallow: /';
                $lines[] = '';
            }
        }

        if (empty($lines)) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . url('/sitemap.xml');
        }

        $aiTrain = $settings['content_signal_ai_train'] ?? 'no';
        $search = $settings['content_signal_search'] ?? 'yes';
        $aiInput = $settings['content_signal_ai_input'] ?? 'yes';

        $lines[] = '';
        $lines[] = '# Content Signals (draft-romm-aipref-contentsignals)';
        $lines[] = "Content-Signal: ai-train={$aiTrain}, search={$search}, ai-input={$aiInput}";

        $path = public_path('robots.txt');
        file_put_contents($path, implode("\n", $lines));
    }

    private function deleteStaticDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
