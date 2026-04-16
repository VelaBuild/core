<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Tests\TestCase;

class TranslationsControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'translation_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/translations');
        $response->assertStatus(200);
    }

    public function test_store_creates_translation(): void
    {
        Permission::firstOrCreate(['title' => 'translation_create']);
        $this->loginAsAdmin();

        $key = 'test_key_' . uniqid();

        $response = $this->post('/admin/translations', [
            'lang_code' => 'en',
            'model_type' => 'content',
            'model_key' => $key,
            'translation' => 'Test translation value',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_translations', ['model_key' => $key]);
    }

    public function test_update_translation(): void
    {
        Permission::firstOrCreate(['title' => 'translation_edit']);
        $this->loginAsAdmin();

        $translation = Translation::factory()->create(['translation' => 'Old value']);

        $response = $this->put('/admin/translations/' . $translation->id, [
            'lang_code' => $translation->lang_code,
            'model_type' => $translation->model_type,
            'model_key' => $translation->model_key,
            'translation' => 'New value',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_translations', ['id' => $translation->id, 'translation' => 'New value']);
    }

    public function test_destroy_translation(): void
    {
        Permission::firstOrCreate(['title' => 'translation_delete']);
        $this->loginAsAdmin();

        $translation = Translation::factory()->create();

        $response = $this->delete('/admin/translations/' . $translation->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_translations', ['id' => $translation->id]);
    }

    public function test_mass_destroy_translations(): void
    {
        Permission::firstOrCreate(['title' => 'translation_delete']);
        $this->loginAsAdmin();

        $translations = Translation::factory()->count(2)->create();
        $ids = $translations->pluck('id')->toArray();

        $response = $this->delete('/admin/translations/destroy', ['ids' => $ids]);

        $response->assertStatus(204);
        foreach ($ids as $id) {
            $this->assertSoftDeleted('vela_translations', ['id' => $id]);
        }
    }
}
