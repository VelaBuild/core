@extends(vela_template_layout())

@section('title', $page->meta_title ?: $page->title)
@section('description', $page->meta_description ?: '')
@if($page->og_image)
    @section('og_image', $page->og_image->url)
@endif

@section('content')
<div class="page-content">
    @foreach($page->rows as $row)
        @if($row->blocks->count() > 0)
        <div class="page-row-public {{ $row->css_class }}">
            @php
                $columns = $row->blocks->groupBy('column_index');
                $gridFr = implode(' ', $columns->map(fn($blocks) => $blocks->first()->column_width . 'fr')->toArray());
            @endphp
            <div class="page-row-columns" style="grid-template-columns: {{ $gridFr }};">
                @foreach($columns as $colIndex => $blocks)
                    <div class="page-column-public">
                        @foreach($blocks->sortBy('order_column') as $block)
                            <div class="page-block-public">
                                @if(view()->exists('vela::public.pages.blocks.' . $block->type))
                                    @include('vela::public.pages.blocks.' . $block->type, ['block' => $block])
                                @elseif(app(\VelaBuild\Core\Vela::class)->blocks()->has($block->type))
                                    @php $blockConfig = app(\VelaBuild\Core\Vela::class)->blocks()->get($block->type); @endphp
                                    @include($blockConfig['view'], ['block' => $block])
                                @else
                                    <div class="alert alert-warning">{{ trans('vela::global.block_type_not_available', ['type' => $block->type]) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        @endif
    @endforeach
</div>

@if($page->custom_css)
<style>{!! $page->custom_css !!}</style>
@endif
@if($page->custom_js)
<script>{!! $page->custom_js !!}</script>
@endif
@endsection
