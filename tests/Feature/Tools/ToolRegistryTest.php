<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Registries\ToolRegistry;
use VelaBuild\Core\Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_register_and_get_tool(): void
    {
        $registry = new ToolRegistry();
        $registry->register('test-tool', ['label' => 'Test', 'category' => 'testing']);

        $tool = $registry->get('test-tool');
        $this->assertEquals('Test', $tool['label']);
        $this->assertEquals('testing', $tool['category']);
    }

    public function test_has_tool(): void
    {
        $registry = new ToolRegistry();
        $this->assertFalse($registry->has('nonexistent'));
        $registry->register('exists', ['label' => 'Exists']);
        $this->assertTrue($registry->has('exists'));
    }

    public function test_all_returns_registered_tools(): void
    {
        $registry = new ToolRegistry();
        $registry->register('a', ['label' => 'A']);
        $registry->register('b', ['label' => 'B']);
        $this->assertCount(2, $registry->all());
    }

    public function test_categorized_groups_by_category(): void
    {
        $registry = new ToolRegistry();
        $registry->register('a', ['label' => 'A', 'category' => 'analytics']);
        $registry->register('b', ['label' => 'B', 'category' => 'seo']);
        $registry->register('c', ['label' => 'C', 'category' => 'analytics']);

        $grouped = $registry->categorized();
        $this->assertArrayHasKey('analytics', $grouped);
        $this->assertArrayHasKey('seo', $grouped);
        $this->assertCount(2, $grouped['analytics']);
    }

    public function test_duplicate_override_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn($msg) => str_contains($msg, 'dupe'));

        $registry = new ToolRegistry();
        $registry->register('dupe', ['label' => 'First']);
        $registry->register('dupe', ['label' => 'Second']);

        $this->assertEquals('Second', $registry->get('dupe')['label']);
    }
}
