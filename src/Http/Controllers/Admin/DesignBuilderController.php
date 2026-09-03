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
    /**
     * What keeping a design replaced, so it can be put back.
     *
     * The page's own slug is not enough on its own: it has to come back
     * published or unlisted as it was, and the site's name has to come back
     * with it.
     */
    private const SUPERSEDED_HOME_KEY = 'design_superseded_home';

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
            'buildWith' => $this->choiceOfModel(),
            'canRestore' => VelaConfig::where('key', self::SUPERSEDED_HOME_KEY)->exists()
                || app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->canDemote(),
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
                $archived = $this->slugToParkTheHomepageUnder();

                $current->update([
                    'slug' => $archived,
                    'status' => 'unlisted',
                ]);

                // Which one to bring back, and what it was called. Kept as a
                // note rather than found by guessing: "the newest page whose
                // slug starts with home-" is a rule that picks the wrong page
                // the moment a design is kept twice, and the site name has to
                // go back with it or the header keeps the design's.
                VelaConfig::updateOrCreate(['key' => self::SUPERSEDED_HOME_KEY], ['value' => json_encode([
                    'slug' => $archived,
                    'status' => 'published',
                    'site_name' => (string) (VelaConfig::where('key', 'site_name')->value('value') ?? ''),
                ])]);
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

        return back()->with('message', 'That design is now your homepage. The one it replaced is still there, unlisted, '
            . 'and "Put back the site I had" brings it back.');
    }

    /**
     * A slug to park the current homepage under, free of anybody else's.
     *
     * Seconds are not fine enough: keeping a design and putting it back inside
     * the same second produced the same slug twice, and pages are unique on
     * (locale, slug) — so the second swap threw and rolled the whole thing
     * back, in the one place where a person is most likely to press twice.
     */
    private function slugToParkTheHomepageUnder(): string
    {
        $base = 'home-' . now()->format('Y-m-d-His');
        $slug = $base;

        for ($n = 2; Page::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * Undo keeping a design: the homepage, the theme and the navigation.
     *
     * Trying a design is only safe if it can be untried, and until now only
     * two of the three could be. The homepage was parked under a timestamped
     * slug and the menus under superseded_ ones, both findable by somebody who
     * knew where to look; the theme was simply overwritten, so a person who
     * switched back in Settings → Appearance got the old clothes on the new
     * content and reasonably concluded the theme had eaten their pages.
     *
     * Nothing is deleted here either — what this replaces is parked the same
     * way, so pressing it does not cost the design.
     */
    public function restore(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $note = json_decode((string) VelaConfig::where('key', self::SUPERSEDED_HOME_KEY)->value('value'), true);
        $frame = app(\VelaBuild\Core\Services\DesignPreviewFrame::class);

        if (!is_array($note) && !$frame->canDemote()) {
            return back()->withErrors(['build' => 'There is no earlier site to go back to.']);
        }

        DB::transaction(function () use ($note, $frame) {
            if (is_array($note) && ($previous = Page::where('slug', $note['slug'] ?? '')->first())) {
                // The design goes where the old homepage was, so this can be
                // undone as many times as somebody changes their mind.
                if ($kept = Page::where('slug', 'home')->first()) {
                    $kept->update([
                        'slug' => $this->slugToParkTheHomepageUnder(),
                        'status' => 'unlisted',
                    ]);
                }

                $previous->update([
                    'slug' => 'home',
                    'status' => $note['status'] ?? 'published',
                ]);

                if (($name = trim((string) ($note['site_name'] ?? ''))) !== '') {
                    VelaConfig::updateOrCreate(['key' => 'site_name'], ['value' => $name]);
                }
            }

            VelaConfig::where('key', self::SUPERSEDED_HOME_KEY)->delete();
            $frame->demote();

            app(SiteConfigWriter::class)->write();
        });

        return back()->with('message', 'Your site is back as it was — homepage, theme and navigation. '
            . 'The design is still there, unlisted.');
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

        if ($error = $this->rememberTheChoiceOfModel($request)) {
            return back()->withErrors(['build' => $error]);
        }

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
     * What the two menus beside the Build button are made of.
     *
     * Only providers this site holds a key for: naming one it cannot use is an
     * invitation to pick it and wait for the failure.
     *
     * @return array{provider: string, model: string, options: array<string, array<int, string>>}
     */
    private function choiceOfModel(): array
    {
        $settings = app(\VelaBuild\Core\Services\AiSettingsService::class);
        $options = [];

        foreach (\VelaBuild\Core\Services\DesignBuilderService::MODELS_FOR_BUILDING as $provider => $models) {
            if ($settings->hasApiKey($provider)) {
                $options[$provider] = $models;
            }
        }

        $provider = (string) $settings->get('design_provider', '');
        $model = (string) $settings->get('design_model', '');

        // A model set in .env, or by a newer Vela than this one, is shown as
        // the current choice instead of quietly reverting to something else.
        if ($provider !== '' && $model !== '' && isset($options[$provider]) && !in_array($model, $options[$provider], true)) {
            array_unshift($options[$provider], $model);
        }

        return ['provider' => $provider, 'model' => $model, 'options' => $options];
    }

    /**
     * Keep the provider and model chosen beside the Build button.
     *
     * Saved as part of pressing Build rather than behind a Save of its own:
     * the choice and the build are one action, and a setting that needed
     * saving separately would be the thing everyone forgets.
     *
     * @return string|null a reason it was not saved, or null
     */
    private function rememberTheChoiceOfModel(Request $request): ?string
    {
        if (!$request->has('design_provider')) {
            return null;
        }

        $settings = app(\VelaBuild\Core\Services\AiSettingsService::class);
        $provider = trim((string) $request->input('design_provider'));
        $model = trim((string) $request->input('design_model'));

        if ($provider === '') {
            $settings->set('design_provider', null);
            $settings->set('design_model', null);

            return null;
        }

        $allowed = \VelaBuild\Core\Services\DesignBuilderService::MODELS_FOR_BUILDING;

        if (!isset($allowed[$provider])) {
            return 'There is no provider called "' . $provider . '".';
        }

        // A model already in use that this release has not heard of stays
        // choosable — it may have been set in .env, or by a later Vela — but
        // anything else has to be one that has been shown to read a design and
        // call a tool. A model that cannot do the second dies at the build's
        // first step, minutes after the button is pressed.
        $inUse = (string) $settings->get('design_model', '');

        if ($model !== '' && $model !== $inUse && !in_array($model, $allowed[$provider], true)) {
            return 'That model is not one this can build with. Pick one of: ' . implode(', ', $allowed[$provider]) . '.';
        }

        $settings->set('design_provider', $provider);
        $settings->set('design_model', $model === '' ? null : $model);

        return null;
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
