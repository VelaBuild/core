@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.reviews.title'), 'toolIcon' => 'fas fa-star'])
        @if($totalReviews > 0)
            <span class="badge badge-success">{{ $totalReviews }} {{ Str::plural('Review', $totalReviews) }}</span>
        @else
            <span class="badge badge-warning">{{ trans('vela::tools.reviews.no_reviews_yet') }}</span>
        @endif
    </div>
    <div class="card-body">

        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif

        {{-- Summary --}}
        @if($totalReviews > 0)
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3 text-center">
                        <div style="font-size: 3rem; font-weight: bold;">{{ number_format($avgRating, 1) }}</div>
                        <div>
                            @for($i = 1; $i <= 5; $i++)
                                <i class="fas fa-star {{ $i <= round($avgRating) ? 'text-warning' : 'text-muted' }}"></i>
                            @endfor
                        </div>
                        <div class="text-muted mt-1">{{ $totalReviews }} {{ Str::plural('review', $totalReviews) }}</div>
                    </div>
                    <div class="col-md-9">
                        @php
                            $ratingCounts = \VelaBuild\Core\Models\Review::published()
                                ->selectRaw('rating, count(*) as count')
                                ->groupBy('rating')
                                ->orderBy('rating', 'desc')
                                ->get()
                                ->keyBy('rating');
                        @endphp
                        @for($star = 5; $star >= 1; $star--)
                            @php $count = $ratingCounts[$star]->count ?? 0; $pct = $totalReviews > 0 ? round($count / $totalReviews * 100) : 0; @endphp
                            <div class="d-flex align-items-center mb-1">
                                <span style="width: 50px; font-size: 0.85em;">{{ $star }} <i class="fas fa-star text-warning" style="font-size: 0.8em;"></i></span>
                                <div class="progress flex-grow-1 mx-2" style="height: 10px;">
                                    <div class="progress-bar bg-warning" style="width: {{ $pct }}%"></div>
                                </div>
                                <span style="width: 30px; font-size: 0.85em; text-align: right;">{{ $count }}</span>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if($canConfigure)
        {{-- Google Config --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.reviews.google_reviews_configuration') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('vela.admin.tools.reviews.config') }}">
                    @csrf

                    <div class="form-row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ trans('vela::tools.reviews.google_places_api_key') }}</label>
                                <input type="password"
                                       name="google_places_api_key"
                                       class="form-control"
                                       placeholder="{{ $isGoogleConfigured ? '••••••••••••  (set — leave blank to keep unchanged)' : trans('vela::tools.reviews.google_places_api_key') }}"
                                       value="">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ trans('vela::tools.reviews.google_place_id') }}</label>
                                <input type="text"
                                       name="google_place_id"
                                       class="form-control"
                                       value="{{ $placeId }}"
                                       placeholder="e.g. ChIJ...">
                                <small class="text-muted">{!! trans('vela::tools.reviews.place_id_help') !!}</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center">
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-save mr-1"></i> {{ trans('vela::tools.common.save_settings') }}
                        </button>
                        @if($isGoogleConfigured)
                            <button type="button" id="sync-btn" class="btn btn-outline-secondary">
                                <i class="fas fa-sync-alt mr-1"></i> {{ trans('vela::tools.reviews.sync_now') }}
                            </button>
                            <span id="sync-feedback" class="ml-2"></span>
                        @endif
                    </div>
                </form>
            </div>
        </div>
        @endif

        {{-- Add Manual Review --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.reviews.add_manual_review') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('vela.admin.tools.reviews.store') }}">
                    @csrf

                    <div class="form-row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>{{ trans('vela::tools.reviews.author') }} <span class="text-danger">*</span></label>
                                <input type="text" name="author" class="form-control" required placeholder="{{ trans('vela::tools.reviews.reviewer_name') }}">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>{{ trans('vela::tools.reviews.rating') }} <span class="text-danger">*</span></label>
                                <select name="rating" class="form-control" required>
                                    @for($i = 5; $i >= 1; $i--)
                                        <option value="{{ $i }}">{{ $i }} star{{ $i !== 1 ? 's' : '' }}</option>
                                    @endfor
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ trans('vela::tools.reviews.review_date') }}</label>
                                <input type="date" name="review_date" class="form-control" value="{{ date('Y-m-d') }}">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.reviews.review_text') }}</label>
                        <textarea name="text" class="form-control" rows="3" placeholder="{{ trans('vela::tools.reviews.review_content') }}"></textarea>
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus mr-1"></i> {{ trans('vela::tools.reviews.add_review') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Reviews Table --}}
        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.reviews.all_reviews') }}</h5></div>
            <div class="card-body p-0">
                @if($reviews->isEmpty())
                    <div class="p-4 text-muted">{{ trans('vela::tools.reviews.no_reviews_table') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('vela::tools.reviews.author') }}</th>
                                    <th>{{ trans('vela::tools.reviews.rating') }}</th>
                                    <th>{{ trans('vela::tools.reviews.review') }}</th>
                                    <th>{{ trans('vela::tools.reviews.source') }}</th>
                                    <th>{{ trans('vela::tools.common.date') }}</th>
                                    <th>{{ trans('vela::tools.common.published') }}</th>
                                    <th>{{ trans('vela::tools.common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reviews as $review)
                                    <tr id="review-row-{{ $review->id }}">
                                        <td>
                                            <strong>{{ $review->author }}</strong>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            @for($i = 1; $i <= 5; $i++)
                                                <i class="fas fa-star {{ $i <= $review->rating ? 'text-warning' : 'text-muted' }}" style="font-size: 0.8em;"></i>
                                            @endfor
                                        </td>
                                        <td>
                                            @if($review->text)
                                                <span title="{{ $review->text }}">{{ Str::limit($review->text, 80) }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($review->source === 'google')
                                                <span class="badge badge-primary">{{ trans('vela::tools.reviews.source_google') }}</span>
                                            @else
                                                <span class="badge badge-secondary">{{ trans('vela::tools.reviews.source_manual') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($review->review_date)
                                                {{ $review->review_date->format('M j, Y') }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <form method="POST" action="{{ route('vela.admin.tools.reviews.update', $review->id) }}" style="display:inline;">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="published" value="{{ $review->published ? '0' : '1' }}">
                                                <button type="submit" class="btn btn-sm {{ $review->published ? 'btn-success' : 'btn-outline-secondary' }}" title="{{ $review->published ? trans('vela::tools.reviews.published_click_unpublish') : trans('vela::tools.reviews.unpublished_click_publish') }}">
                                                    <i class="fas {{ $review->published ? 'fa-eye' : 'fa-eye-slash' }}"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-toggle="modal"
                                                    data-target="#editModal{{ $review->id }}">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="{{ route('vela.admin.tools.reviews.destroy', $review->id) }}" style="display:inline;" onsubmit="return confirm('{{ trans('vela::tools.reviews.delete_confirm') }}');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    {{-- Edit Modal --}}
                                    <div class="modal fade" id="editModal{{ $review->id }}" tabindex="-1" role="dialog">
                                        <div class="modal-dialog" role="document">
                                            <div class="modal-content">
                                                <form method="POST" action="{{ route('vela.admin.tools.reviews.update', $review->id) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">{{ trans('vela::tools.edit_review') }} — {{ $review->author }}</h5>
                                                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="form-group">
                                                            <label>{{ trans('vela::tools.reviews.author') }}</label>
                                                            <input type="text" name="author" class="form-control" value="{{ $review->author }}">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>{{ trans('vela::tools.reviews.rating') }}</label>
                                                            <select name="rating" class="form-control">
                                                                @for($i = 5; $i >= 1; $i--)
                                                                    <option value="{{ $i }}" {{ $review->rating == $i ? 'selected' : '' }}>{{ $i }} star{{ $i !== 1 ? 's' : '' }}</option>
                                                                @endfor
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>{{ trans('vela::tools.reviews.review_text') }}</label>
                                                            <textarea name="text" class="form-control" rows="4">{{ $review->text }}</textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                                                        <button type="submit" class="btn btn-primary">{{ trans('vela::global.save_changes') }}</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-2">
                        {{ $reviews->links() }}
                    </div>
                @endif
            </div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var syncBtn = document.getElementById('sync-btn');
    if (syncBtn) {
        var msgSyncing = '{{ trans('vela::tools.reviews.syncing') }}';
        var msgSyncNow = '{{ trans('vela::tools.reviews.sync_now') }}';
        var msgRequestFailed = '{{ trans('vela::tools.common.request_failed') }}';

        syncBtn.addEventListener('click', function () {
            var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var feedback = document.getElementById('sync-feedback');

            syncBtn.disabled = true;
            syncBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + msgSyncing;
            feedback.innerHTML = '';

            fetch('{{ route('vela.admin.tools.reviews.sync') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                feedback.innerHTML = '<span class="text-' + (data.success ? 'success' : 'danger') + '">'
                    + (data.success ? '<i class="fas fa-check mr-1"></i>' : '<i class="fas fa-times mr-1"></i>')
                    + data.message + '</span>';
                if (data.success) {
                    setTimeout(function () { window.location.reload(); }, 1500);
                }
            })
            .catch(function () {
                feedback.innerHTML = '<span class="text-danger">' + msgRequestFailed + '</span>';
            })
            .finally(function () {
                syncBtn.disabled = false;
                syncBtn.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> ' + msgSyncNow;
            });
        });
    }
});
</script>
@endsection
