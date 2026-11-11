{{-- What the site still needs after an update, said where its owner is.
     Only someone who can change settings can act on it, and only they see it.
     One short line each: a button to the page that deals with it, or, when no
     page does, the command to run. The longer explanation is the tooltip. --}}
@can('config_edit')
@foreach(app(\VelaBuild\Core\Services\SiteHealth::class)->notices() as $notice)
<div class="alert alert-{{ $notice['level'] }} d-flex flex-wrap align-items-center py-2 mb-2" role="alert" title="{{ $notice['body'] }}">
    <i class="fas {{ $notice['level'] === 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle' }} mr-2"></i>
    <strong class="mr-auto">{{ $notice['title'] }}</strong>
    @if(!empty($notice['url']))
        <a href="{{ $notice['url'] }}" class="btn btn-sm btn-light ml-2 text-nowrap">{{ trans('vela::global.health_review') }} <i class="fas fa-arrow-right ml-1"></i></a>
    @else
        <code class="ml-2">{{ $notice['command'] }}</code>
    @endif
</div>
@endforeach
@endcan
