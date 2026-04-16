<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;

class OfflineController extends Controller
{
    public function show()
    {
        $template = config('vela.template.active', 'default');
        $view = view()->exists("vela::templates.{$template}.offline")
            ? "vela::templates.{$template}.offline"
            : 'vela::pwa.offline';
        return view($view);
    }
}
