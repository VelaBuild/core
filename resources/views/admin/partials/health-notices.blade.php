{{-- What the site still needs after an update, said where its owner is.
     Only someone who can change settings can act on it, and only they see it. --}}
@can('config_edit')
@foreach(app(\VelaBuild\Core\Services\SiteHealth::class)->notices() as $notice)
<div class="alert alert-{{ $notice['level'] }}" role="alert">
    <strong>{{ $notice['title'] }}</strong>
    <div class="mt-1">{{ $notice['body'] }}</div>
    <code class="d-inline-block mt-2">{{ $notice['command'] }}</code>
</div>
@endforeach
@endcan
