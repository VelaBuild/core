<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-plus mr-1"></i> {{ trans('vela::global.recent_users') }}</span>
        <a href="{{ route('vela.admin.users.index') }}" class="btn btn-sm btn-outline-primary">{{ trans('vela::global.view_all') }}</a>
    </div>
    <div class="card-body p-0">
        @if($widgetData->isEmpty())
            <div class="p-4 text-muted text-center">{{ trans('vela::global.no_users_yet') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ trans('vela::global.name') }}</th>
                            <th>{{ trans('vela::global.email') }}</th>
                            <th>{{ trans('vela::global.role') }}</th>
                            <th>{{ trans('vela::global.joined') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($widgetData as $user)
                            <tr>
                                <td>
                                    <a href="{{ route('vela.admin.users.edit', $user->id) }}">
                                        {{ $user->name }}
                                    </a>
                                </td>
                                <td class="text-muted small">{{ $user->email }}</td>
                                <td>
                                    @foreach($user->roles as $role)
                                        <span class="badge badge-info">{{ $role->title }}</span>
                                    @endforeach
                                </td>
                                <td class="text-muted small">{{ $user->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
