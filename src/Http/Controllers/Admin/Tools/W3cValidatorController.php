<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;

class W3cValidatorController extends Controller
{
    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        return view('vela::admin.tools.w3c-validator');
    }

    public function check(Request $request)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $request->validate([
            'url' => 'required|url',
        ]);

        $url = $request->input('url');

        $response = Http::timeout(30)
            ->get('https://html5.validator.nu/', [
                'doc' => $url,
                'out' => 'json',
            ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => __('vela::tools.w3c.validator_http_error', ['status' => $response->status()]),
            ]);
        }

        $data = $response->json();

        return response()->json([
            'success' => true,
            'messages' => $data['messages'] ?? [],
            'url' => $url,
        ]);
    }
}
