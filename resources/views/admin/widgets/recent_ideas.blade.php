<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-lightbulb mr-1"></i> {{ trans('vela::global.recent_ideas') }}</span>
        <a href="{{ route('vela.admin.ideas.index') }}" class="btn btn-sm btn-outline-primary">{{ trans('vela::global.view_all') }}</a>
    </div>
    <div class="card-body p-0">
        @if($widgetData->isEmpty())
            <div class="p-4 text-muted text-center">{{ trans('vela::global.no_ideas_yet') }}</div>
        @else
            <ul class="list-group list-group-flush">
                @foreach($widgetData as $idea)
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="mr-2" style="min-width: 0;">
                                <a href="{{ route('vela.admin.ideas.edit', $idea->id) }}">
                                    {{ Str::limit($idea->name, 50) }}
                                </a>
                                <span class="badge badge-{{ $idea->status === 'new' ? 'primary' : ($idea->status === 'planned' ? 'info' : ($idea->status === 'created' ? 'success' : 'secondary')) }} ml-1">
                                    {{ ucfirst($idea->status) }}
                                </span>
                                @if($idea->keyword)
                                    <p class="mb-0 text-muted small">{{ Str::limit($idea->keyword, 60) }}</p>
                                @endif
                            </div>
                            <small class="text-muted text-nowrap">{{ $idea->created_at->diffForHumans() }}</small>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
