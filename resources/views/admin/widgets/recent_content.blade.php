<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-newspaper mr-1"></i> {{ trans('vela::global.recent_content') }}</span>
        <a href="{{ route('vela.admin.contents.index') }}" class="btn btn-sm btn-outline-primary">{{ trans('vela::global.view_all') }}</a>
    </div>
    <div class="card-body p-0">
        @if($widgetData->isEmpty())
            <div class="p-4 text-muted text-center">{{ trans('vela::global.no_content_yet') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ trans('vela::global.title') }}</th>
                            <th>{{ trans('vela::global.author') }}</th>
                            <th>{{ trans('vela::global.status') }}</th>
                            <th>{{ trans('vela::global.date') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($widgetData as $content)
                            <tr>
                                <td>
                                    <a href="{{ route('vela.admin.contents.edit', $content->id) }}">
                                        {{ Str::limit($content->title, 35) }}
                                    </a>
                                </td>
                                <td class="text-muted small">{{ $content->author->name ?? '-' }}</td>
                                <td>
                                    <span class="badge badge-{{ $content->status === 'published' ? 'success' : ($content->status === 'draft' ? 'warning' : 'secondary') }}">
                                        {{ ucfirst($content->status) }}
                                    </span>
                                </td>
                                <td class="text-muted small">{{ $content->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
