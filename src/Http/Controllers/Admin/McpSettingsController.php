<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\McpSettingsService;

class McpSettingsController extends Controller
{
    public function __construct(private McpSettingsService $mcp)
    {
    }

    public function index()
    {
        abort_if(Gate::denies('config_access'), 403);

        $status = $this->mcp->getStatus();

        return view('vela::admin.settings.mcp', compact('status'));
    }

    public function update(Request $request)
    {
        abort_if(Gate::denies('config_edit'), 403);

        // Enabled toggle
        if (!$this->mcp->isEnvLocked('enabled')) {
            $this->mcp->set('enabled', $request->boolean('mcp_enabled') ? '1' : '0');
        }

        // API key
        if (!$this->mcp->isEnvLocked('api_key')) {
            $key = $request->input('mcp_api_key', '');

            if ($key === 'unchanged') {
                // User didn't touch it — skip
            } elseif ($key === '') {
                // Cleared
                $this->mcp->set('api_key', '');
            } else {
                $this->mcp->set('api_key', $key);
            }
        }

        // Public Content API toggle
        \VelaBuild\Core\Models\VelaConfig::updateOrCreate(
            ['key' => 'public_api_enabled'],
            ['value' => $request->boolean('public_api_enabled') ? '1' : '0']
        );

        return redirect()->back()->with('success', __('vela::mcp.settings_saved'));
    }

    public function generateKey()
    {
        abort_if(Gate::denies('config_edit'), 403);

        $key = $this->mcp->generateApiKey();

        return response()->json(['key' => $key]);
    }
}
