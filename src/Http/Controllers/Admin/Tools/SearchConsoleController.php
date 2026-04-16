<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\Tools\SearchConsoleService;
use VelaBuild\Core\Services\ToolSettingsService;

class SearchConsoleController extends Controller
{
    public function __construct(
        private ToolSettingsService $settings,
        private SearchConsoleService $gscService,
    ) {}

    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $canConfigure = Gate::allows('admin_tools_access');

        return view('vela::admin.tools.search-console', [
            'canConfigure' => $canConfigure,
            'siteUrl' => $this->settings->get('gsc_site_url'),
            'siteUrlLocked' => $this->settings->isEnvLocked('gsc_site_url'),
            'hasReportingAccess' => $this->gscService->isConfigured(),
            'maskedServiceKey' => $this->settings->getMaskedValue('ga_service_account_key'),
        ]);
    }

    public function updateConfig(Request $request)
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'gsc_site_url' => 'nullable|url|max:255',
        ]);

        if (!$this->settings->isEnvLocked('gsc_site_url') && $request->has('gsc_site_url')) {
            $val = $request->input('gsc_site_url');
            if ($val === '') {
                $this->settings->set('gsc_site_url', null);
            } elseif ($val !== null && $val !== 'unchanged') {
                $this->settings->set('gsc_site_url', $val);
            }
        }

        return redirect()->back()->with('message', __('vela::tools.search_console.settings_saved'));
    }

    public function reports(Request $request)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $dateRange = $request->get('range', '28daysAgo');
        $allowed = ['7daysAgo', '28daysAgo', '90daysAgo'];
        if (!in_array($dateRange, $allowed)) {
            $dateRange = '28daysAgo';
        }

        $data = $this->gscService->getSearchAnalytics($dateRange);

        return response()->json([
            'data' => $data,
            'cached_at' => now()->toIso8601String(),
        ]);
    }
}
