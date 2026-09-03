<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Commands\DesignToSite;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\SiteConfigWriter;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuildRunner;
use VelaBuild\Core\Services\ScreenshotService;

/**
 * Admin UI for building a site from a design.
 *
 * Upload what the site should look like, press Build, watch it happen. The
 * work runs in a process of its own — see DesignBuildRunner — so this
 * controller only ever starts it and reports on it.
 */
class DesignBuilderController extends Controller
{
    public function __construct(
        protected DesignBuildRunner $runner,
        protected ScreenshotService $screenshots,
        protected AiProviderManager $ai,
    ) {}

    public function index()
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        return view('vela::admin.settings.design-builder', [
            'files' => $this->runner->designFiles(),
            'brief' => $this->brief(),
            'status' => $this->runner->status()->read(),
            'running' => $this->runner->status()->isRunning(),
            'results' => $this->runner->results(),
            'readiness' => $this->readiness(),
            'preview' => $this->previewPage(),
        ]);
    }

    /**
     * The page a finished build put its work on, if there is one.
     */
    private function previewPage(): ?Page
    {
        return Page::where('slug', DesignToSite::PREVIEW_SLUG)->first();
    }

    /**
     * Make what the build produced the site's front page.
     *
     * The homepage that was there is kept, unlisted, rather than overwritten:
     * someone trying a design out should be able to change their mind, and a
     * page they spent time on is not ours to throw away.
     */
    public function useAsHomepage(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $preview = $this->previewPage();

        if (!$preview) {
            return back()->withErrors(['build' => 'There is no design to use yet. Run a build first.']);
        }

        DB::transaction(function () use ($preview) {
            $current = Page::where('slug', 'home')->first();

            if ($current) {
                $current->update([
                    'slug' => 'home-' . now()->format('Y-m-d-His'),
                    'status' => 'unlisted',
                ]);
            }

            $preview->update([
                'slug' => 'home',
                'status' => 'published',
            ]);

            // The build names its page after the site the design is for, and
            // renames nothing while it is only being looked at. This is the
            // moment the design becomes theirs, so the name comes with it.
            $name = trim((string) $preview->title);

            if ($name !== '' && $name !== 'Design preview') {
                VelaConfig::updateOrCreate(['key' => 'site_name'], ['value' => $name]);
                app(SiteConfigWriter::class)->write();
            }

            // Up to here the design's theme and navigation existed only on the
            // page being looked at. This is the moment they become the site's
            // — and what they replace is kept, so changing your mind about a
            // design does not cost you the navigation you wrote before it.
            app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->promote();
        });

        return back()->with('message', 'That design is now your homepage. The one it replaced is still there, unlisted.');
    }

    public function upload(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'files' => 'required|array',
            'files.*' => 'file|mimes:png,jpg,jpeg,gif,webp,svg|max:20480',
        ]);

        $path = $this->runner->designPath();
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        foreach ($request->file('files') as $upload) {
            // The uploader's own name, kept only as far as it is safe to: the
            // design folder is read back by name, and the role a file plays is
            // guessed from it.
            $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $upload->getClientOriginalName());
            $upload->move($path, $name);
        }

        return back()->with('message', 'Design uploaded.');
    }

    public function deleteFile(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $name = basename((string) $request->input('name'));
        $file = $this->runner->designPath() . '/' . $name;

        if ($name !== '' && is_file($file)) {
            unlink($file);
        }

        return back()->with('message', 'Removed ' . $name . '.');
    }

    public function saveBrief(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $request->validate(['brief' => 'nullable|string|max:20000']);

        $path = $this->runner->designPath();
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path . '/instructions.md', (string) $request->input('brief'));

        return back()->with('message', 'Brief saved.');
    }

    public function start(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $request->validate(['max_loops' => 'nullable|integer|min:1|max:10']);

        try {
            $this->runner->start(
                config('app.url'),
                (int) ($request->input('max_loops') ?: 3),
                $request->boolean('generate_images')
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['build' => $e->getMessage()]);
        }

        return back()->with('message', 'Building. This takes a few minutes.');
    }

    /**
     * Polled by the page while a build is under way.
     */
    public function status()
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        return response()->json([
            'running' => $this->runner->status()->isRunning(),
            'status' => $this->runner->status()->read(),
        ]);
    }

    /**
     * A capture from a finished build. Served rather than linked because the
     * design folder sits in storage, outside the web root.
     */
    public function capture(string $name)
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $file = $this->runner->designPath() . '/output/' . basename($name);

        abort_unless(is_file($file) && str_ends_with($file, '.png'), Response::HTTP_NOT_FOUND);

        return response()->file($file);
    }

    public function design(string $name)
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $file = $this->runner->designPath() . '/' . basename($name);

        abort_unless(is_file($file), Response::HTTP_NOT_FOUND);

        return response()->file($file);
    }

    private function brief(): string
    {
        $file = $this->runner->designPath() . '/instructions.md';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * What still needs doing before a build can run, in words the operator
     * can act on. Everything checked here is checked again by the command;
     * this exists so the page can say so before the button is pressed.
     */
    private function readiness(): array
    {
        $checks = [];

        $hasProvider = $this->ai->hasTextProvider();
        $planned = $hasProvider
            ? app(\VelaBuild\Core\Services\DesignBuilderService::class)->plannedProvider()
            : null;

        $checks[] = [
            // A tick here used to mean only "a key exists", which is true of a
            // site whose builds come out well and one whose builds come out
            // thin. The model is the largest difference between those two, and
            // this row is the one place it can be said before the button is
            // pressed rather than after the disappointment.
            'ok' => $hasProvider && ($planned['concern'] ?? null) === null,
            'label' => 'AI provider',
            'detail' => $hasProvider
                ? $this->whatItWillBuildWith($planned)
                : 'Add an OpenAI, Anthropic or Gemini key under Settings → AI.',
        ];

        $browser = $this->screenshots->findChromeBinary();
        $cloud = app(\VelaBuild\Core\Services\BrowserRenderingService::class)->isConfigured();
        $checks[] = [
            'ok' => true,
            'label' => 'Screenshots',
            'detail' => $browser
                ? 'Using the browser already on this machine.'
                : ($cloud
                    ? 'Using the configured cloud rendering service.'
                    : 'No browser found — one will be downloaded on the first build.'),
        ];

        $canDetach = $this->runner->canRunDetached();
        $checks[] = [
            'ok' => $canDetach,
            'label' => 'Background builds',
            'detail' => $canDetach
                ? 'This server can run a build in the background.'
                : 'This server does not allow background processes. Run "php artisan vela:design-to-site" from a terminal.',
        ];

        // Counted by what each file will be taken for, not just how many there
        // are: uploading adds to the folder rather than replacing it, so
        // someone who uploads a second design still has the first, and a
        // build shown two designs tries to satisfy both.
        $files = $this->runner->designFiles();
        $designs = count(array_filter($files, fn ($file) => ($file['role'] ?? '') === 'design'));

        $checks[] = [
            'ok' => $designs > 0,
            'label' => 'Design',
            'detail' => match (true) {
                $designs === 0 => 'Upload a picture of what the site should look like.',
                $designs === 1 => 'One picture, which the site will be built to match.',
                default => $designs . ' pictures, and the site will be built to match all of them. '
                    . 'Remove any you did not mean to include.',
            },
        ];

        return $checks;
    }

    /**
     * Which model the build will run on, in the one line this box gives it.
     *
     * Every other row here is a single short sentence, and this one grew into
     * a paragraph nobody would read. What matters is the name, and — when
     * there is one — the fault and who can fix it. The measurement behind the
     * verdict lives in the code, not on the screen.
     */
    private function whatItWillBuildWith(?array $planned): string
    {
        if (!$planned) {
            // A key exists, but nothing that can look at a picture. The build
            // refuses for the same reason.
            return 'No provider here can read images. Add an OpenAI, Anthropic or Gemini key under Settings → AI.';
        }

        $name = $this->providerName($planned['provider']);
        $model = $planned['model'] !== '' ? ' (' . $planned['model'] . ')' : '';

        if (($planned['concern'] ?? null) !== null) {
            // There is a field for this now, so the advice names it rather
            // than naming a person the reader may not have.
            return $name . $model . ' — ' . $planned['concern']
                . '. Change it under Settings → AI.';
        }

        return 'Building with ' . $name . $model . '.';
    }

    /** The provider as people write it, rather than as the code keys it. */
    private function providerName(string $key): string
    {
        return [
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Gemini',
            'vela gateway' => 'the Vela gateway',
        ][$key] ?? $key;
    }
}
