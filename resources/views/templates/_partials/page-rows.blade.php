@php
// A row with no blocks draws nothing, so "has rows" is not the same question
// as "has anything to show". Both are asked here, once, because the answer is
// needed again at the foot of this file.
$__rowsWithSomethingIn = $page->rows->filter(fn ($r) => $r->blocks->count() > 0);

// Aliased here rather than imported: Blade compiles each @php block inline
// where it stands, and a `use` statement inside a loop body is a parse error.
$__tokens = \VelaBuild\Core\Services\DesignTokens::class;
@endphp
@foreach($__rowsWithSomethingIn as $row)
@php
$rowStyle = '';
// A colour here may be a literal the author picked, or a name from the
// theme's palette — `token:surface` — which resolves to the custom property
// behind it, so the row follows the site when the site is re-skinned.
// DesignTokens also drops anything that is not a colour at all: these values
// land in a `style` attribute, and `text_alignment` used to go in unchecked.
if ($c = $__tokens::colour($row->background_color)) $rowStyle .= 'background-color:' . e($c) . ';';
// A CSS background never passed through the optimiser, so a full-bleed row
// image was served at whatever size it was uploaded, in its original format,
// to phones and desktops alike — while every <img> on the same page got WebP
// and a size that fits. vela_image_url() closes that gap.
if ($row->background_image) $rowStyle .= 'background-image:url(' . e(vela_background_url($row->background_image)) . ');background-size:cover;background-position:center;';
// Also published as a custom property: a block whose container sets its own
// colour (.block-hero paints white over its overlay) beats a plain inherited
// `color`, so those blocks read this variable to know the author overrode it.
if ($c = $__tokens::colour($row->text_color)) $rowStyle .= 'color:' . e($c) . ';--vela-text-color:' . e($c) . ';';
if ($a = $__tokens::alignment($row->text_alignment)) $rowStyle .= 'text-align:' . $a . ';';
// A copied section brings its own spacing. The template's 20px above and
// below is not breathing room around it, it is a band of page background
// between one section and the next.
// Matched on the wrapper class, which every imported section has carried
// from the first version — the block id came later, so looking for that
// alone missed every section copied before it.
$rowImported = $row->blocks->contains(
    fn ($b) => $b->type === 'html' && str_contains((string) ($b->content['html'] ?? ''), 'vela-import-')
);
// `?? ''`, not truthiness: "0" is a perfectly good answer to "how much
// space", and PHP reads that string as false — so choosing None in the row
// style left the template's 20px exactly where it was.
$rowPadding = $__tokens::spacing($row->padding) ?? ($rowImported ? '0' : null);
if ($rowPadding !== null) {
    // A single length is vertical space only. Written as the shorthand it
    // would also set the sides, and an inline rule beats the stylesheet — so
    // asking a full-width row for 40px of breathing room would hand its
    // gutters back and pull the section in from the edges it was meant to
    // reach.
    $rowStyle .= str_contains($rowPadding, ' ')
        ? 'padding:' . $rowPadding . ';'
        : 'padding-top:' . $rowPadding . ';padding-bottom:' . $rowPadding . ';';
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
if ($c = $__tokens::colour($block->background_color)) $blockStyle .= 'background-color:' . e($c) . ';';
if ($block->background_image) $blockStyle .= 'background-image:url(' . e(vela_background_url($block->background_image)) . ');background-size:cover;background-position:center;';
if ($c = $__tokens::colour($block->text_color)) $blockStyle .= 'color:' . e($c) . ';--vela-text-color:' . e($c) . ';';
if ($a = $__tokens::alignment($block->text_alignment)) $blockStyle .= 'text-align:' . $a . ';';
$blockPadding = $__tokens::spacing($block->padding);
if ($blockPadding !== null) {
    $blockStyle .= str_contains($blockPadding, ' ')
        ? 'padding:' . $blockPadding . ';'
        : 'padding-top:' . $blockPadding . ';padding-bottom:' . $blockPadding . ';';
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
@endforeach

{{-- A page with nothing on it put the footer directly under the navigation,
     which reads as a broken theme rather than as an empty page — and it is
     the normal state of the About, Privacy, Terms and Contact pages every
     install ships, so it is the first thing somebody sees after switching
     theme. No theme has ever had a rule for it: none of the six shipped ones
     nor the skeleton gives the page area a minimum height.

     Fixed here rather than in seven layouts because every theme includes this
     partial, and inline rather than in page-blocks.css so it works on a site
     that has not republished the package's assets. --}}
@if($__rowsWithSomethingIn->isEmpty())
<div class="page-empty" style="min-height:42vh;display:flex;align-items:center;justify-content:center;padding:64px 20px;text-align:center;">
    @auth('vela')
        {{-- Only whoever can do something about it is told. A visitor gets the
             space and nothing else; a notice addressed to the owner, printed
             on the live site, is worse than the blank. --}}
        <div style="max-width:34em;opacity:.6;font-size:.95em;">
            {{ trans('vela::global.page_has_no_content') }}
        </div>
    @endauth
</div>
@endif
