@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>{{ trans('vela::global.show') }} {{ trans('vela::cruds.page.title_singular') }}: {{ $page->title }}</span>
        <div>
            <a href="{{ LaravelLocalization::getLocalizedURL($page->locale, '/' . $page->slug) }}" class="btn btn-sm btn-outline-primary" target="_blank">
                <i class="fas fa-external-link-alt"></i> {{ trans('vela::global.view_live') }}
            </a>
            <a href="{{ route('vela.admin.pages.edit', $page->id) }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-edit"></i> {{ trans('vela::global.edit') }}
            </a>
        </div>
    </div>

    <div class="card-body">
        <table class="table table-bordered table-sm mb-4">
            <tr><th style="width:150px;">{{ trans('vela::global.title') }}</th><td>{{ $page->title }}</td></tr>
            <tr><th>{{ trans('vela::global.slug') }}</th><td>/{{ $page->slug }}</td></tr>
            <tr><th>{{ trans('vela::global.locale') }}</th><td>{{ $page->locale }}</td></tr>
            <tr>
                <th>{{ trans('vela::global.status') }}</th>
                <td>
                    @php
                        $badgeClass = ['draft' => 'badge-secondary', 'published' => 'badge-success', 'unlisted' => 'badge-warning'][$page->status] ?? 'badge-secondary';
                    @endphp
                    <span class="badge {{ $badgeClass }}">{{ trans('vela::global.status_' . $page->status) }}</span>
                </td>
            </tr>
            @if($page->meta_title)<tr><th>{{ trans('vela::global.meta_title') }}</th><td>{{ $page->meta_title }}</td></tr>@endif
            @if($page->meta_description)<tr><th>{{ trans('vela::global.meta_description') }}</th><td>{{ $page->meta_description }}</td></tr>@endif
        </table>

        <h5 class="mb-3">{{ trans('vela::global.page_content_preview') }}</h5>

        @forelse($page->rows->sortBy('order_column') as $row)
            <div class="card mb-3">
                <div class="card-header py-2 bg-light d-flex justify-content-between">
                    <small class="text-muted">
                        <i class="fas fa-grip-vertical mr-1"></i>
                        {{ trans('vela::global.row_label') }}{{ $row->name ? ': ' . e($row->name) : '' }}
                        @if($row->css_class) <code class="ml-2">.{{ e($row->css_class) }}</code> @endif
                    </small>
                </div>
                <div class="card-body p-2">
                    @php
                        $columns = $row->blocks->groupBy('column_index');
                    @endphp
                    @if($columns->count() > 0)
                    <div class="row">
                        @foreach($columns as $colIndex => $blocks)
                            @php
                                $colWidth = $blocks->first()->column_width ?? 12;
                                $bsCol = $colWidth;
                            @endphp
                            <div class="col-md-{{ $bsCol }}">
                                <div style="border:1px dashed #dee2e6; border-radius:4px; padding:8px; min-height:40px;">
                                    <small class="text-muted d-block mb-2">{{ trans('vela::global.column_label', ['index' => $colIndex + 1, 'percent' => round(($colWidth / 12) * 100)]) }}</small>
                                    @foreach($blocks->sortBy('order_column') as $block)
                                        <div class="mb-3 p-2" style="border:1px solid #e9ecef; border-radius:4px; background:#fff;">
                                            <div class="d-flex justify-content-between align-items-center mb-2" style="border-bottom:1px solid #f0f0f0; padding-bottom:4px;">
                                                <small class="text-uppercase text-muted font-weight-bold">
                                                    @if($block->type === 'text')<i class="fas fa-font mr-1"></i>{{ trans('vela::global.block_type_text') }}
                                                    @elseif($block->type === 'image')<i class="fas fa-image mr-1"></i>{{ trans('vela::global.block_type_image') }}
                                                    @elseif($block->type === 'video')<i class="fas fa-video mr-1"></i>{{ trans('vela::global.block_type_video') }}
                                                    @elseif($block->type === 'html')<i class="fas fa-code mr-1"></i>{{ trans('vela::global.block_type_html') }}
                                                    @elseif($block->type === 'accordion')<i class="fas fa-list-ul mr-1"></i>{{ trans('vela::global.block_type_accordion') }}
                                                    @elseif($block->type === 'contact_form')<i class="fas fa-envelope mr-1"></i>{{ trans('vela::global.block_type_contact_form') }}
                                                    @else<i class="fas fa-cube mr-1"></i>{{ $block->type }}
                                                    @endif
                                                </small>
                                            </div>
                                            <div class="block-preview-rendered">
                                                @if(in_array($block->type, \VelaBuild\Core\Models\PageBlock::BLOCK_TYPES))
                                                    @include('vela::admin.pages.partials.block-preview-' . $block->type, ['block' => $block])
                                                @else
                                                    <em class="text-muted">{{ trans('vela::global.unknown_block_type') }}</em>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @else
                        <em class="text-muted">{{ trans('vela::global.empty_row') }}</em>
                    @endif
                </div>
            </div>
        @empty
            <div class="alert alert-info">{{ trans('vela::global.no_content_rows') }}</div>
        @endforelse
    </div>
</div>

<style>
.block-preview-rendered img { max-width: 100%; height: auto; border-radius: 4px; }
.block-preview-rendered iframe { max-width: 100%; border: none; }
.block-preview-rendered table { width: 100%; border-collapse: collapse; }
.block-preview-rendered table td, .block-preview-rendered table th { border: 1px solid #dee2e6; padding: 4px 8px; }
.block-preview-rendered blockquote { border-left: 3px solid #dee2e6; padding-left: 12px; color: #6c757d; }
.block-preview-rendered .preview-accordion-item { border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 4px; }
.block-preview-rendered .preview-accordion-header { padding: 8px 12px; background: #f8f9fa; font-weight: 600; cursor: default; }
.block-preview-rendered .preview-accordion-body { padding: 8px 12px; }
.block-preview-rendered .preview-contact-form { background: #f8f9fa; border-radius: 4px; padding: 12px; }
</style>

@endsection
