<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\Tools\GoogleAnalyticsService;
use VelaBuild\Core\Services\ToolSettingsService;

class GoogleAnalyticsController extends Controller
{
    public function __construct(
        private ToolSettingsService $settings,
        private GoogleAnalyticsService $gaService,
    ) {}

    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $canConfigure = Gate::allows('admin_tools_access');

        return view('vela::admin.tools.google-analytics', [
            'canConfigure' => $canConfigure,
            'measurementId' => $this->settings->get('ga_measurement_id'),
            'hasMeasurementId' => $this->settings->hasKey('ga_measurement_id'),
            'hasReportingAccess' => $this->gaService->hasReportingAccess(),
            'measurementIdLocked' => $this->settings->isEnvLocked('ga_measurement_id'),
            'propertyIdLocked' => $this->settings->isEnvLocked('ga_property_id'),
            'serviceKeyLocked' => $this->settings->isEnvLocked('ga_service_account_key'),
            'maskedServiceKey' => $this->settings->getMaskedValue('ga_service_account_key'),
            'propertyId' => $this->settings->get('ga_property_id'),
            'emStatus' => $this->gaService->getEnhancedMeasurementStatus(),
        ]);
    }

    public function updateConfig(Request $request)
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'ga_measurement_id' => 'nullable|string|max:50',
            'ga_property_id' => 'nullable|string|max:50',
            'ga_service_account_key' => 'nullable|string',
        ]);

        foreach (['ga_measurement_id', 'ga_property_id', 'ga_service_account_key'] as $key) {
            if (!$this->settings->isEnvLocked($key) && $request->has($key)) {
                $val = $request->input($key);
                if ($val === '') {
                    $this->settings->set($key, null);
                } elseif ($val !== null && $val !== 'unchanged') {
                    $this->settings->set($key, $val);
                }
            }
        }

        return redirect()->back()->with('message', __('vela::tools.analytics.settings_saved'));
    }

    public function reports(Request $request)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $dateRange = $request->get('range', '30daysAgo');
        $allowed = ['7daysAgo', '30daysAgo', '90daysAgo'];
        if (!in_array($dateRange, $allowed)) {
            $dateRange = '30daysAgo';
        }

        $data = $this->gaService->getReport($dateRange);

        return response()->json([
            'data' => $data,
            'cached_at' => now()->toIso8601String(),
        ]);
    }
}
