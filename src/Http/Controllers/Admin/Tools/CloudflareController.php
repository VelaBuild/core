<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Jobs\PurgeCloudflareCacheJob;
use VelaBuild\Core\Services\Tools\CloudflareService;
use VelaBuild\Core\Services\ToolSettingsService;

class CloudflareController extends Controller
{
    public function __construct(
        private ToolSettingsService $settings,
        private CloudflareService $cloudflare,
    ) {}

    public function index()
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        return view('vela::admin.tools.cloudflare', [
            'isConfigured' => $this->cloudflare->isConfigured(),
            'zoneId' => $this->settings->get('cf_zone_id'),
            'zoneIdLocked' => $this->settings->isEnvLocked('cf_zone_id'),
            'tokenLocked' => $this->settings->isEnvLocked('cf_api_token'),
            'maskedToken' => $this->settings->getMaskedValue('cf_api_token'),
            'purgeMode' => $this->settings->get('cf_purge_mode', 'smart'),
            'lastError' => $this->settings->get('cf_last_error'),
        ]);
    }

    public function updateConfig(Request $request)
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'cf_api_token' => 'nullable|string',
            'cf_zone_id' => 'nullable|string|max:50',
            'cf_purge_mode' => 'nullable|in:smart,full',
        ]);

        foreach (['cf_api_token', 'cf_zone_id', 'cf_purge_mode'] as $key) {
            if (!$this->settings->isEnvLocked($key) && $request->has($key)) {
                $val = $request->input($key);
                if ($val === '') {
                    $this->settings->set($key, null);
                } elseif ($val !== null && $val !== 'unchanged') {
                    $this->settings->set($key, $val);
                }
            }
        }

        // Clear last error on config save
        $this->settings->set('cf_last_error', null);

        // Verify zone ID if both token and zone are set
        if ($this->cloudflare->isConfigured()) {
            if (!$this->cloudflare->verifyZone()) {
                return redirect()->back()->with('error', __('vela::tools.cloudflare.verify_zone_failed'));
            }
        }

        return redirect()->back()->with('message', __('vela::tools.cloudflare.settings_saved'));
    }

    public function purge(Request $request)
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        if (!$this->cloudflare->isConfigured()) {
            return response()->json(['success' => false, 'message' => __('vela::tools.cloudflare.not_configured_error')], 400);
        }

        $type = $request->input('type', 'smart'); // 'smart', 'full', 'urls'
        $urls = $request->input('urls', []);

        if ($type === 'full') {
            $result = $this->cloudflare->purgeAll();
        } elseif ($type === 'urls' && !empty($urls)) {
            $result = $this->cloudflare->purgeUrls($urls);
        } else {
            // Smart purge — purge the homepage + common pages
            $result = $this->cloudflare->purgeUrls([url('/')]);
        }

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => ($result['success'] ?? false) ? __('vela::tools.cloudflare.cache_purged_success') : __('vela::tools.cloudflare.purge_failed_error'),
        ]);
    }

    public function status()
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        if (!$this->cloudflare->isConfigured()) {
            return response()->json(['configured' => false]);
        }

        $zone = $this->cloudflare->getZoneStatus();
        $ssl = $this->cloudflare->getSslSetting();
        $cache = $this->cloudflare->getCacheSetting();
        $pageRules = $this->cloudflare->getPageRules();

        return response()->json([
            'configured' => true,
            'zone' => $zone['result'] ?? null,
            'ssl' => $ssl['result'] ?? null,
            'cache' => $cache['result'] ?? null,
            'page_rules' => $pageRules['result'] ?? [],
        ]);
    }
}
