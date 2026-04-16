<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class PageControllerTest extends TestCase
{
    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/admin/pages');
        $response->assertRedirect(route('vela.auth.login'));
    }

    public function test_index_requires_page_access_permission(): void
    {
        $this->loginAsUser();

        $response = $this->get('/admin/pages');
        $response->assertStatus(403);
    }

    public function test_index_renders_for_authorized_user(): void
    {
        Permission::firstOrCreate(['title' => 'page_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/pages');
        $response->assertStatus(200);
    }

    public function test_create_page(): void
    {
        Permission::firstOrCreate(['title' => 'page_create']);
        $this->loginAsAdmin();

        $response = $this->post('/admin/pages', [
            'title' => 'Test Page',
            'slug' => 'test-page-' . uniqid(),
            'locale' => 'en',
            'status' => 'draft',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_pages', ['title' => 'Test Page']);
    }

    public function test_update_page(): void
    {
        Permission::firstOrCreate(['title' => 'page_edit']);
        $this->loginAsAdmin();

        $page = Page::factory()->create(['title' => 'Old Title']);

        $response = $this->put('/admin/pages/' . $page->id, [
            'title' => 'New Title',
            'slug' => $page->slug,
            'locale' => $page->locale,
            'status' => $page->status,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_pages', ['id' => $page->id, 'title' => 'New Title']);
    }

    public function test_delete_page(): void
    {
        Permission::firstOrCreate(['title' => 'page_delete']);
        $this->loginAsAdmin();

        $page = Page::factory()->create();

        $response = $this->delete('/admin/pages/' . $page->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_pages', ['id' => $page->id]);
    }

    public function test_mass_destroy_pages(): void
    {
        Permission::firstOrCreate(['title' => 'page_delete']);
        $this->loginAsAdmin();

        $pages = Page::factory()->count(3)->create();
        $ids = $pages->pluck('id')->toArray();

        $response = $this->delete('/admin/pages/destroy', ['ids' => $ids]);

        $response->assertStatus(204);
        foreach ($ids as $id) {
            $this->assertSoftDeleted('vela_pages', ['id' => $id]);
        }
    }
}
