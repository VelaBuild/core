<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\CmsFileUpgrader;

/**
 * Settings → CMS files: the starter files this release updated, with a diff
 * and a decision per file. Same service as `php artisan vela:upgrade-files`.
 *
 * Changing these files changes the site's own code, so only an Admin who can
 * edit settings gets here.
 */
class CmsFilesController extends Controller
{
    public function __construct(private CmsFileUpgrader $upgrader)
    {
    }

    public function index()
    {
        $this->authorizeManage();

        $files = collect($this->upgrader->report())
            ->sortBy(fn ($i) => [array_search($i['status'], ['review', 'remove', 'update', 'add', 'removed-customised', 'customised', 'deleted', 'kept', 'current'], true), $i['path']])
            ->values();

        return view('vela::admin.cms-files.index', [
            'files'   => $files,
            'version' => $this->upgrader->version(),
        ]);
    }

    public function diff(Request $request)
    {
        $this->authorizeManage();

        $path = (string) $request->query('path', '');
        try {
            $diff = $this->upgrader->diff($path);
        } catch (\InvalidArgumentException $e) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response($diff, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function apply(Request $request)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'path'   => ['required', 'string', 'max:200'],
            'action' => ['required', 'in:overwrite,add,merge,keep,skip,delete'],
            'allow_conflicts' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->upgrader->apply($data['path'], $data['action'], $this->actor(), (bool) ($data['allow_conflicts'] ?? false));
        } catch (\InvalidArgumentException $e) {
            abort(Response::HTTP_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $result['ok']
            ? back()->with('message', "{$data['path']}: {$data['action']} done." . (!empty($result['backup']) ? ' Previous version backed up.' : ''))
            : back()->with('error', "{$data['path']}: " . ($result['message'] ?? 'not applied'))->with('conflict_path', ($result['conflicts'] ?? 0) > 0 ? $data['path'] : null);
    }

    public function applySafe()
    {
        $this->authorizeManage();

        $results = $this->upgrader->applySafe([], $this->actor());
        $failed = array_filter($results, fn ($r) => !$r['ok']);

        return back()->with($failed ? 'error' : 'message', count($results) - count($failed) . ' file(s) updated'
            . ($failed ? ', ' . count($failed) . ' failed: ' . implode(', ', array_column($failed, 'path')) : '.'));
    }

    private function authorizeManage(): void
    {
        abort_unless(CmsFileUpgrader::userMayManage(auth()->user()), Response::HTTP_FORBIDDEN);
    }

    private function actor(): string
    {
        $user = auth()->user();

        return 'admin:' . ($user->id ?? '?') . ':' . ($user->email ?? '');
    }
}
