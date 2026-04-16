<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class ArticleControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'article_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/articles');
        $response->assertStatus(200);
    }

    public function test_store_creates_article(): void
    {
        Permission::firstOrCreate(['title' => 'article_create']);
        $this->loginAsAdmin();

        $title = 'Test Article ' . uniqid();

        $response = $this->post('/admin/articles', [
            'title' => $title,
            'status' => 'draft',
            'categories' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_articles', ['title' => $title]);
    }

    public function test_update_article(): void
    {
        Permission::firstOrCreate(['title' => 'article_edit']);
        $this->loginAsAdmin();

        $content = Content::factory()->create(['title' => 'Old Title']);

        $response = $this->put('/admin/articles/' . $content->id, [
            'title' => 'New Title',
            'status' => $content->status,
            'categories' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_articles', ['id' => $content->id, 'title' => 'New Title']);
    }

    public function test_destroy_article(): void
    {
        Permission::firstOrCreate(['title' => 'article_delete']);
        $this->loginAsAdmin();

        $content = Content::factory()->create();

        $response = $this->delete('/admin/articles/' . $content->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_articles', ['id' => $content->id]);
    }

    public function test_mass_publish(): void
    {
        Permission::firstOrCreate(['title' => 'article_edit']);
        $this->loginAsAdmin();

        $contents = Content::factory()->count(2)->create(['status' => 'draft']);
        $ids = $contents->pluck('id')->toArray();

        $response = $this->post('/admin/articles/mass-publish', ['ids' => $ids]);

        $response->assertSuccessful();
        foreach ($ids as $id) {
            $this->assertDatabaseHas('vela_articles', ['id' => $id, 'status' => 'published']);
        }
    }
}
