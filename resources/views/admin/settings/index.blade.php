@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.config.title') }}
    </div>
    <div class="card-body">
        <div class="row">
            {{-- All cards come from SettingsNavRegistry. To add one,
                 call Vela::registerSettingsItem(...) from a service
                 provider. Do not hardcode cards in this file. --}}
            @foreach(app(\VelaBuild\Core\Vela::class)->settingsNav()->all(includeHidden: false) as $item)
                @php $url = app(\VelaBuild\Core\Vela::class)->settingsNav()->url($item); @endphp
                @if($url)
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="{{ $item['icon'] }} fa-3x mb-3 text-primary"></i>
                                <h5>{{ $item['label'] }}</h5>
                                @if(!empty($item['description']))
                                    <p class="text-muted">{{ $item['description'] }}</p>
                                @endif
                                <a href="{{ $url }}" class="btn btn-primary btn-sm">
                                    {{ __('vela::pwa.manage') }}
                                </a>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endsection
