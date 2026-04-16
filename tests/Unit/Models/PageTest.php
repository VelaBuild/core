<?php

namespace VelaBuild\Core\Tests\Unit\Models;

use VelaBuild\Core\Models\FormSubmission;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Tests\TestCase;

class PageTest extends TestCase
{
    public function test_page_has_rows_relationship(): void
    {
        $page = Page::factory()->create();
        PageRow::factory()->count(3)->create(['page_id' => $page->id]);

        $page->load('rows');

        $this->assertCount(3, $page->rows);
        $this->assertInstanceOf(PageRow::class, $page->rows->first());
    }

    public function test_page_has_parent_relationship(): void
    {
        $parent = Page::factory()->create();
        $child = Page::factory()->create(['parent_id' => $parent->id]);

        $loaded = $child->load('parent');

        $this->assertNotNull($loaded->parent);
        $this->assertEquals($parent->id, $loaded->parent->id);
    }

    public function test_page_has_children_relationship(): void
    {
        $parent = Page::factory()->create();
        Page::factory()->count(2)->create(['parent_id' => $parent->id]);

        $parent->load('children');

        $this->assertCount(2, $parent->children);
        $this->assertInstanceOf(Page::class, $parent->children->first());
    }

    public function test_page_has_form_submissions_relationship(): void
    {
        $page = Page::factory()->create();
        FormSubmission::factory()->count(2)->create(['page_id' => $page->id]);

        $page->load('formSubmissions');

        $this->assertCount(2, $page->formSubmissions);
        $this->assertInstanceOf(FormSubmission::class, $page->formSubmissions->first());
    }

    public function test_page_uses_soft_deletes(): void
    {
        $page = Page::factory()->create();
        $id = $page->id;

        $page->delete();

        $this->assertNull(Page::find($id));
        $this->assertNotNull(Page::withTrashed()->find($id));
        $this->assertTrue(Page::withTrashed()->find($id)->trashed());
    }
}
