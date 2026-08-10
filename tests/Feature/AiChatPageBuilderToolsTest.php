<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\Tools\AddBlockTool;
use VelaBuild\Core\Services\AiChat\Tools\CreatePageTool;
use VelaBuild\Core\Services\AiChat\Tools\ListBlockTypesTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdateBlockTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdatePageTool;
use VelaBuild\Core\Tests\PackageTestCase;
use VelaBuild\Core\Vela;

/**
 * These tools all share one failure mode: accepting a payload the renderer
 * never reads, so the page silently loses content while the chatbot reports
 * success to the user. Each test below pins one of those guards.
 */
class AiChatPageBuilderToolsTest extends PackageTestCase
{
    private ?PageRow $row = null;

    /** One row per test — several cases add blocks to it in turn. */
    private function makeRow(): PageRow
    {
        if ($this->row === null) {
            $page = Page::create([
                'title'  => 'Block Guard Fixture',
                'slug'   => 'block-guard-fixture',
                'status' => 'draft',
                'locale' => 'en',
            ]);

            $this->row = PageRow::create(['page_id' => $page->id, 'order_column' => 0]);
        }

        return $this->row;
    }

    public function test_add_block_rejects_content_keys_the_view_cannot_read(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'hero',
            // What a model reaches for; the view reads primary_button_text/url.
            'content' => ['title' => 'Hi', 'button' => ['text' => 'Go', 'link' => '/x']],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains('button', $result['unknown_keys']);
        $this->assertContains('primary_button_text', $result['valid_content_keys']);
        $this->assertSame(0, PageBlock::count(), 'the rejected block must not reach the database');
    }

    public function test_add_block_rejects_settings_keys_the_view_cannot_read(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'   => $this->makeRow()->id,
            'type'     => 'hero',
            'content'  => ['title' => 'Hi'],
            // A background image is a column, not a setting.
            'settings' => ['background_image_url' => 'https://example.com/a.png'],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains('background_image_url', $result['unknown_keys']);
    }

