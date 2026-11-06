<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\CmsFileUpgrader;
use VelaBuild\Core\Services\SitePack\SitePacker;

/**
 * Settings → Move to Vela Hosted: download this site as one archive (the same
 * file `php artisan vela:pack` writes) to upload in the hosting panel. Only on
 * self-hosted installs; the archive holds the app key and every row, so only
 * an Admin gets it.
 */
class MoveToHostedController extends Controller
{
    public function index()
    {
        $this->authorizeManage();
        $packer = new SitePacker();

        return view('vela::admin.move-to-hosted.index', [
            'blockers' => $packer->blockers(),
        ]);
    }

    public function download()
    {
        $this->authorizeManage();
        $packer = new SitePacker();
        if ($packer->blockers()) {
            return back()->with('error', implode(' ', $packer->blockers()));
        }
        @set_time_limit(0);
        $dir = storage_path('app/exports');
        @mkdir($dir, 0700, true);
        $name = 'vela-site-' . gmdate('Ymd-His') . '.tar.gz';
        $result = $packer->pack($dir . '/' . $name);

        return response()->download($result['path'], $name, ['Content-Type' => 'application/gzip'])->deleteFileAfterSend();
    }

    private function authorizeManage(): void
    {
        abort_if(config('vela.hosting.site_id'), Response::HTTP_NOT_FOUND);
        abort_unless(CmsFileUpgrader::userMayManage(auth()->user()), Response::HTTP_FORBIDDEN);
    }
}
