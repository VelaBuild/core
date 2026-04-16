<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Idea;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class IdeasControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'idea_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/ideas');
        $response->assertStatus(200);
    }

    public function test_store_creates_idea(): void
    {
        Permission::firstOrCreate(['title' => 'idea_create']);
        $this->loginAsAdmin();

        $name = 'Test Idea ' . uniqid();

        $response = $this->post('/admin/ideas', [
            'name' => $name,
            'status' => 'new',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_ideas', ['name' => $name]);
    }

    public function test_update_idea(): void
    {
        Permission::firstOrCreate(['title' => 'idea_edit']);
        $this->loginAsAdmin();

        $idea = Idea::factory()->create(['name' => 'Old Idea Name']);

        $response = $this->put('/admin/ideas/' . $idea->id, [
            'name' => 'New Idea Name',
            'status' => $idea->status,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_ideas', ['id' => $idea->id, 'name' => 'New Idea Name']);
    }

    public function test_destroy_idea(): void
    {
        Permission::firstOrCreate(['title' => 'idea_delete']);
        $this->loginAsAdmin();

        $idea = Idea::factory()->create();

        $response = $this->delete('/admin/ideas/' . $idea->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_ideas', ['id' => $idea->id]);
    }

    public function test_generate_ai_returns_error_without_api_key(): void
    {
        Permission::firstOrCreate(['title' => 'idea_create']);
        $this->loginAsAdmin();

        config(['vela.ai.openai.api_key' => null]);

        $response = $this->postJson('/admin/ideas/generate-ai', [
            'topic' => 'test topic',
            'count' => 5,
        ]);

        // Should not return 500 — must be a graceful error response
        $this->assertNotEquals(500, $response->status(), 'Expected graceful error, not 500');
        $response->assertJson(['success' => false]);
    }
}
