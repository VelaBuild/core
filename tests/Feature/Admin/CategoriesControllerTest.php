<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class CategoriesControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'category_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/categories');
        $response->assertStatus(200);
    }

    public function test_store_creates_category(): void
    {
        Permission::firstOrCreate(['title' => 'category_create']);
        $this->loginAsAdmin();

        $name = 'Test Category ' . uniqid();

        $response = $this->post('/admin/categories', [
            'name' => $name,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_categories', ['name' => $name]);
    }

    public function test_update_category(): void
    {
        Permission::firstOrCreate(['title' => 'category_edit']);
        $this->loginAsAdmin();

        $category = Category::factory()->create(['name' => 'Old Category']);

        $response = $this->put('/admin/categories/' . $category->id, [
            'name' => 'New Category',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_categories', ['id' => $category->id, 'name' => 'New Category']);
    }

    public function test_destroy_category(): void
    {
        Permission::firstOrCreate(['title' => 'category_delete']);
        $this->loginAsAdmin();

        $category = Category::factory()->create();

        $response = $this->delete('/admin/categories/' . $category->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_categories', ['id' => $category->id]);
    }
}
