<?php

namespace VelaBuild\Core\Tests\Unit\Registries;

use VelaBuild\Core\Registries\MenuRegistry;
use VelaBuild\Core\Tests\TestCase;

class MenuRegistryTest extends TestCase
{
    private MenuRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new MenuRegistry();
    }

    public function test_can_register_menu_item(): void
    {
        $this->registry->register('dashboard', ['label' => 'Dashboard', 'route' => 'vela.admin.home']);

        $all = $this->registry->all();

        $this->assertArrayHasKey('dashboard', $all);
        $this->assertEquals('Dashboard', $all['dashboard']['label']);
    }

    public function test_grouped_returns_items_by_group(): void
    {
        $this->registry->register('dashboard', ['label' => 'Dashboard', 'group' => 'main']);
        $this->registry->register('users', ['label' => 'Users', 'group' => 'admin']);
        $this->registry->register('pages', ['label' => 'Pages', 'group' => 'main']);

        $grouped = $this->registry->grouped();

        $this->assertArrayHasKey('main', $grouped);
        $this->assertArrayHasKey('admin', $grouped);
        $this->assertArrayHasKey('dashboard', $grouped['main']);
        $this->assertArrayHasKey('pages', $grouped['main']);
        $this->assertArrayHasKey('users', $grouped['admin']);
    }

    public function test_grouped_sorts_by_order(): void
    {
        $this->registry->register('third', ['label' => 'Third', 'group' => 'main', 'order' => 30]);
        $this->registry->register('first', ['label' => 'First', 'group' => 'main', 'order' => 10]);
        $this->registry->register('second', ['label' => 'Second', 'group' => 'main', 'order' => 20]);

        $grouped = $this->registry->grouped();
        $keys = array_keys($grouped['main']);

        $this->assertEquals(['first', 'second', 'third'], $keys);
    }
}
