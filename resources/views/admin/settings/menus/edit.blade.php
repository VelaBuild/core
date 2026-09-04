@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head', ['subtitle' => __('Edit menu items for the “:slot” slot.', ['slot' => $menu->label])])
<div class="card">
    <div class="card-body">
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="row">
            <div class="col-md-7">
                <h5 class="mb-3">
                    <i class="fas fa-list mr-2 text-primary"></i>
                    {{ __('Items in this menu') }}
                </h5>

                <form id="menu-form" action="{{ route('vela.admin.settings.menus.update', $slot) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label for="label">{{ __('Display label (admin only)') }}</label>
                        <input type="text" name="label" id="label" class="form-control" value="{{ old('label', $menu->label) }}" maxlength="120">
                    </div>

                    @if($slot === 'primary')
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" name="auto_add_pages" id="auto_add_pages" value="1" class="custom-control-input" {{ $menu->auto_add_pages ? 'checked' : '' }}>
                                <label for="auto_add_pages" class="custom-control-label">
                                    {{ __('Automatically add newly published pages to this menu') }}
                                </label>
                            </div>
                            <small class="form-text text-muted">{{ __('On by default for the primary menu. New top-level pages will be appended at the end. You can still reorder or remove them.') }}</small>
                        </div>
                    @endif

                    <hr>

                    <div id="menu-items" class="vela-menu-items">
                        @foreach($menu->items as $i => $item)
                            @include('vela::admin.settings.menus._item-row', ['item' => $item, 'index' => $i])
                        @endforeach
                    </div>

                    <div class="text-muted small mt-2 mb-3" id="empty-hint" style="{{ $menu->items->count() ? 'display:none' : '' }}">
                        {{ __('No items yet. Add your first item from the right, or use the AI helper to set this menu up.') }}
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> {{ __('Save menu') }}
                    </button>
                    <a href="{{ route('vela.admin.settings.menus.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                </form>
            </div>

            <div class="col-md-5">
                <h5 class="mb-3">
                    <i class="fas fa-plus-circle mr-2 text-success"></i>
                    {{ __('Add to menu') }}
                </h5>

                <ul class="nav nav-tabs nav-tabs-sm" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-pages">{{ __('Pages') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-posts">{{ __('Articles') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-cats">{{ __('Categories') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-builtin">{{ __('Built-in') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-custom">{{ __('Custom') }}</a></li>
                </ul>

                <div class="tab-content border border-top-0 p-3">
                    <div class="tab-pane fade show active" id="tab-pages">
                        <input type="search" class="form-control form-control-sm mb-2" placeholder="{{ __('Filter pages…') }}" data-filter="#picker-pages">
                        <ul class="list-unstyled mb-0" id="picker-pages" style="max-height:300px; overflow:auto;">
                            {{-- The address, not just the name. A title does not
                                 identify a page: keeping a design parks the
                                 homepage it replaced under a timestamped slug
                                 and leaves its title alone, so a site that has
                                 tried a few designs offers several pages called
                                 "Home" and only the address tells them apart.
                                 Somebody added one and got /home-2026-09-04-082328
                                 in their live header.

                                 To link the front page, use Built-in → Home:
                                 that follows the homepage wherever it moves. --}}
                            @foreach($pages as $p)
                                <li class="d-flex align-items-center py-1 border-bottom">
                                    <span class="flex-grow-1 small">
                                        {{ $p->title }}
                                        <span class="text-muted">/{{ $p->slug }}</span>
                                        @if($p->status !== 'published')
                                            <span class="badge badge-light border ml-1">{{ $p->status }}</span>
                                        @endif
                                    </span>
                                    <button type="button" class="btn btn-link btn-sm py-0" data-add="page" data-id="{{ $p->id }}" data-label="{{ $p->title }}">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="tab-pane fade" id="tab-posts">
                        <input type="search" class="form-control form-control-sm mb-2" placeholder="{{ __('Filter articles…') }}" data-filter="#picker-posts">
                        <ul class="list-unstyled mb-0" id="picker-posts" style="max-height:300px; overflow:auto;">
                            @foreach($posts as $p)
                                <li class="d-flex align-items-center py-1 border-bottom">
                                    <span class="flex-grow-1 small">{{ $p->title }}</span>
                                    <button type="button" class="btn btn-link btn-sm py-0" data-add="content" data-id="{{ $p->id }}" data-label="{{ $p->title }}">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="tab-pane fade" id="tab-cats">
                        <input type="search" class="form-control form-control-sm mb-2" placeholder="{{ __('Filter categories…') }}" data-filter="#picker-cats">
                        <ul class="list-unstyled mb-0" id="picker-cats" style="max-height:300px; overflow:auto;">
                            @foreach($categories as $c)
                                <li class="d-flex align-items-center py-1 border-bottom">
                                    <span class="flex-grow-1 small">{{ $c->name }}</span>
                                    <button type="button" class="btn btn-link btn-sm py-0" data-add="category" data-id="{{ $c->id }}" data-label="{{ $c->name }}">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="tab-pane fade" id="tab-builtin">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex align-items-center py-1 border-bottom">
                                <span class="flex-grow-1 small">{{ __('Home') }}</span>
                                <button type="button" class="btn btn-link btn-sm py-0" data-add="home" data-label="{{ __('Home') }}"><i class="fas fa-plus"></i></button>
                            </li>
                            <li class="d-flex align-items-center py-1 border-bottom">
                                <span class="flex-grow-1 small">{{ __('All articles') }}</span>
                                <button type="button" class="btn btn-link btn-sm py-0" data-add="posts_index" data-label="{{ __('All articles') }}"><i class="fas fa-plus"></i></button>
                            </li>
                            <li class="d-flex align-items-center py-1 border-bottom">
                                <span class="flex-grow-1 small">{{ __('Topics / categories') }}</span>
                                <button type="button" class="btn btn-link btn-sm py-0" data-add="categories_index" data-label="{{ __('Topics') }}"><i class="fas fa-plus"></i></button>
                            </li>
                        </ul>
                    </div>
                    <div class="tab-pane fade" id="tab-custom">
                        <div class="form-group mb-2">
                            <label class="small mb-1">{{ __('Label') }}</label>
                            <input type="text" id="custom-label" class="form-control form-control-sm" placeholder="{{ __('Contact us') }}">
                        </div>
                        <div class="form-group mb-2">
                            <label class="small mb-1">{{ __('URL') }}</label>
                            <input type="text" id="custom-url" class="form-control form-control-sm" placeholder="https://example.com or /contact">
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" id="add-custom">
                            <i class="fas fa-plus"></i> {{ __('Add custom link') }}
                        </button>
                    </div>
                </div>

                <hr>

                <h5 class="mb-3 mt-4">
                    <i class="fas fa-magic mr-2 text-warning"></i>
                    {{ __('Set up with AI') }}
                </h5>
                <p class="small text-muted">{{ __('Let AI propose a sensible set of items for this menu based on your site’s pages and categories.') }}</p>
                <button type="button" class="btn btn-outline-primary btn-sm" id="ai-suggest">
                    <i class="fas fa-wand-magic-sparkles"></i> {{ __('Suggest menu items') }}
                </button>
                <div id="ai-suggest-result" class="mt-2"></div>
            </div>
        </div>
    </div>
</div>

<template id="item-template">
    @include('vela::admin.settings.menus._item-row', ['item' => null, 'index' => '__INDEX__'])
</template>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
(function () {
    const list = document.getElementById('menu-items');
    const tpl  = document.getElementById('item-template').innerHTML;
    const empty = document.getElementById('empty-hint');

    function renumber() {
        Array.from(list.children).forEach((row, i) => {
            row.querySelectorAll('input[name^="items["], select[name^="items["]').forEach(input => {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + i + ']');
            });
        });
        empty.style.display = list.children.length ? 'none' : '';
    }

    function applyTypeVisibility(row) {
        const type = row.dataset.type;
        row.querySelectorAll('[data-show-for]').forEach(el => {
            el.style.display = (el.dataset.showFor === type) ? '' : 'none';
        });
    }
    list.querySelectorAll('.menu-item-row').forEach(applyTypeVisibility);

    new Sortable(list, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: renumber,
    });

    function addRow(payload) {
        const i = list.children.length;
        const html = tpl
            .replace(/__INDEX__/g, i)
            .replace(/__TYPE__/g,  payload.type)
            .replace(/__REF_ID__/g, payload.ref_id ?? '')
            .replace(/__LABEL__/g, payload.label ?? '')
            .replace(/__URL__/g,   payload.url ?? '')
            .replace(/__TYPE_LABEL__/g, payload.type_label ?? payload.type);
        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        const row = wrap.firstElementChild;
        list.appendChild(row);
        applyTypeVisibility(row);
        renumber();
    }

    document.querySelectorAll('[data-add]').forEach(btn => {
        btn.addEventListener('click', () => {
            const type = btn.dataset.add;
            addRow({
                type:       type,
                ref_id:     btn.dataset.id,
                label:      btn.dataset.label,
                type_label: type,
            });
        });
    });

    document.getElementById('add-custom').addEventListener('click', () => {
        const label = document.getElementById('custom-label').value.trim();
        const url   = document.getElementById('custom-url').value.trim();
        if (!label || !url) {
            alert('{{ __('Both label and URL are required.') }}');
            return;
        }
        addRow({ type: 'url', label, url, type_label: 'url' });
        document.getElementById('custom-label').value = '';
        document.getElementById('custom-url').value = '';
    });

    list.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove]');
        if (!btn) return;
        btn.closest('.menu-item-row').remove();
        renumber();
    });

    document.querySelectorAll('[data-filter]').forEach(input => {
        input.addEventListener('input', () => {
            const target = document.querySelector(input.dataset.filter);
            const q = input.value.toLowerCase();
            target.querySelectorAll('li').forEach(li => {
                li.style.display = li.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    document.getElementById('ai-suggest').addEventListener('click', async () => {
        const btn = document.getElementById('ai-suggest');
        const out = document.getElementById('ai-suggest-result');
        btn.disabled = true;
        out.innerHTML = '<div class="text-muted small"><i class="fas fa-spinner fa-spin"></i> {{ __('Asking the AI…') }}</div>';
        try {
            const res = await fetch('{{ route('vela.admin.settings.menus.ai-suggest', $slot) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (!res.ok || !data.items) {
                out.innerHTML = '<div class="alert alert-warning small mb-0">' + (data.error || '{{ __('AI suggestion failed.') }}') + '</div>';
                return;
            }
            out.innerHTML = '<div class="alert alert-success small mb-0">{{ __('AI suggested :n items. They have been added below — review and save.', ['n' => '<span id="ai-count"></span>']) }}</div>';
            data.items.forEach(it => addRow({
                type: it.type, ref_id: it.ref_id, label: it.label, url: it.url, type_label: it.type
            }));
            const c = document.getElementById('ai-count');
            if (c) c.textContent = data.items.length;
        } catch (e) {
            out.innerHTML = '<div class="alert alert-danger small mb-0">' + e.message + '</div>';
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
