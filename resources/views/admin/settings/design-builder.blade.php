@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head')

@if(session('message'))
    <div class="alert alert-success">{{ session('message') }}</div>
@endif
@foreach($errors->all() as $error)
    <div class="alert alert-danger">{{ $error }}</div>
@endforeach

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
                                        style="display:block;width:100%;cursor:zoom-in;">
                                    <img src="{{ route('vela.admin.settings.design-builder.design', $file['name']) }}"
                                         alt="{{ $file['name'] }}"
                                         style="width:100%;height:70px;object-fit:cover;border-radius:3px;">
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
                                        style="display:block;width:100%;height:70px;line-height:70px;cursor:zoom-in;">
                                    <i class="fas fa-file-alt fa-2x"></i>
                                </button>
                            @endif
                            <div class="small text-truncate mt-1" title="{{ $file['name'] }}">{{ $file['name'] }}</div>
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
                <p class="text-muted small">
                    This replaces your homepage, styling and site settings, and adds pages and articles to match
                    the design. It takes a few minutes. Screenshots of your site are sent to your AI provider so
                    it can compare them with the design.
                </p>

                @can('config_edit')
                <form action="{{ route('vela.admin.settings.design-builder.start') }}" method="POST" class="form-inline mb-3"
                      onsubmit="return confirm('This will change your site\'s content and styling. Continue?');">
                    @csrf
                    <label class="mr-2 small">Rounds of refinement</label>
                    <select name="max_loops" class="form-control form-control-sm mr-2">
                        <option value="1">1 — quickest</option>
                        <option value="3" selected>3 — recommended</option>
                        <option value="5">5 — most thorough</option>
                    </select>
                    <button type="submit" class="btn btn-primary" id="vela-build-btn" @if($running) disabled @endif>
                        <i class="fas fa-magic mr-1"></i> {{ $running ? 'Building…' : 'Build my site' }}
                    </button>
                </form>
                @endcan

                <div id="vela-build-progress" class="@if(!$status) d-none @endif">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge badge-secondary mr-2" id="vela-build-state">…</span>
                        <span class="small text-muted" id="vela-build-hint"></span>
                    </div>
                    <pre id="vela-build-log" class="bg-light border rounded p-2 small mb-0"
                         style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
                </div>
            </div>
        </div>

        @if(!empty($results))
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3">What it produced</h5>
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
            btn.innerHTML = '<i class="fas fa-magic mr-1"></i> ' + (live ? 'Building…' : 'Build my site');
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
