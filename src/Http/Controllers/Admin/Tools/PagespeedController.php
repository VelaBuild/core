<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Jobs\RunPagespeedJob;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PagespeedResult;

class PagespeedController extends Controller
{
    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $results = PagespeedResult::orderBy('created_at', 'desc')
            ->take(50)
            ->get();

        $pages = Page::where('status', 'published')->pluck('slug')->map(fn($s) => url($s));
        $posts = Content::where('status', 'published')->where('type', 'post')->pluck('slug')->map(fn($s) => url("posts/{$s}"));
        $urls = $pages->merge($posts)->merge(collect([url('/')]));

        return view('vela::admin.tools.pagespeed', [
            'results' => $results,
            'urls' => $urls,
        ]);
    }

    public function scan(Request $request)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $request->validate([
            'url' => 'required|url',
        ]);

        $recent = PagespeedResult::where('url', $request->input('url'))
            ->where('created_at', '>=', now()->subMinutes(5))
            ->first();

        if ($recent) {
            return response()->json([
                'message' => __('vela::tools.pagespeed.recent_scan_exists_message'),
                'result' => $recent,
            ]);
        }

        RunPagespeedJob::dispatch($request->input('url'));

        return response()->json(['message' => __('vela::tools.pagespeed.scan_queued')]);
    }

    public function show(int $id)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $result = PagespeedResult::findOrFail($id);

        return response()->json($result);
    }
}
