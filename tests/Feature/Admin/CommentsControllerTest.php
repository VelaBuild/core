<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Comment;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class CommentsControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'comment_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/comments');
        $response->assertStatus(200);
    }

    public function test_store_creates_comment(): void
    {
        Permission::firstOrCreate(['title' => 'comment_create']);
        $this->loginAsAdmin();

        $comment = 'Test comment ' . uniqid();

        $response = $this->post('/admin/comments', [
            'comment' => $comment,
            'status' => 'approved',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_comments', ['comment' => $comment]);
    }

    public function test_update_comment(): void
    {
        Permission::firstOrCreate(['title' => 'comment_edit']);
        $this->loginAsAdmin();

        $comment = Comment::factory()->create(['comment' => 'Old comment text']);

        $response = $this->put('/admin/comments/' . $comment->id, [
            'comment' => 'New comment text',
            'status' => $comment->status,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_comments', ['id' => $comment->id, 'comment' => 'New comment text']);
    }

    public function test_destroy_comment(): void
    {
        Permission::firstOrCreate(['title' => 'comment_delete']);
        $this->loginAsAdmin();

        $comment = Comment::factory()->create();

        $response = $this->delete('/admin/comments/' . $comment->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_comments', ['id' => $comment->id]);
    }

    public function test_mass_destroy_comments(): void
    {
        Permission::firstOrCreate(['title' => 'comment_delete']);
        $this->loginAsAdmin();

        $comments = Comment::factory()->count(2)->create();
        $ids = $comments->pluck('id')->toArray();

        $response = $this->delete('/admin/comments/destroy', ['ids' => $ids]);

        $response->assertStatus(204);
        foreach ($ids as $id) {
            $this->assertSoftDeleted('vela_comments', ['id' => $id]);
        }
    }
}
