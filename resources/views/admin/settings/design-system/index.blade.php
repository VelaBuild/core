@extends('vela::layouts.admin')

@section('breadcrumb', 'Design System')

@section('content')
@include('vela::admin.settings._page-head', ['subtitle' => __('Files, palette, fonts — shared source of truth for the site.')])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<style>
    .ds-card { margin-bottom: 20px; }
    .ds-card .card-header { font-weight: 600; display:flex; justify-content:space-between; align-items:center; }
    .ds-hint { font-size:12px; color:#64748b; margin-top:4px; }
    .ds-file-row { display:grid; grid-template-columns: 1fr 80px 100px 140px 40px; gap:12px; align-items:center; padding:10px 12px; border-bottom:1px solid #f1f5f9; }
    .ds-file-row:last-child { border-bottom: 0; }
    .ds-file-row code { font-size:12px; color:#334155; word-break:break-all; }
    .ds-file-row .ds-file-size { color:#64748b; font-size:12px; text-align:right; font-variant-numeric: tabular-nums; }
    .ds-file-row .ds-file-type { font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; }
    .ds-palette-row { display:grid; grid-template-columns: 40px 1.2fr 1fr 1fr 2fr 40px; gap:10px; align-items:center; margin-bottom:8px; }
    .ds-palette-row input[type="color"] { width:40px; height:36px; padding:2px; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; }
    .ds-font-row { display:grid; grid-template-columns: 1fr 2fr 3fr 1fr 1fr 40px; gap:10px; align-items:center; margin-bottom:8px; }
    .ds-section-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px; }
    .ds-section-head h4 { margin:0; font-size:14px; text-transform:uppercase; letter-spacing:0.06em; color:#475569; }
    .ds-import-block { padding:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:12px; }
    .ds-import-block h5 { font-size:14px; font-weight:600; margin:0 0 4px; }
</style>

{{-- ─────────────── Files ─────────────── --}}
<div class="card ds-card">
    <div class="card-header">
        <span><i class="fas fa-folder-open mr-2"></i> Files</span>
        <small class="text-muted">{{ count($files) }} file(s) · {{ number_format($totalBytes / 1024, 1) }} KB</small>
    </div>
    <div class="card-body p-0">
        @if(empty($files))
            <div class="p-4 text-center text-muted">
                <p class="mb-1"><strong>No files yet.</strong></p>
                <p class="small mb-0">Upload individual files below or import a ZIP.</p>
            </div>
        @else
            <div>
                @foreach($files as $f)
                    <div class="ds-file-row">
                        <code>{{ $f['name'] }}</code>
                        <span class="ds-file-type">{{ strtoupper($f['ext']) }}</span>
                        <span class="ds-file-size">{{ number_format($f['bytes'] / 1024, 1) }} KB</span>
                        <span class="ds-file-size">{{ \Carbon\Carbon::parse($f['updated_at'])->diffForHumans() }}</span>
                        <div style="display:flex;gap:4px;">
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" title="Preview"
                               href="{{ route('vela.admin.settings.design-system.download', $f['name']) }}">
                                <i class="fas fa-eye"></i>
                            </a>
                            @can('config_edit')
                                <form method="POST" action="{{ route('vela.admin.settings.design-system.delete-file') }}"
                                      onsubmit="return confirm('Delete {{ $f['name'] }}?');" style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="name" value="{{ $f['name'] }}">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    @can('config_edit')
        <div class="card-footer">
            <form method="POST" action="{{ route('vela.admin.settings.design-system.upload-file') }}"
                  enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center;">
                @csrf
                <input type="file" name="file" class="form-control-file" required>
                <input type="text" name="rename" class="form-control" placeholder="Save as (optional)" style="max-width:240px;">
                <button class="btn btn-outline-primary"><i class="fas fa-upload"></i> Upload</button>
            </form>
            <p class="ds-hint mt-2 mb-0">Allowed: md / html / txt / json / css / png / jpg / svg / webp / woff2 / pdf (25 MB max).</p>
        </div>
    @endcan
</div>

{{-- ─────────────── Import ─────────────── --}}
@can('config_edit')
<div class="card ds-card">
    <div class="card-header">
        <span><i class="fas fa-file-archive mr-2"></i> Import ZIP</span>
    </div>
    <div class="card-body">
        <div class="ds-import-block">
            <h5>Upload a ZIP file</h5>
            <form method="POST" action="{{ route('vela.admin.settings.design-system.import-zip') }}"
                  enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center;">
                @csrf
                <input type="file" name="zip" accept=".zip" class="form-control-file" required>
                <button class="btn btn-primary"><i class="fas fa-upload"></i> Import</button>
            </form>
            <p class="ds-hint mt-2 mb-0">Every file inside the ZIP is validated against the same rules as a manual upload. Sub-directories are flattened.</p>
        </div>
        <div class="ds-import-block">
            <h5>Import from a URL</h5>
            <form method="POST" action="{{ route('vela.admin.settings.design-system.import-url') }}"
                  style="display:flex; gap:10px; align-items:center;">
                @csrf
                <input type="url" name="zip_url" class="form-control" placeholder="https://example.com/design.zip" required>
                <button class="btn btn-primary"><i class="fas fa-download"></i> Fetch &amp; import</button>
            </form>
            <p class="ds-hint mt-2 mb-0">Useful for Claude-generated design packages. 100 MB max download size.</p>
        </div>
    </div>
</div>
@endcan

{{-- ─────────────── Palette ─────────────── --}}
<div class="card ds-card">
    <div class="card-header">
        <span><i class="fas fa-palette mr-2"></i> Colour palette</span>
        <small class="text-muted">Shown as presets in admin colour pickers</small>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('vela.admin.settings.design-system.save-palette') }}">
            @csrf
            <div class="form-group" style="max-width:300px;">
                <label for="palette_name">Palette name</label>
                <input type="text" name="palette_name" id="palette_name" class="form-control" value="{{ $palette['name'] }}">
            </div>
            <div class="ds-section-head">
                <h4>Entries</h4>
            </div>
            <div id="ds-palette-rows">
                <div class="ds-palette-row" style="color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">
                    <span>Colour</span>
                    <span>Name</span>
                    <span>Slug</span>
                    <span>Hex</span>
                    <span>Description</span>
                    <span></span>
                </div>
                @foreach($palette['entries'] as $i => $entry)
                    <div class="ds-palette-row">
                        <input type="color" value="{{ $entry['hex'] }}"
                               oninput="this.closest('.ds-palette-row').querySelector('input[name$=\'[hex]\']').value=this.value">
                        <input type="text" name="entries[{{ $i }}][name]" class="form-control form-control-sm" value="{{ $entry['name'] }}" placeholder="Brand">
                        <input type="text" name="entries[{{ $i }}][slug]" class="form-control form-control-sm" value="{{ $entry['slug'] }}" placeholder="brand">
                        <input type="text" name="entries[{{ $i }}][hex]"  class="form-control form-control-sm" value="{{ $entry['hex'] }}"  placeholder="#4f46e5">
                        <input type="text" name="entries[{{ $i }}][description]" class="form-control form-control-sm" value="{{ $entry['description'] }}" placeholder="Primary CTA colour">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.ds-palette-row').remove()"><i class="fas fa-trash"></i></button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-secondary mt-2" id="ds-palette-add"><i class="fas fa-plus"></i> Add colour</button>
            <hr>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save palette</button>
        </form>
    </div>
</div>

{{-- ─────────────── Fonts ─────────────── --}}
<div class="card ds-card">
    <div class="card-header">
        <span><i class="fas fa-font mr-2"></i> Fonts</span>
        <small class="text-muted">Surfaced as options in admin font selectors</small>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('vela.admin.settings.design-system.save-fonts') }}">
            @csrf
            <div class="ds-section-head">
                <h4>Entries</h4>
            </div>
            <div id="ds-font-rows">
                <div class="ds-font-row" style="color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">
                    <span>Role</span>
                    <span>Family</span>
                    <span>Source URL</span>
                    <span>Weights</span>
                    <span>Fallback</span>
                    <span></span>
                </div>
                @foreach($fonts['entries'] as $i => $entry)
                    <div class="ds-font-row">
                        <input type="text" name="entries[{{ $i }}][role]"       class="form-control form-control-sm" value="{{ $entry['role'] }}" placeholder="display / body / mono">
                        <input type="text" name="entries[{{ $i }}][family]"     class="form-control form-control-sm" value="{{ $entry['family'] }}" placeholder="Inter">
                        <input type="url"  name="entries[{{ $i }}][source_url]" class="form-control form-control-sm" value="{{ $entry['source_url'] }}" placeholder="https://fonts.bunny.net/css2?family=...">
                        <input type="text" name="entries[{{ $i }}][weights]"    class="form-control form-control-sm" value="{{ implode(',', $entry['weights']) }}" placeholder="300,400,500,600,700">
                        <input type="text" name="entries[{{ $i }}][fallback]"   class="form-control form-control-sm" value="{{ $entry['fallback'] }}" placeholder="sans-serif">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.ds-font-row').remove()"><i class="fas fa-trash"></i></button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-secondary mt-2" id="ds-font-add"><i class="fas fa-plus"></i> Add font</button>
            <p class="ds-hint">Fonts must load from <strong>Bunny Fonts</strong> (GDPR-compliant Google Fonts mirror) or a self-hosted file you've uploaded above. Never <code>fonts.googleapis.com</code>.</p>
            <hr>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save fonts</button>
        </form>
    </div>
