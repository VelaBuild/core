<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\FormSubmission;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class FormSubmissionControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'form_submission_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/form-submissions');
        $response->assertStatus(200);
    }

    public function test_show_renders(): void
    {
        Permission::firstOrCreate(['title' => 'form_submission_show']);
        $this->loginAsAdmin();

        $page = Page::factory()->create();
        $submission = FormSubmission::factory()->create(['page_id' => $page->id]);

        $response = $this->get('/admin/form-submissions/' . $submission->id);
        $response->assertStatus(200);
    }

    public function test_destroy_submission(): void
    {
        Permission::firstOrCreate(['title' => 'form_submission_delete']);
        $this->loginAsAdmin();

        $page = Page::factory()->create();
        $submission = FormSubmission::factory()->create(['page_id' => $page->id]);

        $response = $this->delete('/admin/form-submissions/' . $submission->id);

        $response->assertRedirect();
        $this->assertDatabaseMissing('vela_form_submissions', ['id' => $submission->id]);
    }

    public function test_mass_destroy_submissions(): void
    {
        Permission::firstOrCreate(['title' => 'form_submission_delete']);
        $this->loginAsAdmin();

        $page = Page::factory()->create();
        $submissions = FormSubmission::factory()->count(2)->create(['page_id' => $page->id]);
        $ids = $submissions->pluck('id')->toArray();

        $response = $this->delete('/admin/form-submissions/destroy', ['ids' => $ids]);

        $response->assertStatus(204);
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('vela_form_submissions', ['id' => $id]);
        }
    }
}
