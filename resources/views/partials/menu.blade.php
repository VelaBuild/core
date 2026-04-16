<div id="sidebar" class="c-sidebar c-sidebar-fixed c-sidebar-lg-show">

    <div class="c-sidebar-brand d-md-down-none" style="justify-content: flex-start; padding-left: 1rem;">
        <a class="c-sidebar-brand-full" href="{{ route('vela.admin.home') }}">
            <img src="{{ asset('vendor/vela/images/vela-logo-white.png') }}" alt="{{ trans('vela::panel.brand_name') }}" style="height:32px;width:auto">
        </a>
    </div>

    <ul class="c-sidebar-nav">
        @foreach(app(\VelaBuild\Core\Vela::class)->menus()->grouped() as $group => $items)
            @foreach($items as $name => $item)
                @if(!empty($item['children']))
                    @php
                        $isActive = collect($item['children'])->contains(function($child) {
                            return request()->routeIs($child['route'] . '*');
                        });
                    @endphp
                    @if($item['gate'])
                        @can($item['gate'])
                            <li class="c-sidebar-nav-dropdown {{ $isActive ? 'c-show' : '' }}">
                                <a class="c-sidebar-nav-dropdown-toggle" href="#">
                                    <i class="fa-fw {{ $item['icon'] }} c-sidebar-nav-icon"></i>
                                    {{ trans($item['label']) }}
                                </a>
                                <ul class="c-sidebar-nav-dropdown-items">
                                    @foreach($item['children'] as $childName => $child)
                                        @if($child['gate'])
                                            @can($child['gate'])
                                                <li class="c-sidebar-nav-item">
                                                    <a href="{{ route($child['route']) }}" class="c-sidebar-nav-link {{ request()->routeIs($child['route'] . '*') ? 'c-active' : '' }}">
                                                        <i class="fa-fw {{ $child['icon'] }} c-sidebar-nav-icon"></i>
                                                        {{ trans($child['label']) }}
                                                    </a>
                                                </li>
                                            @endcan
                                        @else
                                            <li class="c-sidebar-nav-item">
                                                <a href="{{ route($child['route']) }}" class="c-sidebar-nav-link {{ request()->routeIs($child['route'] . '*') ? 'c-active' : '' }}">
                                                    <i class="fa-fw {{ $child['icon'] }} c-sidebar-nav-icon"></i>
                                                    {{ trans($child['label']) }}
                                                </a>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </li>
                        @endcan
                    @else
                        <li class="c-sidebar-nav-dropdown {{ $isActive ? 'c-show' : '' }}">
                            <a class="c-sidebar-nav-dropdown-toggle" href="#">
                                <i class="fa-fw {{ $item['icon'] }} c-sidebar-nav-icon"></i>
                                {{ trans($item['label']) }}
                            </a>
                            <ul class="c-sidebar-nav-dropdown-items">
                                @foreach($item['children'] as $childName => $child)
                                    @if($child['gate'])
                                        @can($child['gate'])
                                            <li class="c-sidebar-nav-item">
                                                <a href="{{ route($child['route']) }}" class="c-sidebar-nav-link {{ request()->routeIs($child['route'] . '*') ? 'c-active' : '' }}">
                                                    <i class="fa-fw {{ $child['icon'] }} c-sidebar-nav-icon"></i>
                                                    {{ trans($child['label']) }}
                                                </a>
                                            </li>
                                        @endcan
                                    @else
                                        <li class="c-sidebar-nav-item">
                                            <a href="{{ route($child['route']) }}" class="c-sidebar-nav-link {{ request()->routeIs($child['route'] . '*') ? 'c-active' : '' }}">
                                                <i class="fa-fw {{ $child['icon'] }} c-sidebar-nav-icon"></i>
                                                {{ trans($child['label']) }}
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </li>
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
                            <li class="c-sidebar-nav-item">
<!--                                <a href="{{ $item['route'] === '#' ? '#' : route($item['route']) }}" class="c-sidebar-nav-link {{ $item['route'] !== '#' && request()->routeIs($item['route'] . '*') ? 'c-active' : '' }}">-->
                                <a href="{{ $menuHref }}" class="c-sidebar-nav-link {{ $menuActive ? 'c-active' : '' }}" @if($menuId) id="{{ $menuId }}" @endif>
                                    <i class="fa-fw {{ $item['icon'] }} c-sidebar-nav-icon"></i>
                                    {{ trans($item['label']) }}
                                </a>
                            </li>
                        @endcan
                    @else
                        <li class="c-sidebar-nav-item">
                            <a href="{{ $menuHref }}" class="c-sidebar-nav-link {{ $menuActive ? 'c-active' : '' }}" @if($menuId) id="{{ $menuId }}" @endif>
                                <i class="fa-fw {{ $item['icon'] }} c-sidebar-nav-icon"></i>
                                {{ trans($item['label']) }}
                            </a>
                        </li>
                    @endif
                @endif
            @endforeach
        @endforeach

    </ul>

</div>
