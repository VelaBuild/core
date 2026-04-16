@php
    $allTools = app(\VelaBuild\Core\Vela::class)->tools()->all();
    $currentRoute = request()->route()->getName();
@endphp
<div class="dropdown d-inline-block">
    <button class="btn btn-link text-dark font-weight-bold p-0 dropdown-toggle" type="button" id="toolNavDropdown" data-toggle="dropdown" data-coreui-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="font-size:1.5rem; text-decoration:none;">
        <i class="{{ $toolIcon ?? 'fas fa-toolbox' }} mr-1"></i> {{ $toolName ?? trans('vela::tools.title') }}
    </button>
    <div class="dropdown-menu" aria-labelledby="toolNavDropdown">
        <a class="dropdown-item" href="{{ route('vela.admin.tools.index') }}">
            <i class="fas fa-th-large mr-2"></i> {{ trans('vela::tools.common.all_tools') }}
        </a>
        <div class="dropdown-divider"></div>
        @foreach($allTools as $name => $tool)
            @can($tool['gate'] ?? 'tools_access')
                <a class="dropdown-item {{ $currentRoute === $tool['route'] ? 'active' : '' }}"
                   href="{{ route($tool['route']) }}">
                    <i class="{{ $tool['icon'] }} mr-2"></i> {{ trans($tool['label']) }}
                </a>
            @endcan
        @endforeach
    </div>
</div>
