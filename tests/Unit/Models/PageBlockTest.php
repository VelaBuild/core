<?php

namespace VelaBuild\Core\Tests\Unit\Models;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Tests\TestCase;

class PageBlockTest extends TestCase
{
    public function test_content_is_cast_to_array(): void
    {
        $page = Page::factory()->create();
        $row = PageRow::factory()->create(['page_id' => $page->id]);
        $block = PageBlock::factory()->create([
            'page_row_id' => $row->id,
            'content'     => ['text' => 'Hello world', 'align' => 'left'],
        ]);

        $fresh = PageBlock::find($block->id);

        $this->assertIsArray($fresh->content);
        $this->assertEquals('Hello world', $fresh->content['text']);
    }

    public function test_settings_is_cast_to_array(): void
    {
        $page = Page::factory()->create();
        $row = PageRow::factory()->create(['page_id' => $page->id]);
        $block = PageBlock::factory()->create([
            'page_row_id' => $row->id,
            'settings'    => ['bg_color' => '#ffffff', 'padding' => '20px'],
        ]);

        $fresh = PageBlock::find($block->id);

        $this->assertIsArray($fresh->settings);
        $this->assertEquals('#ffffff', $fresh->settings['bg_color']);
    }

    public function test_block_belongs_to_row(): void
    {
        $page = Page::factory()->create();
        $row = PageRow::factory()->create(['page_id' => $page->id]);
        $block = PageBlock::factory()->create(['page_row_id' => $row->id]);

        $block->load('row');

        $this->assertNotNull($block->row);
        $this->assertInstanceOf(PageRow::class, $block->row);
        $this->assertEquals($row->id, $block->row->id);
    }
}
