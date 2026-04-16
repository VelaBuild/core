<?php

namespace VelaBuild\Core\Tests\Unit\Registries;

use VelaBuild\Core\Registries\BlockRegistry;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class BlockRegistryTest extends TestCase
{
    private BlockRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new BlockRegistry();
    }

    public function test_can_register_block_type(): void
    {
        $this->registry->register('text', ['label' => 'Text', 'view' => 'vela::blocks.text']);

        $this->assertTrue($this->registry->has('text'));
    }

    public function test_can_retrieve_registered_block(): void
    {
        $config = ['label' => 'Text Block', 'icon' => 'fa-font', 'view' => 'vela::blocks.text'];
        $this->registry->register('text', $config);

        $block = $this->registry->get('text');

        $this->assertIsArray($block);
        $this->assertEquals('Text Block', $block['label']);
        $this->assertEquals('fa-font', $block['icon']);
        $this->assertEquals('vela::blocks.text', $block['view']);
    }

    public function test_override_block_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with("Vela: Block type 'text' is being overridden by a new registration.");

        $this->registry->register('text', ['label' => 'Text']);
        $this->registry->register('text', ['label' => 'Text Override']);
    }

    public function test_all_returns_all_blocks(): void
    {
        $this->registry->register('text', ['label' => 'Text']);
        $this->registry->register('image', ['label' => 'Image']);
        $this->registry->register('video', ['label' => 'Video']);

        $all = $this->registry->all();

        $this->assertCount(3, $all);
    }

    public function test_names_returns_block_names(): void
    {
        $this->registry->register('text', ['label' => 'Text']);
        $this->registry->register('image', ['label' => 'Image']);
        $this->registry->register('video', ['label' => 'Video']);

        $names = $this->registry->names();

        $this->assertEquals(['text', 'image', 'video'], $names);
    }

    public function test_unregistered_block_returns_null(): void
    {
        $result = $this->registry->get('nonexistent');

        $this->assertNull($result);
    }
}
