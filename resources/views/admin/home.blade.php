@extends('vela::layouts.admin')
@section('breadcrumb', trans('vela::global.dashboard'))
@section('content')
<div class="content">
    @if(session('status'))
        <div class="alert alert-success" role="alert">
            {{ session('status') }}
        </div>
    @endif

    <div class="vela-page-head">
        <div>
            <h1>{{ trans('vela::global.welcome') }}, {{ auth('vela')->user()->name }}.</h1>
            <div class="sub">{{ trans('vela::global.dashboard_subtitle', ['app' => config('app.name')]) }}</div>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="vela-btn vela-btn-secondary vela-btn-sm" id="vela-widget-settings-toggle">
                <i class="fas fa-cog mr-1"></i> {{ trans('vela::global.customize') }}
            </button>
            <a href="{{ url('/') }}" target="_blank" class="vela-btn vela-btn-secondary vela-btn-sm">
                {{ trans('vela::global.preview') }} <i class="fas fa-external-link-alt ml-1"></i>
            </a>
        </div>
    </div>

    {{-- Widget settings panel --}}
    <div id="vela-widget-settings" class="vela-panel mb-4" style="display: none;">
        <h6 style="font-weight: 600; margin-bottom: 8px;">{{ trans('vela::global.dashboard_widgets') }}</h6>
        <p style="color: var(--v-fg-muted); font-size: var(--v-text-sm); margin-bottom: 12px;">{{ trans('vela::global.widget_reorder_help') }}</p>
        <ul id="vela-widget-list" class="list-group">
            @foreach($widgets as $name => $widget)
                <li class="list-group-item d-flex justify-content-between align-items-center" data-widget="{{ $name }}" style="cursor: grab; border-radius: var(--v-r-sm); margin-bottom: 4px;">
                    <span>
                        <i class="fas fa-grip-vertical text-muted mr-2"></i>
                        <i class="{{ $widget['icon'] }} mr-1"></i>
                        {{ $widget['label'] }}
                    </span>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input vela-widget-toggle" id="toggle-{{ $name }}" value="{{ $name }}" {{ in_array($name, $disabledWidgets) ? '' : 'checked' }}>
                        <label class="custom-control-label" for="toggle-{{ $name }}"></label>
                    </div>
                </li>
            @endforeach
        </ul>
        <button type="button" class="vela-btn vela-btn-accent vela-btn-sm mt-3" id="vela-save-widget-prefs">
            <i class="fas fa-save mr-1"></i> {{ trans('vela::global.save_layout') }}
        </button>
    </div>

    {{-- Render widgets --}}
    <div class="row" id="vela-widgets-container">
        @foreach($widgets as $name => $widget)
            @if(!in_array($name, $disabledWidgets))
                @if($widget['gate'])
                    @can($widget['gate'])
                        <div class="{{ $widget['width'] }} mb-3 vela-widget-cell" data-widget="{{ $name }}">
                            @include($widget['view'], ['widgetData' => $widgetDataMap[$name] ?? null])
                        </div>
                    @endcan
                @else
                    <div class="{{ $widget['width'] }} mb-3 vela-widget-cell" data-widget="{{ $name }}">
                        @include($widget['view'], ['widgetData' => $widgetDataMap[$name] ?? null])
                    </div>
                @endif
            @endif
        @endforeach
    </div>
</div>
@endsection
@section('scripts')
@parent
<script>
document.addEventListener('DOMContentLoaded', function () {
    var settingsToggle = document.getElementById('vela-widget-settings-toggle');
    var settingsPanel = document.getElementById('vela-widget-settings');
    var widgetList = document.getElementById('vela-widget-list');
    var saveBtn = document.getElementById('vela-save-widget-prefs');

    settingsToggle.addEventListener('click', function () {
        settingsPanel.style.display = settingsPanel.style.display === 'none' ? 'block' : 'none';
    });

    var dragItem = null;

    widgetList.addEventListener('dragstart', function (e) {
        dragItem = e.target.closest('li');
        if (dragItem) {
            e.dataTransfer.effectAllowed = 'move';
            dragItem.style.opacity = '0.5';
        }
    });

    widgetList.addEventListener('dragend', function (e) {
        if (dragItem) dragItem.style.opacity = '';
        dragItem = null;
        widgetList.querySelectorAll('li').forEach(function (li) { li.style.borderTop = ''; });
    });

    widgetList.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var target = e.target.closest('li');
        if (target && target !== dragItem) {
            widgetList.querySelectorAll('li').forEach(function (li) { li.style.borderTop = ''; });
            target.style.borderTop = '2px solid var(--vela-teal-400)';
        }
    });

    widgetList.addEventListener('drop', function (e) {
        e.preventDefault();
        var target = e.target.closest('li');
        if (target && dragItem && target !== dragItem) {
            widgetList.insertBefore(dragItem, target);
        }
    });

    widgetList.querySelectorAll('li').forEach(function (li) {
        li.setAttribute('draggable', 'true');
    });

    saveBtn.addEventListener('click', function () {
        var order = [];
        var disabled = [];
        widgetList.querySelectorAll('li').forEach(function (li) {
            var name = li.getAttribute('data-widget');
            order.push(name);
            if (!li.querySelector('.vela-widget-toggle').checked) {
                disabled.push(name);
            }
        });

        fetch('{{ route("vela.admin.dashboard.preferences") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ order: order, disabled: disabled })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                window.location.reload();
            }
        });
    });
});
</script>
@endsection
