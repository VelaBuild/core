<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\ToolSettingsService;

/**
 * Tracking & conversion pixels settings page.
 *
 * Lives outside the big ConfigController.group() switch because its storage
 * layer is ToolSettingsService (tool_-prefixed, some encrypted), not the
 * generic vela_configs KV that ConfigController drives.
 */
class TrackingController extends Controller
{
    public function __construct(
        protected ToolSettingsService $tools,
    ) {}

    public function index()
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $keys = [
            'ga_measurement_id',
            'ga4_api_secret',
            'gtm_container_id',
            'meta_pixel_id',
            'meta_capi_access_token',
            'meta_capi_test_event_code',
            'google_ads_id',
            'google_ads_purchase_label',
        ];

        $values = [];
        $locked = [];
        foreach ($keys as $k) {
            $values[$k] = $this->tools->get($k, '');
            $locked[$k] = $this->tools->isEnvLocked($k);
        }

        // Sensitive — mask for display.
        foreach (['meta_capi_access_token', 'ga4_api_secret'] as $k) {
            $values[$k] = $values[$k] ? $this->mask((string) $values[$k]) : '';
        }

        return view('vela::admin.settings.tracking', [
            'values' => $values,
            'locked' => $locked,
        ]);
    }

    public function update(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $data = Validator::make($request->all(), [
            'ga_measurement_id'          => 'nullable|string|max:50',
            'ga4_api_secret'             => 'nullable|string|max:120',
            'gtm_container_id'           => 'nullable|string|max:50',
            'meta_pixel_id'              => 'nullable|string|max:50',
            'meta_capi_access_token'     => 'nullable|string|max:500',
            'meta_capi_test_event_code'  => 'nullable|string|max:40',
            'google_ads_id'              => 'nullable|string|max:50',
            'google_ads_purchase_label'  => 'nullable|string|max:80',
        ])->validate();

        $sensitive = ['meta_capi_access_token', 'ga4_api_secret'];

        foreach ($data as $key => $val) {
            if ($this->tools->isEnvLocked($key)) {
                continue;
            }
            // Masked sentinel from the form means "don't change".
            if (in_array($key, $sensitive, true) && $val === 'unchanged') {
                continue;
            }
            $this->tools->set($key, $val === '' ? null : $val);
        }

        return redirect()
            ->route('vela.admin.settings.tracking.index')
            ->with('status', __('Tracking settings saved.'));
    }

    private function mask(string $val): string
    {
        if (strlen($val) < 8) {
            return str_repeat('•', strlen($val));
        }
        return str_repeat('•', max(strlen($val) - 4, 4)) . substr($val, -4);
    }
}
