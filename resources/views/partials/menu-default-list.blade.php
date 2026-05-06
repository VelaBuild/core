{{-- List-item variant of menu-default — caller wraps in <ul>. --}}
@php
    $linkClass = $linkClass ?? 'vela-menu-link';
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
    <li>
        <a href="{{ $href }}"
           class="{{ $linkClass }} {{ $active ? $activeClass : '' }}"
           @if($target && $target !== '_self') target="{{ $target }}" rel="noopener" @endif>
            {{ $label }}
        </a>
    </li>
@endforeach
