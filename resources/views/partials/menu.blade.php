<aside id="vela-sidebar" class="vela-sidebar">
    <div class="vela-sidebar-brand">
        <a href="{{ route('vela.admin.home') }}">
            <img src="{{ asset('vendor/vela/images/vela-logo-white.png') }}" alt="{{ trans('vela::panel.brand_name') }}">
        </a>
    </div>

    <nav class="vela-sidebar-nav">
        @foreach(app(\VelaBuild\Core\Vela::class)->menus()->grouped() as $group => $items)
            <div class="vela-sidebar-group">
                @if($group && $group !== 'default')
                    <div class="vela-sidebar-group-label">{{ trans($group) }}</div>
                @endif

                @foreach($items as $name => $item)
                    @if(!empty($item['children']))
                        @php
                            $isActive = collect($item['children'])->contains(function($child) {
                                return request()->routeIs($child['route'] . '*');
                            });
                        @endphp
                        @if($item['gate'])
                            @can($item['gate'])
                                <div class="vela-sidebar-link {{ $isActive ? 'is-active' : '' }}" onclick="this.nextElementSibling.classList.toggle('d-none')" style="cursor:pointer;">
                                    <i class="fa-fw {{ $item['icon'] }} ico"></i>
                                    {{ trans($item['label']) }}
                                </div>
                                <div class="vela-sidebar-dropdown-items {{ $isActive ? '' : 'd-none' }}">
                                    @foreach($item['children'] as $childName => $child)
                                        @if($child['gate'])
                                            @can($child['gate'])
                                                <a href="{{ route($child['route']) }}" class="vela-sidebar-link {{ request()->routeIs($child['route'] . '*') ? 'is-active' : '' }}">
                                                    <i class="fa-fw {{ $child['icon'] }} ico"></i>
                                                    {{ trans($child['label']) }}
                                                </a>
                                            @endcan
                                        @else
                                            <a href="{{ route($child['route']) }}" class="vela-sidebar-link {{ request()->routeIs($child['route'] . '*') ? 'is-active' : '' }}">
                                                <i class="fa-fw {{ $child['icon'] }} ico"></i>
                                                {{ trans($child['label']) }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endcan
                        @else
                            <div class="vela-sidebar-link {{ $isActive ? 'is-active' : '' }}" onclick="this.nextElementSibling.classList.toggle('d-none')" style="cursor:pointer;">
                                <i class="fa-fw {{ $item['icon'] }} ico"></i>
                                {{ trans($item['label']) }}
                            </div>
                            <div class="vela-sidebar-dropdown-items {{ $isActive ? '' : 'd-none' }}">
                                @foreach($item['children'] as $childName => $child)
                                    @if($child['gate'])
                                        @can($child['gate'])
                                            <a href="{{ route($child['route']) }}" class="vela-sidebar-link {{ request()->routeIs($child['route'] . '*') ? 'is-active' : '' }}">
                                                <i class="fa-fw {{ $child['icon'] }} ico"></i>
                                                {{ trans($child['label']) }}
                                            </a>
                                        @endcan
                                    @else
                                        <a href="{{ route($child['route']) }}" class="vela-sidebar-link {{ request()->routeIs($child['route'] . '*') ? 'is-active' : '' }}">
                                            <i class="fa-fw {{ $child['icon'] }} ico"></i>
                                            {{ trans($child['label']) }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    @else
                        @php
                            $isLiteral = str_starts_with($item['route'], '#') || str_starts_with($item['route'], 'http');
                            $menuHref = $isLiteral ? '#' : route($item['route']);
                            $menuActive = !$isLiteral && request()->routeIs($item['route'] . '*');
                            $menuId = $isLiteral ? 'menu-' . $name : '';
                        @endphp
                        @if($item['gate'])
                            @can($item['gate'])
                                <a href="{{ $menuHref }}" class="vela-sidebar-link {{ $menuActive ? 'is-active' : '' }}" @if($menuId) id="{{ $menuId }}" @endif>
                                    <i class="fa-fw {{ $item['icon'] }} ico"></i>
                                    {{ trans($item['label']) }}
                                </a>
                            @endcan
                        @else
                            <a href="{{ $menuHref }}" class="vela-sidebar-link {{ $menuActive ? 'is-active' : '' }}" @if($menuId) id="{{ $menuId }}" @endif>
                                <i class="fa-fw {{ $item['icon'] }} ico"></i>
                                {{ trans($item['label']) }}
                            </a>
                        @endif
                    @endif
                @endforeach
            </div>
        @endforeach
    </nav>

    <details class="vela-sidebar-user js-click-away">
        <summary class="vela-sidebar-user-trigger" aria-label="{{ trans('vela::global.my_profile') }}">
            <div class="vela-avatar vela-avatar-sm" style="background: var(--vela-teal-500); color: #fff;">
                {{ strtoupper(substr(auth('vela')->user()->name, 0, 1)) }}{{ strtoupper(substr(explode(' ', auth('vela')->user()->name)[1] ?? '', 0, 1)) }}
            </div>
            <div style="flex: 1; min-width: 0;">
                <div class="name">{{ auth('vela')->user()->name }}</div>
                <div class="plan">{{ auth('vela')->user()->roles->first()->title ?? 'Admin' }}</div>
            </div>
            <i class="fas fa-chevron-up vela-sidebar-user-chevron" aria-hidden="true"></i>
        </summary>
        <div class="vela-sidebar-user-menu">
@foreach(app(\VelaBuild\Core\Vela::class)->profileMenu()->all() as $itemName => $item)
@if($item['divider_before'])
            <div class="vela-sidebar-user-divider"></div>
@endif
@if($item['gate'] && !Gate::allows($item['gate'])) @continue @endif
@if($item['route'] === '#logout')
            <a class="vela-sidebar-user-item" href="#" onclick="event.preventDefault(); document.getElementById('logoutform').submit();">
                <i class="{{ $item['icon'] }} fa-fw"></i><span>{{ trans($item['label']) }}</span>
            </a>
@else
            <a class="vela-sidebar-user-item" href="{{ route($item['route']) }}">
                <i class="{{ $item['icon'] }} fa-fw"></i><span>{{ trans($item['label']) }}</span>
            </a>
@endif
@endforeach
        </div>
    </details>
</aside>
