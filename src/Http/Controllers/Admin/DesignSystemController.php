<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\DesignSystem;

/**
 * Admin UI for the /designsystem folder.
 *
 * One page with four sections — files, palette, fonts, import — each
 * posting to its own endpoint so a mistake in one doesn't clobber another.
 */
class DesignSystemController extends Controller
{
    public function __construct(
        protected DesignSystem $ds,
    ) {}

    public function index()
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $this->ds->ensureStructure();

        return view('vela::admin.settings.design-system.index', [
            'files'   => $this->ds->files(),
            'palette' => $this->ds->palette(),
            'fonts'   => $this->ds->fonts(),
            'totalBytes' => $this->ds->totalBytes(),
        ]);
    }

    // ── File ops ───────────────────────────────────────────────────────────

    public function uploadFile(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'file'   => 'required|file|max:25600', // 25 MB
            'rename' => 'nullable|string|max:128',
        ]);

        $upload = $request->file('file');
        $target = $request->input('rename') ?: $upload->getClientOriginalName();

        try {
            $this->ds->adoptUpload($upload->getRealPath(), $target);
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', __('Uploaded :name.', ['name' => $target]));
    }

    public function deleteFile(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $name = (string) $request->input('name', '');
        try {
            $this->ds->delete($name);
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', __('Deleted :name.', ['name' => $name]));
    }

    public function downloadFile(string $name)
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        try {
            $stream = $this->ds->readStream($name);
        } catch (\Throwable $e) {
            abort(404);
        }

        return response()->stream(function () use ($stream) {
            while (!feof($stream)) {
                echo fread($stream, 8192);
            }
            fclose($stream);
        }, 200, [
            'Content-Type'        => $this->ds->mime($name),
            'Content-Disposition' => 'inline; filename="' . addslashes($name) . '"',
            'Cache-Control'       => 'private, max-age=60',
        ]);
    }

    // ── Palette ────────────────────────────────────────────────────────────

    public function savePalette(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $data = Validator::make($request->all(), [
            'palette_name'            => 'nullable|string|max:80',
            'entries'                 => 'nullable|array',
            'entries.*.name'          => 'required_with:entries|string|max:60',
            'entries.*.slug'          => 'nullable|string|max:60',
            'entries.*.hex'           => 'required_with:entries|string|max:9',
            'entries.*.description'   => 'nullable|string|max:200',
        ])->validate();

        $this->ds->setPalette([
            'name'    => $data['palette_name'] ?? 'Default',
            'entries' => $data['entries'] ?? [],
        ]);

        return back()->with('status', __('Palette saved.'));
    }

    // ── Fonts ──────────────────────────────────────────────────────────────

    public function saveFonts(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $data = Validator::make($request->all(), [
            'entries'                  => 'nullable|array',
            'entries.*.role'           => 'nullable|string|max:40',
            'entries.*.family'         => 'required_with:entries|string|max:80',
            'entries.*.source_url'     => 'nullable|url|max:500',
            'entries.*.weights'        => 'nullable|string|max:80', // comma-separated in the form
            'entries.*.fallback'       => 'nullable|string|max:40',
        ])->validate();

        $entries = [];
        foreach ($data['entries'] ?? [] as $e) {
            $weights = array_values(array_filter(array_map('intval',
                preg_split('/[,\s]+/', (string) ($e['weights'] ?? '')))));
            $entries[] = [
                'role'       => $e['role'] ?? '',
                'family'     => $e['family'],
                'source_url' => $e['source_url'] ?? '',
                'weights'    => $weights,
                'fallback'   => $e['fallback'] ?? 'sans-serif',
            ];
        }

        $this->ds->setFonts(['entries' => $entries]);

        return back()->with('status', __('Fonts saved.'));
    }

    // ── Import ─────────────────────────────────────────────────────────────

    public function importZip(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'zip' => 'required|file|mimes:zip|max:102400', // 100 MB
        ]);

        try {
            $result = $this->ds->importZip($request->file('zip')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['zip' => $e->getMessage()]);
        }

        return back()->with('status', $this->importSummary($result));
    }

    public function importZipFromUrl(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'zip_url' => 'required|url|max:800',
        ]);

        try {
            $result = $this->ds->importZipFromUrl($data['zip_url']);
        } catch (\Throwable $e) {
            return back()->withErrors(['zip_url' => $e->getMessage()]);
        }

        return back()->with('status', $this->importSummary($result));
    }

    private function importSummary(array $result): string
    {
        $imported = count($result['imported'] ?? []);
        $skipped  = count($result['skipped']  ?? []);
        $msg = __(':n file(s) imported.', ['n' => $imported]);
        if ($skipped > 0) {
            $names = implode(', ', array_column($result['skipped'], 'name'));
            $msg .= ' ' . __(':n skipped: :names', ['n' => $skipped, 'names' => $names]);
        }
        return $msg;
    }
}
