<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Registries\BlockRegistry;
use VelaBuild\Core\Registries\MenuRegistry;
use VelaBuild\Core\Registries\TemplateRegistry;
use VelaBuild\Core\Tests\TestCase;
use VelaBuild\Core\Vela;

class ExtensibilityTest extends TestCase
{
    public function test_registered_block_appears_in_registry(): void
    {
        $vela = app(Vela::class);

        $vela->registerBlock('test/custom-block', [
            'label' => 'Custom Block',
            'icon' => 'fa-star',
            'view' => 'vela::public.pages.blocks.text',
        ]);

        $blocks = $vela->getBlocks();
        $this->assertArrayHasKey('test/custom-block', $blocks);
        $this->assertEquals('Custom Block', $blocks['test/custom-block']['label']);
    }

    public function test_registered_menu_item_appears_in_registry(): void
    {
        $vela = app(Vela::class);

        $vela->registerMenuItem('test/custom-menu', [
            'label' => 'Custom Menu',
            'icon' => 'fa-circle',
            'route' => '#',
            'group' => 'general',
            'order' => 50,
        ]);

        $items = $vela->getMenuItems();
        $this->assertArrayHasKey('test/custom-menu', $items);
        $this->assertEquals('Custom Menu', $items['test/custom-menu']['label']);
    }

    public function test_registered_template_appears_in_registry(): void
    {
        $vela = app(Vela::class);

        $vela->registerTemplate('test/custom-template', [
            'label' => 'Custom Template',
            'namespace' => 'test-template',
            'path' => null,
        ]);

        $templates = $vela->getTemplates();
        $this->assertArrayHasKey('test/custom-template', $templates);
        $this->assertEquals('Custom Template', $templates['test/custom-template']['label']);
    }

    public function test_missing_block_type_shows_placeholder(): void
    {
        $page = Page::factory()->create(['status' => 'published', 'locale' => 'en']);
        $row = PageRow::factory()->create(['page_id' => $page->id]);
        PageBlock::factory()->create([
            'page_row_id' => $row->id,
            'type' => 'nonexistent_block_type_xyz',
            'content' => json_encode([]),
            'settings' => json_encode([]),
        ]);

        $response = $this->get('/' . $page->slug);
        $response->assertStatus(200);
        $response->assertSee("not available", false);
    }
}
