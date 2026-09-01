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

    public function test_a_written_block_comes_back_with_what_a_visitor_would_read(): void
    {
        // The prompt has told the model to check its work from the start and
        // it never has — not once in fifty conversations. So the tool looks,
        // and hands the answer back with the result.
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'hero',
            'content' => ['title' => 'Dive with us', 'subtitle' => 'Bangkok'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Dive with us', $result['visitor_sees']);
        $this->assertArrayNotHasKey('warning', $result);
    }

    public function test_a_block_that_renders_to_nothing_comes_back_as_a_warning(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'accordion',
            'content' => ['items' => []],
        ]);

        $this->assertArrayHasKey('warning', $result);
        $this->assertStringContainsString('blank gap', $result['warning']);
        $this->assertArrayNotHasKey('visitor_sees', $result);
    }

    public function test_a_block_showing_its_own_empty_state_is_caught(): void
    {
        // A block with a placeholder — "No testimonials yet" — has visible
        // words, so a blank check never fires while the stored content reached
        // nothing. Rendering it again with the content taken away tells them
        // apart: the same reading either way means the payload did nothing.
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'testimonials',
            'content' => ['items' => []],
        ]);

        $this->assertArrayHasKey('warning', $result);
        $this->assertStringContainsString('no content at all', $result['warning']);
    }

    public function test_testimonials_render_the_entries_the_schema_advertises(): void
    {
        // The view read content['testimonials'] while the block declares, and
        // list_block_types advertises, `items` — so the documented shape could
        // never render and the block always showed its empty state.
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'testimonials',
            'content' => ['items' => [['quote' => 'Best dive trip ever.', 'name' => 'Jane Doe', 'title' => 'Diver']]],
        ]);

        $this->assertStringContainsString('Best dive trip ever.', $result['visitor_sees']);
        $this->assertArrayNotHasKey('warning', $result);
    }

    public function test_a_block_made_of_pictures_is_not_mistaken_for_an_empty_one(): void
    {
        // Wordless is not the same as broken.
        $result = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'image',
            'content' => ['url' => 'https://example.com/reef.jpg', 'alt' => '', 'caption' => ''],
        ]);

        $this->assertArrayNotHasKey('warning', $result);
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

    public function test_every_heading_inside_a_block_controls_its_own_colour(): void
    {
        // A heading that states no colour inherits, and inheritance loses to any
        // rule aimed at the element — so a single site-wide `h1 { color: … }`
        // (hand-written, from a template, or generated by the chatbot) paints
        // hero titles black over their dark photo and silently disables the
        // block's text_color. Each heading class must therefore set a colour of
        // its own, even if that colour is `inherit`.
        $css = file_get_contents(__DIR__ . '/../../public/css/page-blocks.css');
        $missing = [];

        foreach (glob(__DIR__ . '/../../resources/views/public/pages/blocks/*.blade.php') as $view) {
            preg_match_all('/<h[1-6][^>]*class="([a-z0-9 _-]+)"/i', file_get_contents($view), $matches);

            foreach ($matches[1] as $classAttribute) {
                foreach (preg_split('/\s+/', trim($classAttribute)) as $class) {
                    if ($class === '') {
                        continue;
                    }

                    $declaresColour = preg_match_all('/\.' . preg_quote($class, '/') . '\s*(,[^{]*)?\{([^}]*)\}/', $css, $rules)
                        && collect($rules[2])->contains(fn ($body) => str_contains($body, 'color:'));

                    // Grouped selectors: the class may appear in a list.
                    $inGroup = preg_match('/[^{}]*\.' . preg_quote($class, '/') . '\s*[,{][^{}]*\{[^}]*color:\s*inherit/', $css);

                    if (!$declaresColour && !$inGroup) {
                        $missing[$class] = basename($view);
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Block headings with no colour of their own: ' . json_encode($missing));
    }

    public function test_a_block_that_paints_its_own_text_still_obeys_text_color(): void
    {
        // .block-hero sets white for text over its overlay. A class rule beats
        // an inherited colour, so unless it reads the custom property the row
        // and block text_color columns can never reach hero text.
        $blockCss = file_get_contents(__DIR__ . '/../../public/css/page-blocks.css');
        $rowsView = file_get_contents(__DIR__ . '/../../resources/views/templates/_partials/page-rows.blade.php');

        preg_match('/\.block-hero\s*\{([^}]*)\}/', $blockCss, $hero);
        $this->assertNotEmpty($hero, '.block-hero rule not found');
        $this->assertStringContainsString(
            'var(--vela-text-color',
            $hero[1],
            '.block-hero pins its own colour, so text_color would be ignored'
        );
        $this->assertStringContainsString('#fff', $hero[1], 'the white default over the overlay was dropped');

        // Both the row and the block must publish the property.
        $this->assertSame(
            2,
            substr_count($rowsView, '--vela-text-color:'),
            'text_color must publish --vela-text-color for rows and for blocks'
        );
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

    public function test_create_page_refuses_to_make_a_second_design_preview(): void
    {
        Page::create([
            'title'  => 'Design preview',
            'slug'   => \VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG,
            'status' => 'unlisted',
            'locale' => 'en',
        ]);

        // What a build did: asked for the address of the page it had already
        // been handed, and was quietly given "design-preview-1" to fill instead.
        foreach ([
            ['title' => 'Zercurity', 'slug' => 'design-preview'],
            ['title' => 'Zercurity', 'slug' => 'design-preview-1'],
            ['title' => 'Design Preview'],
        ] as $parameters) {
            $result = (new CreatePageTool())->execute($parameters);

            $this->assertArrayHasKey('error', $result, json_encode($parameters) . ' should be refused');
        }

        $this->assertSame(1, Page::where('slug', 'like', 'design-preview%')->count());
    }

    public function test_create_page_refuses_an_explicit_slug_that_is_taken(): void
    {
        Page::create(['title' => 'Pricing', 'slug' => 'pricing', 'status' => 'published', 'locale' => 'en']);

        // Silently moving it to "pricing-1" leaves the menu item the model
        // writes next pointing at somebody else's page.
        $result = (new CreatePageTool())->execute(['title' => 'Plans', 'slug' => 'pricing']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('pricing', $result['existing_slug']);
        $this->assertSame(0, Page::where('title', 'Plans')->count());
    }

    public function test_contact_form_rejects_a_button_label_written_under_an_invented_key(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'   => $this->makeRow()->id,
            'type'     => 'contact_form',
            'content'  => ['title' => 'Get in Touch'],
            // What a model reaches for; the view reads submit_label.
            'settings' => ['primary_button_text' => 'Send Message'],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains('primary_button_text', $result['unknown_keys']);
        $this->assertContains('submit_label', $result['valid_settings_keys']);
        $this->assertSame(0, PageBlock::count(), 'the rejected block must not reach the database');
    }

    public function test_contact_form_rejects_a_field_the_form_cannot_draw(): void
    {
        // The view draws five fixed inputs while the controller derives its
        // validation rules from this map, so an extra required entry would
        // reject every submission over a field nobody is shown.
        $result = (new AddBlockTool())->execute([
            'row_id'   => $this->makeRow()->id,
            'type'     => 'contact_form',
            'content'  => ['title' => 'Get in Touch'],
            'settings' => ['fields' => [
                'name'    => ['enabled' => true, 'required' => true],
                'company' => ['enabled' => true, 'required' => true],
            ]],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains('company', $result['unknown_entries']);
        $this->assertContains('message', $result['valid_entries']);
        $this->assertSame(0, PageBlock::count());
    }

    public function test_contact_form_keeps_the_heading_and_labels_it_was_given(): void
    {
        $result = (new AddBlockTool())->execute([
            'row_id'   => $this->makeRow()->id,
            'type'     => 'contact_form',
            'content'  => ['title' => 'Get in Touch', 'intro' => 'We reply within a day.'],
            'settings' => [
                'submit_label' => 'Send Message',
                'fields'       => ['phone' => ['enabled' => false, 'required' => false]],
            ],
        ]);

        $this->assertTrue($result['success']);
        $block = PageBlock::find($result['block_id']);
        $this->assertSame('Get in Touch', $block->content['title']);
        $this->assertSame('Send Message', $block->settings['submit_label']);
    }

    public function test_a_block_that_stores_rather_than_sends_says_so(): void
    {
        // The old shape advertised a recipient key, so the chatbot filled one
        // in and told the user their messages would be emailed there. Nothing
        // sends mail; submissions only land in the admin.
        $definition = app(Vela::class)->blocks()->all()['contact_form'];
        $this->assertArrayNotHasKey('email', $definition['defaults']['content']);

        $listed = collect((new ListBlockTypesTool())->execute([])['types'])
            ->firstWhere('type', 'contact_form');
        $this->assertNotEmpty($listed['note'] ?? null, 'list_block_types must pass the constraint on');

        $rejected = (new AddBlockTool())->execute([
            'row_id'  => $this->makeRow()->id,
            'type'    => 'contact_form',
            'content' => ['title' => 'Contact', 'email' => 'hello@example.com'],
        ]);

        $this->assertContains('email', $rejected['unknown_keys']);
        $this->assertNotEmpty($rejected['note'], 'the rejection must explain where submissions go');
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
