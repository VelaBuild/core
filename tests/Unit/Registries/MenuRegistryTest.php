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

    public function test_active_label_matches_every_route_of_the_item(): void
    {
        $this->registry->register('dashboard', ['label' => 'Dashboard', 'route' => 'vela.admin.home']);
        $this->registry->register('pages', ['label' => 'Pages', 'route' => 'vela.admin.pages.index']);

        $this->assertSame('Pages', $this->registry->activeLabel('vela.admin.pages.index'));
        $this->assertSame('Pages', $this->registry->activeLabel('vela.admin.pages.edit'));
        $this->assertSame('Dashboard', $this->registry->activeLabel('vela.admin.home'));
    }

    public function test_active_label_prefers_the_most_specific_item(): void
    {
        $this->registry->register('tools', [
            'label' => 'Tools',
            'route' => 'vela.admin.tools.index',
            'children' => [
                'pagespeed' => ['label' => 'PageSpeed', 'route' => 'vela.admin.tools.pagespeed'],
            ],
        ]);

        $this->assertSame('PageSpeed', $this->registry->activeLabel('vela.admin.tools.pagespeed'));
        $this->assertSame('Tools', $this->registry->activeLabel('vela.admin.tools.cloudflare'));
    }

    public function test_active_label_is_null_when_nothing_matches(): void
    {
        $this->registry->register('pages', ['label' => 'Pages', 'route' => 'vela.admin.pages.index']);
        $this->registry->register('logout', ['label' => 'Logout', 'route' => '#logout']);

        $this->assertNull($this->registry->activeLabel('vela.admin.pagesx.index'));
        $this->assertNull($this->registry->activeLabel('#logout'));
        $this->assertNull($this->registry->activeLabel(null));
    }
}
