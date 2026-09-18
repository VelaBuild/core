<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a code snippet puts on the page: the code as written, a gutter and a
 * fold drawn at render time, and nothing a setting could smuggle in.
 */
class CodeBlockRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    private function render(array $content, array $settings = []): string
    {
        $slug = 'code-check-' . uniqid();
        $page = Page::create(['title' => 'Docs', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0])->blocks()->create([
            'type' => 'code', 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function figure(string $html): string
    {
        preg_match('/<figure class="block-code[ "][^>]*>/', $html, $tag);

        return $tag[0] ?? '';
    }

    public function test_a_snippet_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $html = $this->render(['code' => "echo 'hi'", 'filename' => 'run.sh'], ['language' => 'bash', 'theme' => 'dark', 'show_copy' => true]);

        $this->assertSame('<figure class="block-code block-code--dark" data-code-highlight="bash">', $this->figure($html));
        $this->assertStringContainsString('<code class="block-code-body language-bash">echo &#039;hi&#039;</code>', $html);
        $this->assertStringContainsString('<span class="block-code-filename">run.sh</span>', $html);
        $this->assertStringContainsString('data-code-copy', $html);
        $this->assertStringNotContainsString('block-code-gutter', $html);
        $this->assertStringNotContainsString('block-code-more', $html);
    }

    public function test_a_setting_that_is_not_one_of_the_choices_is_dropped(): void
    {
        $html = $this->render(['code' => 'x'], [
            'theme' => 'dark" onload="alert(1)', 'language' => '../../etc', 'max_height' => ['short'],
            'line_numbers' => 'yes please', 'wrap' => 'no',
        ]);

        $this->assertSame('<figure class="block-code block-code--dark" data-code-highlight="bash">', $this->figure($html));
        $this->assertStringNotContainsString('onload', $html);
        $this->assertStringContainsString('language-bash', $html);
    }

    public function test_nothing_is_drawn_without_code(): void
    {
        // Not the whole page: the footer's loader script names the block too.
        $this->assertSame('', $this->figure($this->render(['code' => '', 'caption' => 'Empty'])));
    }

    public function test_the_gutter_counts_the_lines_and_wrapping_takes_it_away(): void
    {
        $code = "one\ntwo\nthree";

        $html = $this->render(['code' => $code], ['line_numbers' => true]);
        $this->assertStringContainsString('block-code--numbered', $this->figure($html));
        $this->assertStringContainsString("<span class=\"block-code-gutter\" aria-hidden=\"true\">1\n2\n3</span>", $html);

        // Numbers cannot line up with lines that wrap.
        $wrapped = $this->render(['code' => $code], ['line_numbers' => true, 'wrap' => true]);
        $this->assertStringContainsString('block-code--wrap', $this->figure($wrapped));
        $this->assertStringNotContainsString('block-code-gutter', $wrapped);
    }

    public function test_a_long_snippet_folds_and_a_short_one_does_not(): void
    {
        $long = implode("\n", array_fill(0, 20, 'line'));

        $html = $this->render(['code' => $long], ['max_height' => 'medium']);
        $this->assertStringContainsString('block-code--folds block-code--medium', $this->figure($html));
        $this->assertStringContainsString('Show all 20 lines', $html);

        $short = $this->render(['code' => "a\nb"], ['max_height' => 'medium']);
        $this->assertStringNotContainsString('block-code--folds', $short);
    }

    public function test_the_language_stands_in_for_a_missing_filename_and_names_the_scrolling_box(): void
    {
        $html = $this->render(['code' => 'SELECT 1'], ['language' => 'sql']);

        $this->assertStringContainsString('<span class="block-code-filename">SQL</span>', $html);
        $this->assertStringContainsString('<pre class="block-code-pre" tabindex="0" role="region" aria-label="SQL">', $html);
        $this->assertStringContainsString('data-code-highlight="sql"', $html);
    }

    public function test_plain_text_asks_for_no_highlighting(): void
    {
        $this->assertStringNotContainsString('data-code-highlight', $this->render(['code' => 'just words'], ['language' => 'text']));
    }
}
