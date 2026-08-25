@foreach($page->rows as $row)
@if($row->blocks->count() > 0)
@php
$rowStyle = '';
if ($row->background_color) $rowStyle .= 'background-color:' . e($row->background_color) . ';';
// A CSS background never passed through the optimiser, so a full-bleed row
// image was served at whatever size it was uploaded, in its original format,
// to phones and desktops alike — while every <img> on the same page got WebP
// and a size that fits. vela_image_url() closes that gap.
if ($row->background_image) $rowStyle .= 'background-image:url(' . e(vela_background_url($row->background_image)) . ');background-size:cover;background-position:center;';
// Also published as a custom property: a block whose container sets its own
// colour (.block-hero paints white over its overlay) beats a plain inherited
// `color`, so those blocks read this variable to know the author overrode it.
if ($row->text_color)       $rowStyle .= 'color:' . e($row->text_color) . ';--vela-text-color:' . e($row->text_color) . ';';
if ($row->text_alignment)   $rowStyle .= 'text-align:' . e($row->text_alignment) . ';';
// A single length is vertical space only. Written as the shorthand it would
// also set the sides, and an inline rule beats the stylesheet — so asking a
// full-width row for 40px of breathing room would hand its gutters back and
// pull the section in from the edges it was meant to reach.
// `!== ''`, not truthiness: "0" is a perfectly good answer to "how much
// space", and PHP reads that string as false — so choosing None in the row
// style left the template's 20px exactly where it was.
$rowPadding = trim((string) ($row->padding ?? ''));
// A copied section brings its own spacing. The template's 20px above and
// below is not breathing room around it, it is a band of page background
// between one section and the next.
// Matched on the wrapper class, which every imported section has carried
// from the first version — the block id came later, so looking for that
// alone missed every section copied before it.
$rowImported = $row->blocks->contains(
    fn ($b) => $b->type === 'html' && str_contains((string) ($b->content['html'] ?? ''), 'vela-import-')
);
if ($rowPadding === '' && $rowImported) {
    $rowPadding = '0';
}
if ($rowPadding !== '') {
    $rowStyle .= str_contains($rowPadding, ' ')
        ? 'padding:' . e($rowPadding) . ';'
        : 'padding-top:' . e($rowPadding) . ';padding-bottom:' . e($rowPadding) . ';';
}
$widthClass = ($row->width ?? 'contained') === 'full' ? 'row-full' : 'row-contained';
$columns    = $row->blocks->groupBy('column_index');
$gridFr     = implode(' ', $columns->map(fn($blocks) => $blocks->first()->column_width . 'fr')->toArray());
@endphp
<div id="row-{{ $row->id }}" class="page-row-public {{ $widthClass }} {{ $row->css_class }}"@if($rowStyle) style="{{ $rowStyle }}"@endif>
<div class="page-row-columns" style="grid-template-columns: {{ $gridFr }};">
@foreach($columns as $colIndex => $blocks)
<div class="page-column-public">
@foreach($blocks->sortBy('order_column') as $block)
@php
$blockStyle = '';
if ($block->background_color) $blockStyle .= 'background-color:' . e($block->background_color) . ';';
if ($block->background_image) $blockStyle .= 'background-image:url(' . e(vela_background_url($block->background_image)) . ');background-size:cover;background-position:center;';
if ($block->text_color)       $blockStyle .= 'color:' . e($block->text_color) . ';--vela-text-color:' . e($block->text_color) . ';';
if ($block->text_alignment)   $blockStyle .= 'text-align:' . e($block->text_alignment) . ';';
$blockPadding = trim((string) ($block->padding ?? ''));
if ($blockPadding !== '') {
    $blockStyle .= str_contains($blockPadding, ' ')
        ? 'padding:' . e($blockPadding) . ';'
        : 'padding-top:' . e($blockPadding) . ';padding-bottom:' . e($blockPadding) . ';';
}
// The 20px under every block is the other half of the seam.
$blockImported = $block->type === 'html'
    && str_contains((string) ($block->content['html'] ?? ''), 'vela-import-');
@endphp
<div id="block-{{ $block->id }}" class="page-block-public @if($blockImported)block-imported-section @endif"@if($blockStyle) style="{{ $blockStyle }}"@endif>
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
