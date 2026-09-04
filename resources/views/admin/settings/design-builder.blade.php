@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head')

@if(session('message'))
    <div class="alert alert-success">{{ session('message') }}</div>
@endif
@foreach($errors->all() as $error)
    <div class="alert alert-danger">{{ $error }}</div>
@endforeach

{{-- The short version of docs/build-from-a-design.md, kept here because this
     is where somebody has the question. Closed by default so it is not in the
     way of anyone who has done this before. --}}
<details class="mb-3">
    <summary class="text-muted" style="cursor:pointer;">What this does, and what happens to my site</summary>
    <div class="card mt-2">
        <div class="card-body small">
            <p>
                Show us a picture of a website — a screenshot, a mockup from a designer, a photo of a
                sketch — and your site is built to look like it: each section is written to match what
                your picture shows, rather than fitted into a ready-made shape. Open the page in the
                editor afterwards and every headline, sentence, picture and link is there as a plain
                form to change.
            </p>
            <p class="mb-2"><strong>Your site is not touched while it works.</strong> The build makes a
                page of its own, and the theme and navigation it writes belong to that page alone —
                visitors go on seeing the site exactly as it is. When it is done you can look at it,
                and only then decide whether to put it in place. Whatever it replaces is kept, so you
                can go back.</p>
            <p class="mb-2">Say what the design is for before you build. <strong>A homepage</strong> is the
                whole site: the build writes the theme, the navigation and the front page, and keeping it
                dresses every page in the design. <strong>A page</strong> — one you have or a new one — is
                content only: the build writes the sections of your picture into the theme you already
                have, and leaves your theme, your navigation and your site name alone. That is the
                distinction that matters, because a theme belongs to the whole site: a mockup of one
                inside page cannot be allowed to redress the rest of it.</p>
            <p class="mb-2">
                A build takes a few minutes per round. It photographs what it has made, compares it
                with your picture, and corrects the differences. Three rounds suits most designs. You
                can close this page while it runs.
            </p>
            {{-- "Build again" used to be the whole of this advice, and it is the
                 wrong advice when the cause is the model: the same model gives
                 the same thin result and the second build costs what the first
                 did. Measured — one design, three rounds: 1,570 bytes of
                 styling on an old model against 45,421 on a current one. --}}
            <p class="mb-0 text-muted">
                It reads the words, prices and numbers off your picture accurately, and gives the site
                your colours and typeface. It does not trace the design pixel for pixel, and it cannot
                use photographs it does not have. Two runs of the same picture differ in detail — if
                one comes out thin, build again. If they all do, check the AI provider below.
            </p>
        </div>
    </div>
</details>

{{-- Furniture for the destination choice on this page. Written against the
     admin design system's tokens, each with a literal fallback, so it still
     reads correctly where a site has not republished the stylesheet. --}}
<style>
.vela-dest { width: 100%; border: 0; padding: 0; margin: 1.25rem 0; }
/* Prefixed with body.vela-admin: the design system styles bare `label`, which
   beats a lone class and made these read as headings rather than as the quiet
   labels the legend beside them is. */
