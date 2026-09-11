<?php

namespace VelaBuild\Core\Tests\Feature\Public;

use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\FormSubmission;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Tests\TestCase;

class PageControllerTest extends TestCase
{
    public function test_published_page_renders(): void
    {
        $page = Page::factory()->create([
            'status' => 'published',
            'locale' => 'en',
        ]);

        $response = $this->get('/' . $page->slug);
        $response->assertStatus(200);
    }

    public function test_draft_page_returns_404(): void
    {
        $page = Page::factory()->create([
            'status' => 'draft',
            'locale' => 'en',
        ]);

        $response = $this->get('/' . $page->slug);
        $response->assertStatus(404);
    }

    public function test_form_submission_creates_record(): void
    {
        $page = Page::factory()->create(['status' => 'published', 'locale' => 'en']);
        $row = PageRow::factory()->create(['page_id' => $page->id]);
        $block = PageBlock::factory()->create([
            'page_row_id' => $row->id,
            'type' => 'contact_form',
            // The contact form reads its field config from `settings`, keyed
            // by field name — the same shape registerBlock() declares. Put in
            // `content` as a list, it was never seen, and the controller fell
            // back to its default five fields and refused the post for a
            // missing email.
            'content'  => ['title' => 'Contact', 'intro' => ''],
            'settings' => ['fields' => [
                'name'    => ['enabled' => true,  'required' => true],
                'email'   => ['enabled' => false, 'required' => false],
                'message' => ['enabled' => false, 'required' => false],
            ]],
        ]);

        $response = $this->post('/page-form/' . $page->id, [
            'block_id' => $block->id,
            'name' => 'Test User',
            'website_url' => '',
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseHas('vela_form_submissions', ['page_id' => $page->id]);
    }

    public function test_honeypot_rejects_bots(): void
    {
        $page = Page::factory()->create(['status' => 'published', 'locale' => 'en']);
        $row = PageRow::factory()->create(['page_id' => $page->id]);
        PageBlock::factory()->create([
            'page_row_id' => $row->id,
            'type' => 'contact_form',
            'content' => [],
            'settings' => [],
        ]);

        $response = $this->post('/page-form/' . $page->id, [
            'website_url' => 'http://spam.example.com',
        ]);

        $response->assertStatus(422);
    }

    public function test_posts_index_renders(): void
    {
        Content::factory()->create([
            'type' => 'post',
            'status' => 'published',
        ]);

        $response = $this->get('/posts');
        $response->assertStatus(200);
    }

    public function test_categories_index_renders(): void
    {
        Category::factory()->create();

        $response = $this->get('/categories');
        $response->assertStatus(200);
    }
}
