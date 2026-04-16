<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use VelaBuild\Core\Contracts\AiImageProvider;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\MediaItem;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\TestCase;

class MediaLibraryControllerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create a user with a fresh role that has no permissions.
     * The User role (id=2) already has content_access and content_edit,
     * so loginAsUser() won't trigger 403 for those gates.
     */
    private function loginAsNoPermUser(): VelaUser
    {
        $user = VelaUser::factory()->create();
        $role = Role::create(['title' => 'no-perms-' . uniqid()]);
        // sync() replaces all roles (including the auto-attached default User role)
        $user->roles()->sync([$role->id]);
        $this->actingAs($user, 'vela');
        return $user;
    }

    // --- Permission Tests ---

    public function test_index_requires_content_access(): void
    {
        $this->loginAsNoPermUser();
        $response = $this->get(route('vela.admin.media.index'));
        $response->assertStatus(403);
    }

    public function test_index_renders_for_authorized_user(): void
    {
        $this->loginAsAdmin();
        $response = $this->get(route('vela.admin.media.index'));
        $response->assertStatus(200);
        $response->assertViewIs('vela::admin.media.index');
    }

    public function test_store_requires_content_edit(): void
    {
        $this->loginAsNoPermUser();
        $response = $this->postJson(route('vela.admin.media.store'), ['media_file' => 'test.jpg']);
        $response->assertStatus(403);
    }

    public function test_replace_requires_content_edit(): void
    {
        $this->loginAsNoPermUser();
        $response = $this->postJson(route('vela.admin.media.replace', ['id' => 1]));
        $response->assertStatus(403);
    }

    public function test_crop_requires_content_edit(): void
    {
        $this->loginAsNoPermUser();
        $response = $this->postJson(route('vela.admin.media.crop', ['id' => 1]));
        $response->assertStatus(403);
    }

    // --- Index JSON ---

    public function test_index_json_returns_media(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create();
        $mediaItem->addMedia(UploadedFile::fake()->image('test-index-'.uniqid().'.jpg', 100, 100))
            ->toMediaCollection('media_library');

        $response = $this->getJson(route('vela.admin.media.index').'?per_page=36', [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'next_cursor']);
    }

    public function test_index_json_cursor_pagination(): void
    {
        $this->loginAsAdmin();

        $createdIds = [];
        for ($i = 0; $i < 2; $i++) {
            $mi = MediaItem::factory()->create();
            $mi->addMedia(UploadedFile::fake()->image('paginate-'.uniqid().'.jpg', 50, 50))
                ->toMediaCollection('media_library');
            $createdIds[] = $mi->getMedia('media_library')->first()->id;
        }

        // Anchor cursor just above our created IDs so only our 2 records are returned
        $maxId = max($createdIds) + 1;

        $response = $this->getJson(route('vela.admin.media.index').'?per_page=1&cursor='.$maxId, [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['data']);
        $this->assertNotNull($data['next_cursor']);

        // Load next page
        $response2 = $this->getJson(route('vela.admin.media.index').'?per_page=1&cursor='.$data['next_cursor'], [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $response2->assertStatus(200);
        $this->assertNotEmpty($response2->json('data'));
    }

    // --- Store ---

    public function test_store_creates_media_item(): void
    {
        $this->loginAsAdmin();

        $tmpDir = storage_path('tmp/uploads');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $uniqueTitle = 'Test Upload '.uniqid();
        $tmpFile = 'test-upload-'.uniqid().'.jpg';
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $tmpDir.'/'.$tmpFile);
        imagedestroy($img);

        $response = $this->postJson(route('vela.admin.media.store'), [
            'media_file' => $tmpFile,
            'title' => $uniqueTitle,
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('vela_media_items', ['title' => $uniqueTitle]);
    }

    // --- Show ---

    public function test_show_returns_detail_with_used_in(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create(['title' => 'Show Test '.uniqid()]);
        $mediaItem->addMedia(UploadedFile::fake()->image('show-test-'.uniqid().'.jpg', 100, 100))
            ->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $response = $this->getJson(route('vela.admin.media.show', ['medium' => $media->id]));
        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'file_name', 'url', 'used_in']);
    }

    // --- Destroy ---

    public function test_destroy_soft_deletes_media_item(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create();
        $mediaItem->addMedia(UploadedFile::fake()->image('delete-test-'.uniqid().'.jpg', 50, 50))
            ->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $response = $this->deleteJson(route('vela.admin.media.destroy', ['medium' => $media->id]));
        $response->assertStatus(200);
        $this->assertSoftDeleted('vela_media_items', ['id' => $mediaItem->id]);
    }

    // --- Mass Destroy ---

    public function test_mass_destroy(): void
    {
        $this->loginAsAdmin();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $mi = MediaItem::factory()->create();
            $mi->addMedia(UploadedFile::fake()->image('mass-del-'.uniqid().'.jpg', 50, 50))
                ->toMediaCollection('media_library');
            $ids[] = $mi->getMedia('media_library')->first()->id;
        }

        $response = $this->deleteJson(route('vela.admin.media.massDestroy'), ['ids' => $ids]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
    }

    // --- Replace ---

    public function test_replace_updates_content_body_urls(): void
    {
        $this->loginAsAdmin();

        $mediaItem = MediaItem::factory()->create();
        $tmpPath = tempnam(sys_get_temp_dir(), 'replace').'.jpg';
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $tmpPath);
        imagedestroy($img);
        $mediaItem->addMedia($tmpPath)->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();
        $oldUrl = $media->getUrl();

        // Create Content referencing old URL
        $content = Content::factory()->create(['content' => json_encode(['url' => $oldUrl])]);

        // Create temp replacement file
        $tmpDir = storage_path('tmp/uploads');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $newFile = 'replacement-'.uniqid().'.jpg';
        $img2 = imagecreatetruecolor(100, 100);
        imagejpeg($img2, $tmpDir.'/'.$newFile);
        imagedestroy($img2);

        $response = $this->postJson(route('vela.admin.media.replace', ['id' => $media->id]), [
            'new_file' => $newFile,
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);

        $content->refresh();
        $this->assertStringNotContainsString($oldUrl, $content->content);
    }

    // --- Crop ---

    public function test_crop_preserves_original(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create();
        $tmpPath = tempnam(sys_get_temp_dir(), 'crop').'.jpg';
        $img = imagecreatetruecolor(200, 200);
        imagejpeg($img, $tmpPath);
        imagedestroy($img);
        $mediaItem->addMedia($tmpPath)->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $response = $this->postJson(route('vela.admin.media.crop', ['id' => $media->id]), [
            'x' => 0,
            'y' => 0,
            'width' => 100,
            'height' => 100,
            'updated_at' => $media->updated_at->format('Y-m-d H:i:s'),
        ]);
        $response->assertStatus(200);
        $media->refresh();
        $this->assertNotNull($media->getCustomProperty('original_file_path'));
        $this->assertFileExists($media->getCustomProperty('original_file_path'));
    }

    public function test_crop_returns_409_on_stale_updated_at(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create();
        $tmpPath = tempnam(sys_get_temp_dir(), 'crop409').'.jpg';
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $tmpPath);
        imagedestroy($img);
        $mediaItem->addMedia($tmpPath)->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $response = $this->postJson(route('vela.admin.media.crop', ['id' => $media->id]), [
            'x' => 0,
            'y' => 0,
            'width' => 50,
            'height' => 50,
            'updated_at' => '2020-01-01 00:00:00',
        ]);
        $response->assertStatus(409);
    }

    // --- AI Generate ---

    public function test_generate_ai_returns_400_without_provider(): void
    {
        $this->loginAsAdmin();
        // AiSettingsService reads env() directly, so config() changes don't help.
        // Mock AiProviderManager to simulate no image provider.
        $mock = $this->mock(AiProviderManager::class);
        $mock->shouldReceive('hasImageProvider')->andReturn(false);

        $response = $this->postJson(route('vela.admin.media.generateAi'), ['prompt' => 'a sunset']);
        $response->assertStatus(400);
    }

    // --- Cache ---

    public function test_regenerate_cache(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create();
        $mediaItem->addMedia(UploadedFile::fake()->image('cache-test-'.uniqid().'.jpg', 50, 50))
            ->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $response = $this->postJson(route('vela.admin.media.regenerateCache', ['id' => $media->id]));
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
    }

    public function test_clear_cache(): void
    {
        $this->loginAsAdmin();
        $mediaItem = MediaItem::factory()->create();
        $mediaItem->addMedia(UploadedFile::fake()->image('clear-cache-'.uniqid().'.jpg', 50, 50))
            ->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $response = $this->deleteJson(route('vela.admin.media.clearCache', ['id' => $media->id]));
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
    }

    // --- Update Meta ---

    public function test_update_meta(): void
    {
        $this->loginAsAdmin();
        $suffix = uniqid();
        $mediaItem = MediaItem::factory()->create(['title' => 'Old Title '.$suffix]);
        $mediaItem->addMedia(UploadedFile::fake()->image('meta-test-'.$suffix.'.jpg', 50, 50))
            ->toMediaCollection('media_library');
        $media = $mediaItem->getMedia('media_library')->first();

        $newTitle = 'New Title '.$suffix;
        $response = $this->postJson(route('vela.admin.media.updateMeta', ['id' => $media->id]), [
            'title' => $newTitle,
            'alt_text' => 'New Alt '.$suffix,
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
        $mediaItem->refresh();
        $this->assertEquals($newTitle, $mediaItem->title);
    }
}
