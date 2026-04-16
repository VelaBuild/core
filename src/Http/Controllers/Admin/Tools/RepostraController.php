<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\ToolSettingsService;

class RepostraController extends Controller
{
    public function __construct(
        private ToolSettingsService $settings,
    ) {}

    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $canConfigure = Gate::allows('admin_tools_access');

        // Get recent imports
        $recentImports = Content::where('keyword', 'LIKE', '%repostra%')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();

        return view('vela::admin.tools.repostra', [
            'canConfigure' => $canConfigure,
            'isConfigured' => $this->settings->hasKey('repostra_webhook_secret'),
            'webhookUrl' => url('/webhook/repostra'),
            'defaultStatus' => $this->settings->get('repostra_default_status', 'draft'),
            'defaultAuthorId' => $this->settings->get('repostra_default_author_id'),
            'recentImports' => $recentImports,
        ]);
    }

    public function updateConfig(Request $request)
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'repostra_webhook_secret' => 'nullable|string',
            'repostra_default_status' => 'nullable|in:draft,published',
            'repostra_default_author_id' => 'nullable|integer|exists:vela_users,id',
        ]);

        foreach (['repostra_webhook_secret', 'repostra_default_status', 'repostra_default_author_id'] as $key) {
            if (!$this->settings->isEnvLocked($key) && $request->has($key)) {
                $val = $request->input($key);
                if ($val === '') {
                    $this->settings->set($key, null);
                } elseif ($val !== null && $val !== 'unchanged') {
                    $this->settings->set($key, $val);
                }
            }
        }

        return redirect()->back()->with('message', __('vela::tools.repostra.settings_saved'));
    }
}