</div>

<script>
(function () {
    const paletteWrap = document.getElementById('ds-palette-rows');
    const paletteAdd  = document.getElementById('ds-palette-add');
    paletteAdd?.addEventListener('click', function () {
        const i = paletteWrap.querySelectorAll('.ds-palette-row').length - 1; // first row is header
        paletteWrap.insertAdjacentHTML('beforeend', `
            <div class="ds-palette-row">
                <input type="color" value="#4f46e5" oninput="this.closest('.ds-palette-row').querySelector('input[name$=\\'[hex]\\']').value=this.value">
                <input type="text" name="entries[${i}][name]"        class="form-control form-control-sm" placeholder="Brand">
                <input type="text" name="entries[${i}][slug]"        class="form-control form-control-sm" placeholder="brand">
                <input type="text" name="entries[${i}][hex]"         class="form-control form-control-sm" value="#4f46e5" placeholder="#4f46e5">
                <input type="text" name="entries[${i}][description]" class="form-control form-control-sm" placeholder="">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.ds-palette-row').remove()"><i class="fas fa-trash"></i></button>
            </div>`);
    });

    const fontWrap = document.getElementById('ds-font-rows');
    const fontAdd  = document.getElementById('ds-font-add');
    fontAdd?.addEventListener('click', function () {
        const i = fontWrap.querySelectorAll('.ds-font-row').length - 1;
        fontWrap.insertAdjacentHTML('beforeend', `
            <div class="ds-font-row">
                <input type="text" name="entries[${i}][role]"       class="form-control form-control-sm" placeholder="body">
                <input type="text" name="entries[${i}][family]"     class="form-control form-control-sm" placeholder="Inter">
                <input type="url"  name="entries[${i}][source_url]" class="form-control form-control-sm" placeholder="https://fonts.bunny.net/css2?family=...">
                <input type="text" name="entries[${i}][weights]"    class="form-control form-control-sm" placeholder="400,500,600,700">
                <input type="text" name="entries[${i}][fallback]"   class="form-control form-control-sm" value="sans-serif" placeholder="sans-serif">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.ds-font-row').remove()"><i class="fas fa-trash"></i></button>
            </div>`);
    });
})();
</script>
@endsection