    public function test_add_block_stores_a_background_image_on_the_block(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'           => $this->makeRow()->id,
            'type'             => 'hero',
            'content'          => ['title' => 'Hi'],
            'background_image' => 'https://example.com/hero.png',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'https://example.com/hero.png',
            PageBlock::find($result['block_id'])->background_image
        );
    }

    public function test_valid_content_is_accepted_and_stored(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'cta',
            'content' => [
                'heading'             => 'Need a plumber?',
                'note'                => 'Available 24/7',
                'primary_button_text' => 'Call',
                'primary_button_url'  => 'tel:+6621234567',
            ],
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('Available 24/7', PageBlock::find($result['block_id'])->content['note']);
    }

    public function test_every_block_view_only_reads_keys_its_defaults_declare(): void
    {
        // The guards above validate against the registered defaults, so a view
        // reading an undeclared key would have valid writes rejected.
        $offenders = [];

        foreach (app(Vela::class)->blocks()->all() as $name => $definition) {
            $declared = $definition['defaults']['content'] ?? [];
            // The text block is EditorJS-backed and normalised before storage.
            if (!is_array($declared) || $declared === [] || $name === 'text' || empty($definition['view'])) {
                continue;
            }

            $source = file_get_contents(view()->getFinder()->find($definition['view']));
            preg_match_all('/\$content\[[\'"]([a-z_]+)[\'"]\]/', $source, $matches);

            $undeclared = array_diff(array_unique($matches[1]), array_keys($declared));
            if ($undeclared) {
                $offenders[$name] = array_values($undeclared);
            }
        }

        $this->assertSame([], $offenders, 'Block views read content keys missing from their registered defaults: ' . json_encode($offenders));
    }

    public function test_list_block_types_describes_the_shape_of_list_style_blocks(): void
    {
        // Without an example these advertise only {"items": []}, and the model
        // invents an item shape the view drops.
        $types = collect((new ListBlockTypesTool())->execute([])['types'])->keyBy('type');

        foreach (['icon_box', 'accordion', 'carousel', 'gallery', 'testimonials', 'pricing_tiers'] as $type) {
            $definition = $types[$type];
            $this->assertArrayHasKey('content_example', $definition, "{$type} has no content_example");

            $entries = reset($definition['content_example']);
            $this->assertNotEmpty($entries[0] ?? null, "{$type}'s example has no entry to copy");
        }
    }

    public function test_icon_box_entries_must_use_the_keys_the_view_reads(): void
    {
        // Top-level validation only sees `items`, so a made-up entry shape used
        // to pass and render as three empty boxes.
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'icon_box',
            'content' => ['items' => [['label' => 'Fast delivery', 'text' => 'Next day']]],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains('title', $result['valid_entry_keys']);
    }

    public function test_an_icon_that_is_not_a_font_awesome_class_is_rejected(): void
    {
        // "fast-delivery" and emoji both draw nothing: the view renders
        // <i class="..."></i> and relies on the icon font for the glyph.
        foreach (['fast-delivery', '🚀'] as $icon) {
            $result = (new AddBlockTool())->execute([
                'row_id'  => $this->makeRow()->id,
                'type'    => 'icon_box',
                'content' => ['items' => [['icon' => $icon, 'title' => 'Fast delivery']]],
            ]);

            $this->assertArrayHasKey('error', $result, "icon '{$icon}' should be rejected");
            $this->assertArrayHasKey('example_entry', $result);
        }

        $ok = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'icon_box',
            'content' => ['items' => [[
                'icon'        => 'fas fa-truck',
                'title'       => 'Fast delivery',
                'description' => 'Next-day across Bangkok.',
            ]]],
        ]);

        $this->assertTrue($ok['success'], $ok['error'] ?? '');
    }

    public function test_update_block_strips_stray_escaping_from_links(): void
    {
        $block = PageBlock::create([
            'page_row_id' => $this->makeRow()->id,
            'type'        => 'hero',
            'content'     => ['title' => 'Hi'],
        ]);

        (new UpdateBlockTool())->execute([
            'block_id' => $block->id,
            'content'  => ['title' => 'Hi', 'primary_button_url' => '\\/contact-us'],
        ]);

        $this->assertSame('/contact-us', $block->fresh()->content['primary_button_url']);
    }

    public function test_create_page_refuses_a_title_that_leaves_no_url(): void
    {
        // Thai, Chinese, Japanese, Korean and Hebrew all slugify to ''. Creating
        // the page anyway yields a record no visitor can ever open.
        foreach (['ติดต่อเรา', '联系我们', 'お問い合わせ'] as $title) {
            $result = (new CreatePageTool())->execute(['title' => $title]);

            $this->assertArrayHasKey('error', $result, "'{$title}' should not produce a page");
            $this->assertSame(0, Page::where('title', $title)->count());
        }
    }

    public function test_create_page_keeps_the_original_title_when_given_a_latin_slug(): void
    {
        $result = (new CreatePageTool())->execute(['title' => 'ติดต่อเรา', 'slug' => 'contact-us']);

        $this->assertTrue($result['success']);
        $this->assertSame('ติดต่อเรา', $result['page']['title']);
        $this->assertSame('contact-us', $result['page']['slug']);
    }

    public function test_update_page_writes_search_metadata_within_the_length_search_engines_show(): void
    {
        $page = Page::create(['title' => 'Services', 'slug' => 'seo-fixture', 'status' => 'published', 'locale' => 'en']);
        $tool = new UpdatePageTool();

        $tooLong = $tool->execute(['page_id' => $page->id, 'meta_description' => str_repeat('x', 200)]);
        $this->assertArrayHasKey('error', $tooLong);
        $this->assertNull($page->fresh()->meta_description);

        $ok = $tool->execute([
            'page_id'          => $page->id,
            'meta_title'       => 'Plumber Bangkok',
            'meta_description' => 'Licensed Bangkok plumbers for emergency repairs.',
        ]);

        $this->assertTrue($ok['success']);
        $this->assertSame('Plumber Bangkok', $page->fresh()->meta_title);
    }
}
