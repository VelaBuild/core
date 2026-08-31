<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\Tools\AddDesignedSectionTool;
use VelaBuild\Core\Services\AiChat\Tools\ConvertSectionToBlockTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A design is built as written sections because that is what makes it look
 * like the design, and the cost is that its owner can reword a section but
 * never restructure it. Which sections a block could carry instead is not
 * knowable before they exist — asked to decide up front, one run wrote
 * everything as markup and the next chose blocks for everything and lost the
 * design's three-column grid. So it is decided here, on the section that was
 * actually built, and it is a trade rather than a free win: a block is painted
 * by the theme.
 */
class ConvertSectionToBlockTest extends PackageTestCase
{
    use RefreshDatabase;

    private function page(): Page
    {
        return Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'status' => 'unlisted',
            'locale' => config('vela.primary_language', 'en'),
        ]);
    }

    private function section(Page $page, string $name, string $html, string $css = ''): array
    {
        return (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => $name,
            'html' => $html,
            'css' => $css,
        ]);
    }

    public function test_a_written_hero_becomes_a_hero_block_with_its_words(): void
    {
        $page = $this->page();

        $written = $this->section($page, 'Hero',
            '<section class="hero"><h1>Ship faster</h1>'
            . '<p>Launch in days rather than quarters.</p>'
            . '<a href="/signup">Start free</a><a href="/demo">Book a demo</a></section>',
            '.hero{padding:96px 0}'
        );

        $result = (new ConvertSectionToBlockTool())->execute([
            'row_id' => $written['row_id'],
            'type' => 'hero',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $block = $page->rows()->first()->blocks()->first();

        $this->assertSame('hero', $block->type);
        $this->assertSame('Ship faster', $block->content['title']);
        $this->assertSame('Launch in days rather than quarters.', $block->content['subtitle']);
        $this->assertSame('Start free', $block->content['primary_button_text']);
        $this->assertSame('/demo', $block->content['secondary_button_url']);

        // The section's own stylesheet goes with it — a block is painted by
        // the theme, and rules under a wrapper nothing carries are dead weight.
        $this->assertStringNotContainsString('padding:96px', (string) $page->fresh()->custom_css);
    }

    public function test_a_written_faq_becomes_an_accordion(): void
    {
        $page = $this->page();

        $written = $this->section($page, 'FAQ',
            '<section class="faq">'
            . '<h3>What is Zercurity?</h3><p>A hosted service.</p>'
            . '<h3>Who is it for?</h3><p>Small teams and large ones.</p>'
            . '</section>'
        );

        $result = (new ConvertSectionToBlockTool())->execute([
            'row_id' => $written['row_id'],
            'type' => 'accordion',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $items = $page->rows()->first()->blocks()->first()->content['items'];

        $this->assertCount(2, $items);
        $this->assertSame('What is Zercurity?', $items[0]['title']);
        $this->assertSame('Small teams and large ones.', $items[1]['body']);
    }

    /**
     * Losing the design's sentences is worse than a section that cannot be
     * restructured, so the wording decides whether the trade is allowed.
     */
    public function test_it_refuses_rather_than_dropping_wording_the_block_cannot_hold(): void
    {
        $page = $this->page();

        $written = $this->section($page, 'Hero',
            '<section class="hero"><h1>Ship faster</h1>'
            . '<p>Launch in days rather than quarters.</p>'
            . '<p>No credit card needed.</p>'
            . '<p>Cancel whenever you like.</p></section>'
        );

        $result = (new ConvertSectionToBlockTool())->execute([
            'row_id' => $written['row_id'],
            'type' => 'hero',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertContains('No credit card needed.', $result['would_be_dropped']);
        // And the section is untouched.
        $this->assertSame('html', $page->rows()->first()->blocks()->first()->type);
    }

    /**
     * An accordion holds questions and answers and has nowhere to print the
     * heading standing over them. Taken as a pair it became a question with no
     * answer; dropped silently it would be a sentence of the design gone from
     * the page with nothing said.
     */
    public function test_a_sections_own_heading_is_reported_rather_than_lost(): void
    {
        $page = $this->page();

        $written = $this->section($page, 'FAQ',
            '<section class="faq"><h2>Frequently Asked Questions</h2>'
            . '<h3>What is it?</h3><p>A hosted service.</p>'
            . '<h3>Who is it for?</h3><p>Small teams.</p></section>'
        );

        $result = (new ConvertSectionToBlockTool())->execute([
            'row_id' => $written['row_id'],
            'type' => 'accordion',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(['Frequently Asked Questions'], $result['would_be_dropped']);

        // And with force, the questions still come out right — it is only the
        // heading above them that goes.
        $forced = (new ConvertSectionToBlockTool())->execute([
            'row_id' => $written['row_id'],
            'type' => 'accordion',
            'force' => true,
        ]);

        $this->assertTrue($forced['success'], $forced['error'] ?? '');
        $items = $page->rows()->first()->blocks()->first()->content['items'];
        $this->assertCount(2, $items);
        $this->assertSame('What is it?', $items[0]['title']);
    }

    public function test_a_block_whose_meaning_is_ambiguous_is_not_offered(): void
    {
        $page = $this->page();

        $written = $this->section($page, 'Pricing',
            '<section class="tiers"><h3>Starter</h3><p>$9</p><h3>Team</h3><p>$29</p></section>'
        );

        $result = (new ConvertSectionToBlockTool())->execute([
            'row_id' => $written['row_id'],
            'type' => 'pricing_tiers',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('cannot be filled', $result['error']);
    }

    public function test_a_row_that_is_not_a_written_section_is_refused(): void
    {
        $page = $this->page();
        $row = $page->rows()->create(['name' => 'Listing', 'width' => 'contained']);
        $row->blocks()->create(['type' => 'posts_grid', 'content' => [], 'column_index' => 0, 'column_width' => 12, 'order_column' => 0]);

        $result = (new ConvertSectionToBlockTool())->execute(['row_id' => $row->id, 'type' => 'hero']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('posts_grid', $result['error']);
    }

    /**
     * The conversion is a bet on how it will look, so losing it must cost
     * nothing: the markup and the stylesheet come back exactly.
     */
    public function test_undo_puts_the_written_section_back_exactly(): void
    {
        $page = $this->page();

        $written = $this->section($page, 'Hero',
            '<section class="hero"><h1>Ship faster</h1><p>Launch in days.</p></section>',
            '.hero{padding:96px 0}'
        );

        $before = $page->rows()->first()->blocks()->first()->content['html'];
        $cssBefore = (string) $page->fresh()->custom_css;

        $log = $this->actionLog();
        (new ConvertSectionToBlockTool())->execute(['row_id' => $written['row_id'], 'type' => 'hero'], $log);
        (new ConvertSectionToBlockTool())->undo($log->fresh());

        $block = $page->rows()->first()->blocks()->first();

        $this->assertSame('html', $block->type);
        $this->assertSame($before, $block->content['html']);
        $this->assertSame($cssBefore, (string) $page->fresh()->custom_css);
    }

    private function actionLog(): \VelaBuild\Core\Models\AiActionLog
    {
        $user = $this->signIn();
        $conversation = \VelaBuild\Core\Models\AiConversation::create(['user_id' => $user->id, 'title' => 'convert']);
        $message = \VelaBuild\Core\Models\AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
        ]);

        return \VelaBuild\Core\Models\AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_id' => $user->id,
            'tool_name' => 'convert_section_to_block',
            'parameters' => [],
            'status' => 'completed',
        ]);
    }
}
