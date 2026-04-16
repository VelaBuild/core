<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Vela;

class HomeController extends Controller
{
    public function index()
    {
        $vela = app(Vela::class);
        $userId = auth('vela')->id();

        // Load user preferences
        $prefsConfig = VelaConfig::where('key', "dashboard_prefs_{$userId}")->first();
        $prefs = $prefsConfig ? json_decode($prefsConfig->value, true) : [];

        $userOrder = $prefs['order'] ?? [];
        $disabledWidgets = $prefs['disabled'] ?? [];

        // Get all registered widgets in default order
        $allWidgets = $vela->widgets()->ordered();

        // Apply user ordering if set
        if (!empty($userOrder)) {
            $ordered = [];
            foreach ($userOrder as $name) {
                if (isset($allWidgets[$name])) {
                    $ordered[$name] = $allWidgets[$name];
                }
            }
            // Append any new widgets not in saved order
            foreach ($allWidgets as $name => $widget) {
                if (!isset($ordered[$name])) {
                    $ordered[$name] = $widget;
                }
            }
            $allWidgets = $ordered;
        }

        // Resolve data for enabled widgets
        $widgetDataMap = [];
        foreach ($allWidgets as $name => $widget) {
            if (!in_array($name, $disabledWidgets) && is_callable($widget['data'])) {
                $widgetDataMap[$name] = call_user_func($widget['data']);
            }
        }

        return view('vela::admin.home', [
            'widgets' => $allWidgets,
            'disabledWidgets' => $disabledWidgets,
            'widgetDataMap' => $widgetDataMap,
        ]);
    }

    public function savePreferences()
    {
        $userId = auth('vela')->id();
        $data = request()->validate([
            'order' => 'array',
            'order.*' => 'string',
            'disabled' => 'array',
            'disabled.*' => 'string',
        ]);

        VelaConfig::updateOrCreate(
            ['key' => "dashboard_prefs_{$userId}"],
            ['value' => json_encode([
                'order' => $data['order'] ?? [],
                'disabled' => $data['disabled'] ?? [],
            ])]
        );

        return response()->json(['success' => true]);
    }
}