.vela-dest__legend,
body.vela-admin .vela-build__label {
    display: block; width: auto; float: none; margin: 0 0 .375rem; padding: 0;
    font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
    color: var(--fg-subtle, #6B7388);
}
/* One label over one control, and two controls that belong together on one
   row — the same shape as the choice above, so the card reads as a sequence
   of decisions rather than a wrapped line of widgets. */
.vela-build__row { display: flex; flex-wrap: wrap; gap: 1rem 1.5rem; margin-bottom: 1rem; }
.vela-build__group { flex: 0 1 auto; min-width: 0; }
.vela-build__hint {
    margin: .375rem 0 0; font-size: .75rem; line-height: 1.4;
    color: var(--fg-muted, #4D5569);
}
.vela-dest__options {
    display: grid; gap: .5rem;
    grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
}
.vela-dest__option { position: relative; display: block; margin: 0; cursor: pointer; }
/* Hidden, not removed: the keyboard and the screen reader still get a plain
   radio group, and the cards are only how it is drawn. */
.vela-dest__option > input { position: absolute; width: 1px; height: 1px; opacity: 0; margin: 0; }
.vela-dest__card {
    display: block; height: 100%; padding: .7rem .8rem;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #DCE0E9);
    border-radius: var(--r-md, 10px);
    transition: border-color 140ms ease, background 140ms ease, box-shadow 140ms ease;
}
.vela-dest__option:hover .vela-dest__card { border-color: var(--border-strong, #BFC5D3); }
.vela-dest__option > input:checked + .vela-dest__card {
    background: var(--accent-muted, #E7F7F8);
    border-color: var(--accent, #22A2AB);
    box-shadow: inset 0 0 0 1px var(--accent, #22A2AB);
}
.vela-dest__option > input:focus-visible + .vela-dest__card {
    box-shadow: var(--shadow-ring, 0 0 0 3px rgba(64, 182, 189, .28));
}
.vela-dest__title {
    display: flex; align-items: center; gap: .4rem;
    font-size: .8125rem; font-weight: 600; line-height: 1.2;
    color: var(--fg, #151A28);
}
.vela-dest__title i { width: 1em; text-align: center; color: var(--fg-subtle, #6B7388); }
.vela-dest__option > input:checked + .vela-dest__card .vela-dest__title i { color: var(--fg-accent, #0F8B9E); }
.vela-dest__hint {
    display: block; margin-top: .25rem;
    font-size: .75rem; line-height: 1.4; color: var(--fg-muted, #4D5569);
}
.vela-dest__field { margin-top: .625rem; max-width: 340px; }
.vela-dest__field[hidden] { display: none; }
.vela-dest__note {
    margin: .5rem 0 0; font-size: .75rem; line-height: 1.5;
    color: var(--fg-muted, #4D5569);
}
</style>

<div class="row">
    <div class="col-lg-5">

        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">1. Show us the design</h5>
                <p class="text-muted small">
                    Upload a picture of what the site should look like — a screenshot, a mockup, a photo of a
                    sketch. Add a logo too if you have one.
                </p>

                @can('config_edit')
                {{-- A form, not a bare div: where Dropzone cannot run, this still
                     uploads by hand rather than leaving no way in at all. --}}
                <form action="{{ route('vela.admin.settings.design-builder.upload') }}" method="POST"
                      enctype="multipart/form-data" class="dropzone mb-3" id="design-dropzone">
                    @csrf
                    <div class="dz-message text-muted">
                        <i class="fas fa-image fa-2x mb-2 d-block"></i>
                        Drop a picture here, or click to choose one.
                    </div>
                    <div class="fallback">
                        <div class="form-group">
                            <input type="file" name="files[]" class="form-control-file" multiple accept="image/*" required>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-upload mr-1"></i> Upload</button>
                    </div>
                </form>
                @endcan

                @if(empty($files))
                    <p class="text-muted small mb-0"><em>Nothing uploaded yet.</em></p>
                @else
                <div class="d-flex flex-wrap" style="gap:10px;">
                    @foreach($files as $file)
                        <div class="border rounded p-2 text-center" style="width:120px;">
                            @if($file['is_image'])
                                <button type="button" class="btn p-0 border-0 bg-transparent vela-design-open"
                                        data-src="{{ route('vela.admin.settings.design-builder.design', $file['name']) }}"
                                        data-name="{{ $file['name'] }}"
                                        data-kind="image"
                                        title="Click to see it full size"
                                        {{-- height:auto because the admin's .btn is a fixed 40px tall: the
                                             thumbnail is 70, so it hung out of the bottom of its own button
                                             and the filename and the role label were printed over the
                                             picture. --}}
                                        style="display:block;width:100%;height:auto;padding:0;cursor:zoom-in;">
                                    <img src="{{ route('vela.admin.settings.design-builder.design', $file['name']) }}"
                                         alt="{{ $file['name'] }}"
                                         style="display:block;width:100%;height:70px;object-fit:cover;border-radius:3px;">
                                </button>
                            @else
                                {{-- A written brief is as much a part of the design as the
                                     picture is, so it opens the same way rather than being
                                     the one thing on the shelf that cannot be looked at. --}}
                                <button type="button" class="btn p-0 border-0 bg-transparent vela-design-open text-muted"
                                        data-src="{{ route('vela.admin.settings.design-builder.design', $file['name']) }}"
                                        data-name="{{ $file['name'] }}"
                                        data-kind="text"
                                        title="Click to read it"
                                        style="display:block;width:100%;height:70px;line-height:70px;padding:0;cursor:zoom-in;">
                                    <i class="fas fa-file-alt fa-2x"></i>
                                </button>
                            @endif
                            <div class="small text-truncate mt-1" title="{{ $file['name'] }}">{{ $file['name'] }}</div>
                            {{-- What this file will be taken for, so a design
                                 left over from last time is visible as one. --}}
                            <div class="small text-muted">
                                @switch($file['role'] ?? '')
                                    @case('design') the design @break
                                    @case('asset') a logo @break
                                    @case('brief') the brief @break
                                    @default &nbsp;
                                @endswitch
                            </div>
                            @can('config_edit')
                            <form action="{{ route('vela.admin.settings.design-builder.delete') }}" method="POST"
                                  onsubmit="return confirm('Remove {{ $file['name'] }}? It would have to be added again.');">
                                @csrf
                                <input type="hidden" name="name" value="{{ $file['name'] }}">
                                <button type="submit" class="btn btn-link btn-sm text-danger p-0">Remove</button>
                            </form>
                            @endcan
                        </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">2. Say what it is for</h5>
                <p class="text-muted small">
                    A sentence or two. What the site is, who it is for, anything the picture does not show.
                </p>
                @can('config_edit')
                <form action="{{ route('vela.admin.settings.design-builder.brief') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <textarea name="brief" rows="5" class="form-control"
                                  placeholder="We are a wood-fired restaurant on the Wellington harbour.">{{ $brief }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save mr-1"></i> Save</button>
                </form>
                @endcan
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="mb-3">Before you build</h5>
                <ul class="list-unstyled mb-0">
                    @foreach($readiness as $check)
                    <li class="mb-2">
                        <i class="fas {{ $check['ok'] ? 'fa-check-circle text-success' : 'fa-exclamation-circle text-warning' }} mr-1"></i>
                        <strong>{{ $check['label'] }}</strong>
                        <div class="small text-muted ml-4">{{ $check['detail'] }}</div>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>

    </div>

    <div class="col-lg-7">

        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">3. Build it</h5>
                {{-- This used to say the build replaced your homepage, styling
                     and settings. That stopped being true when builds moved
                     onto a page of their own, and it was the last thing anyone
                     read before pressing the button — directly contradicting
                     the explainer at the top of this page, and saying the
                     frightening half. --}}
                <p class="text-muted small">
                    The design is built onto a page of its own, so your homepage, theme and navigation stay exactly
                    as they are — you look at the result first and decide afterwards. It may add pages and articles
                    the design calls for. It takes a few minutes. Screenshots of your site are sent to your AI
                    provider so it can compare them with the design.
                </p>

                @can('config_edit')
                <form action="{{ route('vela.admin.settings.design-builder.start') }}" method="POST" class="vela-build mb-3"
                      onsubmit="return confirm('Build the design onto a page of its own? Your homepage and theme stay as they are.');">
                    @csrf
                    {{-- A design has always made a homepage, because the
                         first one a site is given is a homepage. The second
                         is usually not, and until now there was nowhere to
                         say so: the result could only go to the front page,
                         and a build for an inside page would have written a
                         second theme over the site's own on the way. The two
                         answers are genuinely different jobs, which is why
                         the choice is here rather than after the build.

                         Drawn as three cards rather than a stack of radios:
                         this is the most consequential choice on the page —
                         it decides what the build may touch, not only where
                         the result lands — and bare radios inside a
                         form-inline scattered across the row and read as an
                         afterthought. The native radio is still there,
                         visually hidden, so the keyboard and the screen
                         reader get a plain radio group. --}}
                    <fieldset class="vela-dest">
                        <legend class="vela-dest__legend">This design is for</legend>

                        <div class="vela-dest__options">
                            <label class="vela-dest__option">
                                <input type="radio" name="destination" value="homepage" data-vela-dest
                                       {{ ($destination['mode'] ?? 'homepage') === 'homepage' ? 'checked' : '' }}>
                                <span class="vela-dest__card">
                                    <span class="vela-dest__title"><i class="fas fa-home"></i> My homepage</span>
                                    <span class="vela-dest__hint">The whole site — theme, navigation and front page.</span>
                                </span>
                            </label>

                            <label class="vela-dest__option">
                                <input type="radio" name="destination" value="existing" data-vela-dest
                                       {{ ($destination['mode'] ?? '') === 'page' && ($destination['existing'] ?? false) ? 'checked' : '' }}>
                                <span class="vela-dest__card">
                                    <span class="vela-dest__title"><i class="fas fa-file-alt"></i> A page I have</span>
                                    <span class="vela-dest__hint">Its content only. Your theme stays as it is.</span>
                                </span>
                            </label>

                            <label class="vela-dest__option">
                                <input type="radio" name="destination" value="new" data-vela-dest
                                       {{ ($destination['mode'] ?? '') === 'page' && !($destination['existing'] ?? false) ? 'checked' : '' }}>
                                <span class="vela-dest__card">
                                    <span class="vela-dest__title"><i class="fas fa-plus"></i> A new page</span>
                                    <span class="vela-dest__hint">Added to your site. Your theme stays as it is.</span>
                                </span>
                            </label>
                        </div>

                        {{-- One field area under the cards rather than a field
                             hanging off each: the two never both apply, and
                             inside a label the select would have toggled the
                             radio it sits in every time it was used. --}}
                        <div class="vela-dest__field" data-vela-dest-field="existing" hidden>
                            <select name="destination_page" class="form-control form-control-sm">
                                {{-- The homepage is the card above, not one of
                                     these: offering it in both places lets
                                     somebody choose "a page I have" and get a
                                     whole-site build. --}}
                                @foreach($pages->where('slug', '!=', 'home') as $page)
                                    <option value="{{ $page->id }}"
                                            {{ ($destination['mode'] ?? '') === 'page' && $destination['slug'] === $page->slug ? 'selected' : '' }}>
                                        {{ $page->title }} (/{{ $page->slug }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="vela-dest__field" data-vela-dest-field="new" hidden>
                            <input type="text" name="destination_title" class="form-control form-control-sm"
                                   placeholder="What it is called, e.g. Pricing"
                                   value="{{ ($destination['mode'] ?? '') === 'page' && !($destination['existing'] ?? false) ? $destination['title'] : '' }}">
                        </div>

                        {{-- Written by the server as well as by the script:
                             the line explaining the choice should be right on
                             the first paint, not after JavaScript arrives. --}}
                        <p class="vela-dest__note" data-vela-dest-note>
                            @if(($destination['mode'] ?? 'homepage') === 'homepage')
                                This writes a theme, the navigation and a front page from your design. Nothing
                                reaches your site until you say so.
                            @else
                                A page build writes the sections of the design and leaves your theme, your
                                navigation and your site name exactly as they are — one design cannot redress a
                                site it is only one page of.
                            @endif
                        </p>
                    </fieldset>

                    {{-- The rest of the form is laid out the same way as the
                         choice above it: a small label over its control, in
                         one row where two controls belong together. It used to
                         be a form-inline, which put a label, a select, a
                         checkbox and two more selects in one wrapping line and
                         read as a pile rather than a set of decisions. --}}
                    <div class="vela-build__row">
                        <div class="vela-build__group" style="max-width:220px;">
                            <label class="vela-build__label" for="vela-build-loops">Rounds of refinement</label>
                            <select name="max_loops" id="vela-build-loops" class="form-control form-control-sm">
                                <option value="1">1 — quickest</option>
                                <option value="3" selected>3 — recommended</option>
                                <option value="5">5 — most thorough</option>
                            </select>
                        </div>

                        {{-- A build needs a model that can do two things: read a
                             picture and call a tool. Those are not the same
                             models a site chats with, and a newer model is not
                             automatically one of them — OpenAI's gpt-5.6 family
                             reads a design better than anything on this list
                             and cannot call a tool at all, so a build on it
                             dies at its first step. That is why this menu is
                             closed while the one in Settings → AI can be typed
                             into.

                             Kept in the same form as the button: choosing a
                             model and building is one action, and a Save of its
                             own would be the step everyone forgets. --}}
                        @if(!empty($buildWith['options']))
                        <div class="vela-build__group">
                            <label class="vela-build__label" for="vela-build-provider">Build with</label>
                            <div class="d-flex" style="gap:.5rem;">
                                <select name="design_provider" id="vela-build-provider" class="form-control form-control-sm" style="width:auto;">
                                    <option value="">The site's own AI</option>
                                    @foreach($buildWith['options'] as $provider => $models)
                                        <option value="{{ $provider }}" {{ $buildWith['provider'] === $provider ? 'selected' : '' }}>
                                            {{ ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Gemini'][$provider] ?? $provider }}
                                        </option>
                                    @endforeach
                                </select>
                                <select name="design_model" id="vela-build-model" class="form-control form-control-sm" style="width:auto;">
                                    <option value="">Recommended</option>
                                    @foreach($buildWith['options'] as $provider => $models)
                                        @foreach($models as $model)
                                            <option value="{{ $model }}" data-provider="{{ $provider }}"
                                                    {{ $buildWith['model'] === $model ? 'selected' : '' }}>{{ $model }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                            <p class="vela-build__hint">A build is worth a better model than everyday chat is.</p>
                        </div>
                        @endif
                    </div>

                    {{-- Off for anyone who already has their own photographs:
                         a generated picture in the right place is worth
                         waiting for, and one in the wrong style makes the page
                         read as a different site. --}}
                    <div class="form-check mb-3">
                        <input type="checkbox" name="generate_images" value="1" class="form-check-input"
                               id="vela-generate-images" checked>
                        <label class="form-check-label small" for="vela-generate-images">
                            Make pictures for it
                            <span class="text-muted">— leave off if you will add your own</span>
                        </label>
                    </div>

                    {{-- The idle wording is carried on the button rather than
                         repeated in the poller's script, which used to hold a
                         copy of it and quietly wrote an older one back over
                         this on the first poll. --}}
                    @php($buildLabel = 'Build it')
                    <button type="submit" class="btn btn-primary" id="vela-build-btn"
                            data-idle-label="{{ $buildLabel }}" @if($running) disabled @endif>
                        <i class="fas fa-magic mr-1"></i> {{ $running ? 'Building…' : $buildLabel }}
                    </button>
                </form>
                @endcan

                <div id="vela-build-progress" class="@if(!$status) d-none @endif">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge badge-secondary mr-2" id="vela-build-state">…</span>
                        <span class="small text-muted" id="vela-build-hint"></span>
                    </div>
                    <pre id="vela-build-log" class="bg-dark border rounded p-2 small mb-0"
                         style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
                </div>
            </div>
        </div>

        {{-- Keeping a design swaps the homepage, the theme and the navigation
             in one press. Two of those could always be found again by somebody
             who knew where to look — the old homepage under a timestamped
             slug, the menus under superseded_ ones — and the theme could not,
             so switching back in Settings → Appearance gave the old clothes on
             the new content and read as the theme having eaten the pages. This
             puts all three back together.

             Its own card, outside "What it produced", because the wish to go
             back outlives the screenshots: clearing the design folder empties
             that card and must not take the way back with it. --}}
        @if($canRestore)
        @can('config_edit')
        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">Changed your mind?</h5>
                <p class="text-muted small">
                    Keeping a design replaced a page — and where that page was your homepage, your theme and your
                    navigation with it. This puts back whatever it moved. The design is kept, unlisted, so you can
                    go forward again.
                </p>
                <form action="{{ route('vela.admin.settings.design-builder.restore') }}" method="POST"
                      onsubmit="return confirm('Put your site back as it was? The design is kept, unlisted.');">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo mr-1"></i> Put back the site I had
                    </button>
                </form>
            </div>
        </div>
        @endcan
        @endif

        @if(!empty($results))
        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">What it produced</h5>
                @if($preview)
                    <p class="text-muted small">
                        A build makes its own page and leaves your site alone, so you can look
                        before you decide.
                    </p>
                    <div class="d-flex align-items-center mb-4" style="gap:8px;">
                        <a href="{{ url($preview->slug) }}" target="_blank" class="btn btn-secondary btn-sm">
                            <i class="fas fa-external-link-alt mr-1"></i> Open it
                        </a>
                        @can('config_edit')
                        <form action="{{ route('vela.admin.settings.design-builder.use') }}" method="POST"
                              onsubmit="return confirm('Put this design on your site? Whatever is there now is kept, unlisted, so you can go back to it.');">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-check mr-1"></i> {{ $keepLabel }}
                            </button>
                        </form>
                        @endcan
                    </div>
                @endif

                @foreach($results as $result)
                    <div class="mb-4">
                        <h6 class="text-muted">Round {{ $result['loop'] }}</h6>
                        <a href="{{ route('vela.admin.settings.design-builder.capture', $result['screenshot']) }}" target="_blank">
                            <img src="{{ route('vela.admin.settings.design-builder.capture', $result['screenshot']) }}"
                                 alt="Round {{ $result['loop'] }}"
                                 class="border rounded" style="width:100%;max-height:340px;object-fit:cover;object-position:top;">
                        </a>
                        @if($result['report'])
                            <details class="mt-2">
                                <summary class="small text-muted" style="cursor:pointer;">What the AI thought still differed</summary>
                                <pre class="small bg-light border rounded p-2 mt-2" style="white-space:pre-wrap;">{{ $result['report'] }}</pre>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>
</div>

<div id="vela-lightbox" class="d-none"
     style="position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.9);
            display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;">
    <div class="d-flex justify-content-between align-items-center w-100 mb-2" style="max-width:1200px;">
        <span id="vela-lightbox-name" class="text-white small"></span>
        <button type="button" id="vela-lightbox-close" class="btn btn-sm btn-light">
            <i class="fas fa-times mr-1"></i> Close
        </button>
    </div>
    {{-- A tall design is read by scrolling, not by being shrunk to fit a screen it was never drawn for. --}}
    <div style="max-width:1200px;width:100%;flex:1;overflow:auto;background:#fff;border-radius:4px;">
        <img id="vela-lightbox-img" src="" alt="" style="width:100%;display:block;">
        <pre id="vela-lightbox-text" class="d-none mb-0"
             style="padding:24px;white-space:pre-wrap;word-break:break-word;font-size:14px;"></pre>
    </div>
</div>

<script>
(function () {
    var box = document.getElementById('vela-lightbox');
    var img = document.getElementById('vela-lightbox-img');
    var text = document.getElementById('vela-lightbox-text');
    var name = document.getElementById('vela-lightbox-name');
    var opener = null;

    function show(label) {
        opener = document.activeElement;
        name.textContent = label;
        box.classList.remove('d-none');
        // The page behind must not scroll while this is over it, or a flick of
        // the wheel past the end of the design moves the wrong thing.
        document.body.style.overflow = 'hidden';
        document.getElementById('vela-lightbox-close').focus();
    }

    function open(src, label) {
        img.classList.remove('d-none');
        text.classList.add('d-none');
        img.src = src;
        img.alt = label;
        show(label);
    }

    // Read rather than downloaded: a brief is a few lines, and handing it to
    // the browser as a file would take the operator out of the page they are
    // working in.
    function openText(src, label) {
        img.classList.add('d-none');
        text.classList.remove('d-none');
        text.textContent = 'Loading…';
        show(label);

        fetch(src, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (body) {
                text.textContent = body.trim() === '' ? '(this file is empty)' : body;
            })
            .catch(function () {
                text.textContent = 'That file could not be read.';
            });
    }

    function close() {
        box.classList.add('d-none');
        img.src = '';
        text.textContent = '';
        document.body.style.overflow = '';
        if (opener) { opener.focus(); opener = null; }
    }

    document.querySelectorAll('.vela-design-open').forEach(function (button) {
        button.addEventListener('click', function () {
            var label = button.dataset.name || '';

            if (button.dataset.kind === 'text') {
                openText(button.dataset.src, label);
            } else {
                open(button.dataset.src, label);
            }
        });
    });

    document.getElementById('vela-lightbox-close').addEventListener('click', close);

    // Anywhere off the picture closes it, which is what the dark ground invites.
    box.addEventListener('click', function (event) {
        if (event.target === box) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !box.classList.contains('d-none')) { close(); }
    });
})();
</script>

<script>
(function () {
    var box   = document.getElementById('vela-build-progress');
    var log   = document.getElementById('vela-build-log');
    var state = document.getElementById('vela-build-state');
    var hint  = document.getElementById('vela-build-hint');
    var btn   = document.getElementById('vela-build-btn');
    if (!box) return;

    var url = @json(route('vela.admin.settings.design-builder.status'));
    var running = @json((bool) $running);
    var idleLabel = (btn && btn.dataset.idleLabel) || 'Build';
    var timer = null;

    function render(payload) {
        var s = payload.status;
        if (!s) return;

        box.classList.remove('d-none');
        log.textContent = (s.lines || []).map(function (l) { return l.text; }).join('\n');
        log.scrollTop = log.scrollHeight;

        var live = payload.running;
        state.textContent = live ? 'Building' : (s.state === 'done' ? 'Finished' : 'Stopped');
        state.className = 'badge mr-2 ' + (live ? 'badge-primary' : (s.state === 'done' ? 'badge-success' : 'badge-danger'));

        hint.textContent = live
            ? 'This takes a few minutes. You can leave this page — the build keeps going.'
            : (s.error || '');

        if (btn) {
            btn.disabled = live;
            btn.innerHTML = '<i class="fas fa-magic mr-1"></i> ' + (live ? 'Building…' : idleLabel);
        }

        // The finished page carries the captures and reports, which are only
        // rendered server-side — so once it is over, go and get them.
        if (running && !live) {
            clearInterval(timer);
            window.location.reload();
        }
        running = live;
    }

    function poll() {
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { /* a missed poll is not worth reporting; the next one will do */ });
    }

    poll();
    timer = setInterval(poll, 3000);
})();
</script>

<script>
(function () {
    var provider = document.getElementById('vela-build-provider');
    var model = document.getElementById('vela-build-model');
    if (!provider || !model) { return; }

    // A model id belongs to one provider, so the menu only ever offers the
    // ones that go with the provider beside it — and switching provider drops
    // a model that no longer applies rather than sending an OpenAI id to
    // Anthropic.
    function sync(keepChoice) {
        var chosen = keepChoice ? model.value : '';
        var stillThere = false;

        Array.prototype.forEach.call(model.options, function (option) {
            var mine = !option.value || option.dataset.provider === provider.value;
            option.hidden = !mine;
            option.disabled = !mine;
            if (mine && option.value && option.value === chosen) { stillThere = true; }
        });

        model.value = stillThere ? chosen : '';
        // Nothing to choose between when the site's own AI is in charge.
        model.disabled = provider.value === '';
    }

    provider.addEventListener('change', function () { sync(false); });
    sync(true);
})();
</script>

<script>
(function () {
    var choices = document.querySelectorAll('[data-vela-dest]');
    var note = document.querySelector('[data-vela-dest-note]');
    if (!choices.length) { return; }

    var forHomepage = 'This writes a theme, the navigation and a front page from your design. Nothing reaches your '
        + 'site until you say so.';
    var forAPage = 'A page build writes the sections of the design and leaves your theme, your navigation and your '
        + 'site name exactly as they are — one design cannot redress a site it is only one page of.';

    function sync() {
        var chosen = document.querySelector('[data-vela-dest]:checked');
        var mode = chosen ? chosen.value : 'homepage';

        document.querySelectorAll('[data-vela-dest-field]').forEach(function (field) {
            var mine = field.dataset.velaDestField === mode;
            field.hidden = !mine;

            // Hidden is not enough: a hidden select still posts its value, and
            // a page id arriving with "my homepage" chosen is a build sent
            // somewhere nobody asked for. The wrapper is a div, so it is the
            // control inside it that has to be disabled.
            field.querySelectorAll('input, select, textarea').forEach(function (control) {
                control.disabled = !mine;
            });
        });

        if (note) { note.textContent = mode === 'homepage' ? forHomepage : forAPage; }
    }

    choices.forEach(function (choice) { choice.addEventListener('change', sync); });
    sync();
})();
</script>
@endsection

@can('config_edit')
{{-- Pushed rather than written into the page: the admin layout loads Dropzone
     itself further down, and options set before it exists are simply lost. --}}
@push('scripts')
<script>
    // The upload route reads files[], so the pictures have to arrive as an
    // array — which is what uploadMultiple gives us with this paramName.
    Dropzone.options.designDropzone = {
        paramName: 'files',
        uploadMultiple: true,
        parallelUploads: 10,
        maxFilesize: 20,
        acceptedFiles: 'image/*',
        addRemoveLinks: false,
        headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
        // The list of designs, the readiness checks and the Build button are
        // all rendered by the server from what is on disk, so the page has to
        // come back to tell the truth about what was just added.
        queuecomplete: function () {
            if (this.getRejectedFiles().length === 0) {
                window.location.reload();
            }
        },
        errormultiple: function (files, response) {
            var message = typeof response === 'string'
                ? response
                : (response && response.message) || 'That file could not be uploaded.';

            files.forEach(function (file) {
                file.previewElement.classList.add('dz-error');
                file.previewElement.querySelectorAll('[data-dz-errormessage]').forEach(function (node) {
                    node.textContent = message;
                });
            });
        }
    };
</script>
@endpush
@endcan
