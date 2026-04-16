<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Vela;

class ToolsController extends Controller
{
    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $tools = app(Vela::class)->tools()->categorized();

        // Resolve live status for each tool
        $toolStatuses = [];
        foreach (app(Vela::class)->tools()->all() as $name => $tool) {
            if (is_callable($tool['status'])) {
                $toolStatuses[$name] = call_user_func($tool['status']);
            } else {
                $toolStatuses[$name] = 'not_configured';
            }
        }

        return view('vela::admin.tools.index', [
            'tools' => $tools,
            'statuses' => $toolStatuses,
        ]);
    }
}
