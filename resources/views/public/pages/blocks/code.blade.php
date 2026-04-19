@php
    $content  = $block->content ?? [];
    $settings = $block->settings ?? [];
    $code     = $content['code'] ?? '';
    $filename = $content['filename'] ?? '';
    $caption  = $content['caption'] ?? '';
    $language = $settings['language'] ?? 'text';
    $theme    = $settings['theme'] ?? 'dark';
    $showCopy = $settings['show_copy'] ?? true;
@endphp
@if($code !== '')
    <figure class="block-code block-code--{{ e($theme) }}">
@if($filename !== '' || $showCopy)
        <div class="block-code-head">
@if($filename !== '')
            <span class="block-code-filename">{{ e($filename) }}</span>
@endif
@if($showCopy)
            <button type="button" class="block-code-copy" data-code-copy aria-label="Copy code">
                <span class="block-code-copy-label">Copy</span>
            </button>
@endif
        </div>
@endif
        <pre class="block-code-pre"><code class="block-code-body language-{{ e($language) }}">{{ $code }}</code></pre>
@if($caption !== '')
        <figcaption class="block-code-caption">{{ e($caption) }}</figcaption>
@endif
    </figure>
@endif
