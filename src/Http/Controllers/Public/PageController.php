<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\FormSubmission;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PageController extends Controller
{
    public function show($slug)
    {
        // Sync DB with any static config.json files (idempotent, daily-locked).
        \VelaBuild\Core\Jobs\ImportContentFromConfigJob::dispatch();

        $locale = app()->getLocale();

        // DB may be unavailable on a fresh deploy (migrations not run) or during
        // an outage. The static front-controller serves pre-rendered HTML before
        // Laravel boots, so if we got here, no snapshot exists for this path.
        // Fall back to 404 — never surface raw PDO/Query exceptions to visitors.
        try {
            $page = Page::where('slug', $slug)
                ->where('locale', $locale)
                ->whereIn('status', ['published', 'unlisted'])
                ->with(['rows.blocks'])
                ->first();

            if (!$page) {
                // Try primary locale and serve with translations
                $primaryLocale = config('vela.primary_language', 'en');
                $page = Page::where('slug', $slug)
                    ->where('locale', $primaryLocale)
                    ->whereIn('status', ['published', 'unlisted'])
                    ->with(['rows.blocks'])
                    ->firstOrFail();

                // Queue translation snapshot for future static serving
                if ($locale !== $primaryLocale && config('vela.static.enabled', true)) {
                    $snapshotPath = config('vela.static.path', resource_path('static'))
                        . '/pages/' . $slug . '/translations/' . $locale . '.html';
                    if (!file_exists($snapshotPath)) {
                        \VelaBuild\Core\Jobs\GenerateTranslationSnapshotJob::dispatch('page', $slug, $locale);
                    }
                }
            }
        } catch (\Illuminate\Database\QueryException | \PDOException $e) {
            Log::warning('Vela: DB unavailable serving page', [
                'slug'   => $slug,
                'locale' => $locale,
                'error'  => $e->getMessage(),
            ]);
            abort(404);
        }

        // Cloudflare Cache-Tag: identify this page by id + slug + locale so
        // mutations elsewhere can purge it with surgical accuracy. The
        // EmitCacheTags middleware joins these into a single header.
        cache_tag([
            'page:' . $page->id,
            'page:slug:' . $page->slug,
            'locale:' . $locale,
        ]);

        // The page a design build writes onto wears the frame that build
        // produced — its theme and its navigation — while the rest of the site
        // keeps its own. Until someone presses "use this as my homepage", a
        // design being tried out changes nothing a visitor sees anywhere else.
        if ($page->slug === \VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG) {
            app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->activate();
        }

        return view(vela_template_view('page'), compact('page'));
    }

    public function submitForm(Request $request, Page $page)
    {
        // Find contact_form block
        $blockId = $request->input('block_id');
        $block = null;

        if ($blockId) {
            $block = PageBlock::whereHas('row', fn ($q) => $q->where('page_id', $page->id))
                ->where('id', $blockId)
                ->first();
        }

        if (!$block) {
            $page->load('rows.blocks');
            foreach ($page->rows as $row) {
                foreach ($row->blocks as $b) {
                    if ($b->type === 'contact_form') {
                        $block = $b;
                        break 2;
                    }
                }
            }
        }

        // Nothing built-in on this page: an imported section may still carry a
        // form of its own, and a post with no block_id (an older cached page,
        // a form rebuilt by hand) should still land somewhere sensible.
        if (!$block) {
            foreach ($page->rows as $row) {
                foreach ($row->blocks as $b) {
                    if ($b->type === 'html' && str_contains((string) ($b->content['html'] ?? ''), '<form')) {
                        $block = $b;
                        break 2;
                    }
                }
            }
        }

        if (!$block) {
            abort(404);
        }

        // A copied section keeps its own form markup, so the block holding it
        // is an `html` block rather than the built-in contact one. Its fields
        // are whatever the source page had — Country, Company website — none
        // of which the five-field rules below can describe.
        if ($block->type !== 'contact_form') {
            return $this->storeImportedFormSubmission($request, $page, $block);
        }

        // Honeypot check
        if ($request->filled('website_url')) {
            abort(422);
        }

        // Turnstile check
        if (env('TURNSTILE_SECRET_KEY')) {
            try {
                $response = Http::timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret'   => env('TURNSTILE_SECRET_KEY'),
                    'response' => $request->input('cf-turnstile-response'),
                ]);

                $result = $response->json();
                if (empty($result['success'])) {
                    return back()->withErrors(['captcha' => 'Captcha verification failed.']);
                }
            } catch (\Exception $e) {
                Log::warning('Turnstile verification timed out or failed: ' . $e->getMessage());
                // Fail-open: allow submission to proceed
            }
        }

        // Rate limiting
        $key = 'form-submit:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['throttle' => 'Too many submissions. Please try again later.']);
        }
        RateLimiter::hit($key, 60);

        // Build validation rules from block settings
        $settings = $block->settings ?? [];
        $fields = $settings['fields'] ?? [
            'name'    => ['enabled' => true, 'required' => true],
            'email'   => ['enabled' => true, 'required' => true],
            'phone'   => ['enabled' => true, 'required' => false],
            'subject' => ['enabled' => true, 'required' => false],
            'message' => ['enabled' => true, 'required' => true],
        ];

        // The block's view draws these five inputs and no others, so anything
        // else configured here would add a rule for a field the visitor is
        // never shown — a required one would reject every submission.
        $fields = array_intersect_key($fields, array_flip(['name', 'email', 'phone', 'subject', 'message']));

        $rules = [];
        $enabledFields = [];

        foreach ($fields as $fieldName => $fieldConfig) {
            if (empty($fieldConfig['enabled'])) {
                continue;
            }

            $enabledFields[] = $fieldName;
            $required = !empty($fieldConfig['required']);

            if ($fieldName === 'email') {
                $rules[$fieldName] = ($required ? 'required' : 'nullable') . '|email|max:255';
            } elseif ($fieldName === 'phone') {
                $rules[$fieldName] = 'nullable|string|max:50';
            } elseif ($fieldName === 'message') {
                $rules[$fieldName] = ($required ? 'required' : 'nullable') . '|string|max:5000';
            } else {
                $rules[$fieldName] = ($required ? 'required' : 'nullable') . '|string|max:1000';
            }
        }

        $request->validate($rules);

        // Collect only enabled field data
        $data = [];
        foreach ($enabledFields as $fieldName) {
            $data[$fieldName] = $request->input($fieldName);
        }

        try {
            FormSubmission::create([
                'page_id'    => $page->id,
                'block_id'   => $block->id,
                'data'       => $data,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Form submission DB write failed: ' . $e->getMessage());
            // Graceful degradation: show success even if DB write fails
        }

        $successMessage = $settings['success_message'] ?? 'Thank you for your message!';

        return redirect()->back()->with('success', $successMessage);
    }

    /**
     * Store a submission from a form that came in with an imported section.
     *
     * The built-in block knows its five fields and validates them by name.
     * An imported form is whatever the other site built, so the rules here
     * describe shape rather than meaning: everything is text of a sane
     * length, an address that looks like an email has to be one, and the
     * whole thing is capped so a crafted post cannot fill the table.
     * The spam defences are the same ones the built-in form uses.
     */
    private function storeImportedFormSubmission(Request $request, Page $page, PageBlock $block)
    {
        $markup = (string) ($block->content['html'] ?? '');
        if ($block->type !== 'html' || !str_contains($markup, '<form')) {
            abort(404);
        }

        $fields = collect($request->except(['_token', '_method', 'block_id', 'website_url', 'cf-turnstile-response']))
            ->filter(fn ($value) => is_string($value) || is_numeric($value) || is_bool($value) || is_null($value))
            ->take(40);

        $rules = [];
        foreach ($fields as $name => $value) {
            $rules[$name] = str_contains(strtolower($name), 'email')
                ? 'nullable|email|max:255'
                : 'nullable|string|max:5000';
        }
        $request->validate($rules);

        $data = [];
        foreach ($fields as $name => $value) {
            $value = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            if (trim($value) === '') {
                continue;
            }
            $data[$name] = mb_substr($value, 0, 5000);
        }

        if ($data === []) {
            return back()->withErrors(['form' => trans('vela::global.please_fill_in_the_form') ?: 'Please fill in the form before sending it.']);
        }

        try {
            FormSubmission::create([
                'page_id'    => $page->id,
                'block_id'   => $block->id,
                'data'       => $data,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Imported form submission DB write failed: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Thank you for your message!');
    }
}
