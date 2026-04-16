<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-comments mr-1"></i> {{ trans('vela::global.recent_comments') }}</span>
        <a href="{{ route('vela.admin.comments.index') }}" class="btn btn-sm btn-outline-primary">{{ trans('vela::global.view_all') }}</a>
    </div>
    <div class="card-body p-0">
        @if($widgetData->isEmpty())
            <div class="p-4 text-muted text-center">{{ trans('vela::global.no_comments_yet') }}</div>
        @else
            <ul class="list-group list-group-flush">
                @foreach($widgetData as $comment)
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="mr-2" style="min-width: 0;">
                                <strong>{{ $comment->user->name ?? trans('vela::global.guest') }}</strong>
                                <span class="badge badge-{{ $comment->status === 'visible' ? 'success' : ($comment->status === 'hidden' ? 'danger' : 'warning') }} ml-1">
                                    {{ ucfirst($comment->status) }}
                                </span>
                                <p class="mb-0 text-muted small text-truncate">{{ Str::limit($comment->comment, 80) }}</p>
                            </div>
                            <small class="text-muted text-nowrap">{{ $comment->created_at->diffForHumans() }}</small>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
