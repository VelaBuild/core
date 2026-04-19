{{-- Command Palette (Cmd+K) — Vela Design System --}}
<div class="vela-cmd-overlay" id="vela-cmd-overlay">
    <div class="vela-cmd-palette">
        <div class="vela-cmd-input">
            <span style="color: var(--v-fg-subtle); font-size: 20px;"><i class="fas fa-search"></i></span>
            <input type="text" id="vela-cmd-search" placeholder="{{ trans('vela::global.command_palette_placeholder') }}" autocomplete="off">
            <span class="vela-kbd">esc</span>
        </div>
        <div class="vela-cmd-results" id="vela-cmd-results">
            @can('ai_chat_access')
            <div class="vela-cmd-section-label">{{ trans('vela::ai.helper_title') }}</div>
            <div class="vela-cmd-item is-active" data-action="ai">
                <div class="lead"><div class="ico"><i class="fas fa-star" style="font-size:10px;"></i></div><span id="vela-cmd-ai-label">{{ trans('vela::global.ask_vela') }}</span></div>
                <span class="vela-kbd">↵</span>
            </div>
            @endcan

            <div class="vela-cmd-section-label">{{ trans('vela::global.actions') }}</div>
            @can('page_create')
            <div class="vela-cmd-item" data-action="navigate" data-url="{{ route('vela.admin.pages.create') }}">
                <div class="lead"><div class="ico"><i class="fas fa-plus" style="font-size:10px;"></i></div><span>{{ trans('vela::global.create_new_page') }}</span></div>
            </div>
            @endcan
            @can('article_create')
            <div class="vela-cmd-item" data-action="navigate" data-url="{{ route('vela.admin.articles.create') }}">
                <div class="lead"><div class="ico"><i class="fas fa-pen" style="font-size:10px;"></i></div><span>{{ trans('vela::global.create_new_article') }}</span></div>
            </div>
            @endcan
            @can('media_access')
            <div class="vela-cmd-item" data-action="navigate" data-url="{{ route('vela.admin.media.index') }}">
                <div class="lead"><div class="ico"><i class="fas fa-images" style="font-size:10px;"></i></div><span>{{ trans('vela::media.library_title') }}</span></div>
            </div>
            @endcan

            <div class="vela-cmd-section-label">{{ trans('vela::global.pages') }}</div>
            <div id="vela-cmd-pages-list"></div>

            <div class="vela-cmd-section-label">{{ trans('vela::global.settings') }}</div>
            @can('config_edit')
            <div class="vela-cmd-item" data-action="navigate" data-url="{{ route('vela.admin.settings.index', 'general') }}">
                <div class="lead"><div class="ico"><i class="fas fa-cog" style="font-size:10px;"></i></div><span>{{ trans('vela::global.general_settings') }}</span></div>
            </div>
            @endcan
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.getElementById('vela-cmd-overlay');
    var searchInput = document.getElementById('vela-cmd-search');
    var cmdTrigger = document.getElementById('vela-cmd-trigger');

    function openPalette() {
        overlay.classList.add('is-open');
        searchInput.value = '';
        searchInput.focus();
        filterItems('');
    }

    function closePalette() {
        overlay.classList.remove('is-open');
    }

    // Cmd+K / Ctrl+K
    document.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            if (overlay.classList.contains('is-open')) closePalette();
            else openPalette();
        }
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
            closePalette();
        }
    });

    // Click search bar trigger
    if (cmdTrigger) {
        cmdTrigger.addEventListener('focus', function(e) {
            e.preventDefault();
            this.blur();
            openPalette();
        });
    }

    // Close on overlay backdrop click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closePalette();
    });

    // Filter results
    searchInput.addEventListener('input', function() {
        filterItems(this.value.toLowerCase());
    });

    function filterItems(query) {
        var items = overlay.querySelectorAll('.vela-cmd-item');
        var sections = overlay.querySelectorAll('.vela-cmd-section-label');
        var firstVisible = null;

        items.forEach(function(item) {
            var text = item.textContent.toLowerCase();
            var visible = !query || text.indexOf(query) !== -1;
            item.style.display = visible ? '' : 'none';
            item.classList.remove('is-active');
            if (visible && !firstVisible) firstVisible = item;
        });

        if (firstVisible) firstVisible.classList.add('is-active');

        // Update AI label
        var aiLabel = document.getElementById('vela-cmd-ai-label');
        if (aiLabel && query) {
            aiLabel.textContent = '{{ trans("vela::global.ask_vela") }}: "' + searchInput.value + '"';
        } else if (aiLabel) {
            aiLabel.textContent = '{{ trans("vela::global.ask_vela") }}';
        }
    }

    // Keyboard navigation
    searchInput.addEventListener('keydown', function(e) {
        var visibleItems = Array.from(overlay.querySelectorAll('.vela-cmd-item')).filter(function(el) { return el.style.display !== 'none'; });
        var activeIdx = visibleItems.findIndex(function(el) { return el.classList.contains('is-active'); });

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            visibleItems.forEach(function(el) { el.classList.remove('is-active'); });
            var next = (activeIdx + 1) % visibleItems.length;
            visibleItems[next].classList.add('is-active');
            visibleItems[next].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            visibleItems.forEach(function(el) { el.classList.remove('is-active'); });
            var prev = activeIdx <= 0 ? visibleItems.length - 1 : activeIdx - 1;
            visibleItems[prev].classList.add('is-active');
            visibleItems[prev].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var active = overlay.querySelector('.vela-cmd-item.is-active');
            if (active) activateItem(active);
        }
    });

    // Click on item
    overlay.addEventListener('click', function(e) {
        var item = e.target.closest('.vela-cmd-item');
        if (item) activateItem(item);
    });

    function activateItem(item) {
        var action = item.dataset.action;
        if (action === 'navigate' && item.dataset.url) {
            window.location.href = item.dataset.url;
        } else if (action === 'ai') {
            closePalette();
            var toggle = document.getElementById('ai-chat-toggle');
            if (toggle) toggle.click();
        }
        closePalette();
    }
});
</script>
