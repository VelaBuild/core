<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Services\Translation\HtmlSegments;
use VelaBuild\Core\Services\TranslationStatusService;
use VelaBuild\Core\Services\Translator;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Translating a page used to create an empty row in the target language, and
 * since a page in the visitor's language wins over the source, every
 * "translated" page rendered blank. A translation now carries the source's
 * rows and blocks with only the prose translated.
 */
class PageTranslationTest extends PackageTestCase
{
    private FakeTranslatingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vela.static.enabled' => false]);
        $this->provider = new FakeTranslatingProvider();
        $this->mock(AiProviderManager::class, fn ($m) => $m->shouldReceive('resolveTextProvider')->andReturn($this->provider));
    }

    public function test_the_translation_carries_the_source_layout_with_only_prose_translated(): void
    {
        $source = $this->sourcePage();
        $locale = $this->targetLocale();

        $result = app(Translator::class)->translatePage($source, $locale);
        $this->assertTrue($result['ok'], (string) $result['error']);

        $copy = Page::where('locale', $locale)->where('parent_id', $source->id)->with('rows.blocks')->sole();
        $this->assertSame('pricing', $copy->slug);
        $this->assertSame('XX:Pricing', $copy->title);
        $this->assertSame('.tier { color: red; }', $copy->custom_css);
        $this->assertCount(2, $copy->rows);

        $html = $copy->rows[0]->blocks[0]->content['html'];
        $this->assertStringContainsString('<h2 class="title">HELLO <strong>WORLD</strong></h2>', $html);
        $this->assertStringContainsString('<p>READ THE <a href="/docs">DOCS</a>, THEN RUN <code>vela:install</code>.</p>', $html);
        $this->assertStringContainsString('<pre><code>php artisan vela:install</code></pre>', $html);
        $this->assertStringContainsString('<div class="src"><span class="kw">use</span> Vela\Core;</div>', $html);
        $this->assertStringContainsString('<script>var label = "Hello";</script>', $html);

        $cta = $copy->rows[1]->blocks[0]->content;
        $this->assertSame('GET STARTED', $cta['heading']);
        $this->assertSame('START FREE', $cta['primary_button_text']);
        $this->assertSame('/app/register', $cta['primary_button_url']);
        $this->assertSame('primary', $cta['type']);
        $this->assertSame('29', $cta['price']);

        // The source is untouched.
        $this->assertStringContainsString('<h2 class="title">Hello <strong>world</strong></h2>', $source->fresh()->rows[0]->blocks[0]->content['html']);
    }

    public function test_a_failed_translation_leaves_no_page_behind(): void
    {
        $source = $this->sourcePage();
        $locale = $this->targetLocale();
        $this->provider->failBody = true;   // the title translates, the body doesn't

        $result = app(Translator::class)->translatePage($source, $locale);

        $this->assertFalse($result['ok']);
        $this->assertFalse(Page::withTrashed()->where('locale', $locale)->where('slug', 'pricing')->exists());
    }

    public function test_translating_again_rebuilds_from_the_source(): void
    {
        $source = $this->sourcePage();
        $locale = $this->targetLocale();
        app(Translator::class)->translatePage($source, $locale);

        // The source changes shape: its CTA row is removed and the heading edited.
        $source->rows[1]->blocks()->delete();
        $source->rows[1]->delete();
        $block = $source->rows[0]->blocks[0];
        $block->content = ['html' => '<h2>Changed</h2>'];
        $block->save();

        $result = app(Translator::class)->translatePage($source->fresh(), $locale);
        $this->assertTrue($result['ok'], (string) $result['error']);

        $copies = Page::where('locale', $locale)->where('parent_id', $source->id)->with('rows.blocks')->get();
        $this->assertCount(1, $copies);
        $this->assertCount(1, $copies[0]->rows);
        $this->assertSame('<h2>CHANGED</h2>', $copies[0]->rows[0]->blocks[0]->content['html']);
        $this->assertSame(2, PageRow::count(), 'old translated rows are removed, not left orphaned');
    }

    public function test_a_translation_never_writes_over_the_source_config_file(): void
    {
        $source = $this->sourcePage();
        $locale = $this->targetLocale();
        app(Translator::class)->translatePage($source, $locale);
        $copy = Page::where('locale', $locale)->where('parent_id', $source->id)->sole();

        $dir = sys_get_temp_dir() . '/vela-static-' . uniqid();
        config(['vela.static.path' => $dir]);
        (new StaticSiteGenerator())->writeConfigJson($copy);

        $this->assertFileDoesNotExist($dir . '/pages/pricing/config.json');
    }

    public function test_an_out_of_date_translation_is_listed_as_missing(): void
    {
        $source = $this->sourcePage();
        $locale = $this->targetLocale();
        $status = app(TranslationStatusService::class);
        app(Translator::class)->translatePage($source, $locale);
        $this->assertNotContains($source->id, array_column($status->missing('pages', $locale), 'id'));

        $this->travel(5)->minutes();
        $source->touch();

        $this->assertContains($source->id, array_column($status->missing('pages', $locale), 'id'));
    }

    public function test_segments_skip_markup_code_and_numbers(): void
    {
        $html = '<ul><li>One <em>thing</em></li><li>42</li></ul><pre>keep me</pre><p translate="no">Brand line</p>';
        $texts = array_column(HtmlSegments::find($html), 'text');

        $this->assertSame(['One <em>thing</em>'], $texts);
        $this->assertSame(
            '<ul><li>Uno <em>cosa</em></li><li>42</li></ul><pre>keep me</pre><p translate="no">Brand line</p>',
            HtmlSegments::apply($html, ['One <em>thing</em>' => 'Uno <em>cosa</em>']),
        );
    }

    public function test_prose_detection_keeps_paths_and_identifiers_out(): void
    {
        $this->assertTrue(\VelaBuild\Core\Services\Translation\PageBodyTranslator::isProse('/ month'));
        $this->assertTrue(\VelaBuild\Core\Services\Translation\PageBodyTranslator::isProse('Monthly'));
        $this->assertTrue(\VelaBuild\Core\Services\Translation\PageBodyTranslator::isProse('forever', 'period'));
        $this->assertFalse(\VelaBuild\Core\Services\Translation\PageBodyTranslator::isProse('forever', 'variant'));
        foreach (['/docs', '#demo', 'https://vela.build', 'hello@vela.build', 'primary', 'pricing_tier', 'btn-xl primary', '.tier { color: red; }'] as $v) {
            $this->assertFalse(\VelaBuild\Core\Services\Translation\PageBodyTranslator::isProse($v), $v);
        }
    }

    private function sourcePage(): Page
    {
        $page = Page::create([
            'title' => 'Pricing', 'slug' => 'pricing', 'locale' => app(TranslationStatusService::class)->sourceLocale(),
            'status' => 'published', 'custom_css' => '.tier { color: red; }',
        ]);
        $row = PageRow::create(['page_id' => $page->id, 'order_column' => 1]);
        PageBlock::create([
            'page_row_id' => $row->id, 'column_index' => 0, 'order_column' => 1, 'type' => 'html',
            'content' => ['html' => '<section class="hero"><h2 class="title">Hello <strong>world</strong></h2>'
                . '<p>Read the <a href="/docs">docs</a>, then run <code>vela:install</code>.</p>'
                . '<pre><code>php artisan vela:install</code></pre>'
                . '<div class="src"><span class="kw">use</span> Vela\Core;</div>'
                . '<script>var label = "Hello";</script></section>'],
        ]);
        $row2 = PageRow::create(['page_id' => $page->id, 'order_column' => 2]);
        PageBlock::create([
            'page_row_id' => $row2->id, 'column_index' => 0, 'order_column' => 1, 'type' => 'cta',
            'content' => ['heading' => 'Get started', 'primary_button_text' => 'Start free',
                'primary_button_url' => '/app/register', 'type' => 'primary', 'price' => '29'],
        ]);

        return $page->fresh('rows.blocks');
    }

    private function targetLocale(): string
    {
        $locale = app(TranslationStatusService::class)->targetLocales()[0] ?? null;
        $this->assertNotNull($locale, 'the site must offer at least one other language');

        return $locale;
    }
}

/** "Translates" by upper-casing the words outside tags and ⟦n⟧ tokens. */
class FakeTranslatingProvider implements AiTextProvider
{
    public bool $fail = false;
    public bool $failBody = false;

    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        if ($this->fail) {
            return null;
        }
        if (preg_match('/Input:\n(\[.*\])\s*$/s', $prompt, $m)) {
            if ($this->failBody) {
                return 'Sorry, I cannot help with that.';
            }
            $in = json_decode($m[1], true);

            return json_encode(array_map(fn ($s) => preg_replace_callback(
                '~(<[^>]+>|⟦\d+⟧)|([^<⟦]+)~u',
                fn ($p) => $p[1] !== '' ? $p[1] : mb_strtoupper($p[2]),
                $s,
            ), $in), JSON_UNESCAPED_UNICODE);
        }
        if (preg_match('/Text:\n(.*)$/s', $prompt, $m)) {
            return 'XX:' . trim($m[1]);
        }

        return null;
    }

    public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array
    {
        return null;
    }

    public function supportsVision(): bool
    {
        return false;
    }
}
