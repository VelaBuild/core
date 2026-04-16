@foreach($page->rows as $row)
    @if($row->blocks->count() > 0)
    @php
        $rowStyle = '';
        if ($row->background_color) $rowStyle .= 'background-color:' . e($row->background_color) . ';';
        if ($row->background_image) $rowStyle .= 'background-image:url(' . e($row->background_image) . ');background-size:cover;background-position:center;';
    @endphp
    <div class="page-row-public {{ $row->css_class }}"@if($rowStyle) style="{{ $rowStyle }}"@endif>
        @php
            $columns = $row->blocks->groupBy('column_index');
            $gridFr = implode(' ', $columns->map(fn($blocks) => $blocks->first()->column_width . 'fr')->toArray());
        @endphp
        <div class="page-row-columns" style="grid-template-columns: {{ $gridFr }};">
            @foreach($columns as $colIndex => $blocks)
                <div class="page-column-public">
                    @foreach($blocks->sortBy('order_column') as $block)
                        @php
                            $blockStyle = '';
                            if ($block->background_color) $blockStyle .= 'background-color:' . e($block->background_color) . ';';
                            if ($block->background_image) $blockStyle .= 'background-image:url(' . e($block->background_image) . ');background-size:cover;background-position:center;';
                        @endphp
                        <div class="page-block-public"@if($blockStyle) style="{{ $blockStyle }}"@endif>
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
