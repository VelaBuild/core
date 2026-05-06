{{-- Default front-end menu renderer.
     Themes can override per-slot via @velaMenu('primary', 'vela::partials.my-menu')
     or by passing a $linkClass / $itemClass via the directive's third arg. --}}
@php
    $linkClass = $linkClass ?? 'vela-menu-link';
    $itemClass = $itemClass ?? 'vela-menu-item';
    $activeClass = $activeClass ?? 'is-active';
@endphp
@foreach($items as $item)
    @php
        $href   = $item->resolveUrl();
        $label  = $item->resolveLabel();
        $target = $item->target ?? '_self';
        $active = $item->isActive();
    @endphp
    @if($label === '')
        @continue
    @endif
    <a href="{{ $href }}"
       class="{{ $linkClass }} {{ $active ? $activeClass : '' }}"
       @if($target && $target !== '_self') target="{{ $target }}" rel="noopener" @endif>
        {{ $label }}
    </a>
@endforeach
