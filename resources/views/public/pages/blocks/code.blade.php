@php
    $content  = $block->content ?? [];
    $s        = \VelaBuild\Core\Services\Blocks\Code::settings($block->settings ?? []);
    $code     = (string) ($content['code'] ?? '');
    $filename = trim((string) ($content['filename'] ?? ''));
    $caption  = trim((string) ($content['caption'] ?? ''));

    // Line numbers are drawn here rather than by script, so they are there
    // with JavaScript off and never fall out of step with the code.
    $numbers  = $s['line_numbers'] && !$s['wrap'];
    $lines    = \VelaBuild\Core\Services\Blocks\Code::lineCount($code);
    $folds    = \VelaBuild\Core\Services\Blocks\Code::folds($s, $code);
    $hljs     = \VelaBuild\Core\Services\Blocks\Code::highlightName($s['language']);
    $language = \VelaBuild\Core\Services\Blocks\Code::label($s['language']);

    $classes = ['block-code', 'block-code--' . $s['theme']];
    if ($s['wrap']) {
        $classes[] = 'block-code--wrap';
    }
    if ($numbers) {
        $classes[] = 'block-code--numbered';
    }
    if ($folds) {
        $classes[] = 'block-code--folds';
        $classes[] = 'block-code--' . $s['max_height'];
    }
@endphp
@if($code !== '')
    <figure class="{{ implode(' ', $classes) }}"@if($hljs) data-code-highlight="{{ $hljs }}"@endif>
@if($filename !== '' || $s['show_copy'])
        <div class="block-code-head">
            <span class="block-code-filename">{{ $filename !== '' ? $filename : $language }}</span>
@if($s['show_copy'])
            {{-- The words travel with the button: the script that swaps them
                 runs on the visitor's side, where no translator is. --}}
            <button type="button" class="block-code-copy" data-code-copy
                    data-copy-label="{{ trans('vela::global.code_copy') }}"
                    data-copied-label="{{ trans('vela::global.code_copied') }}"
                    data-failed-label="{{ trans('vela::global.code_copy_failed') }}">
                <span class="block-code-copy-label">{{ trans('vela::global.code_copy') }}</span>
            </button>
@endif
        </div>
@endif
        <div class="block-code-scroll">
@if($numbers)
            <span class="block-code-gutter" aria-hidden="true">{!! implode("\n", range(1, $lines)) !!}</span>
@endif
            {{-- Focusable: a snippet wider than the screen scrolls, and a
                 scrolling box a keyboard cannot reach cannot be read at all. --}}
            <pre class="block-code-pre" tabindex="0" role="region" aria-label="{{ $filename !== '' ? $filename : $language }}"><code class="block-code-body language-{{ $s['language'] }}">{{ $code }}</code></pre>
        </div>
@if($folds)
        <button type="button" class="block-code-more" data-code-more
                data-more-label="{{ trans('vela::global.code_show_all', ['lines' => $lines]) }}"
                data-less-label="{{ trans('vela::global.code_show_less') }}"
                aria-expanded="false">{{ trans('vela::global.code_show_all', ['lines' => $lines]) }}</button>
@endif
@if($caption !== '')
        <figcaption class="block-code-caption">{{ $caption }}</figcaption>
@endif
    </figure>
@endif
