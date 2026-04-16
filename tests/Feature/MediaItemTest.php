<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;
use VelaBuild\Core\Models\MediaItem;
use VelaBuild\Core\Models\VelaUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaItemTest extends TestCase
{
    use DatabaseTransactions;

    public function test_media_item_can_be_created(): void
    {
        $item = MediaItem::factory()->create([
            'title' => 'Test Image',
            'alt_text' => 'Alt text here',
            'description' => 'A test description',
        ]);

        $this->assertDatabaseHas('vela_media_items', [
            'id' => $item->id,
            'title' => 'Test Image',
            'alt_text' => 'Alt text here',
        ]);
    }

    public function test_media_item_soft_deletes(): void
    {
        $item = MediaItem::factory()->create();
        $item->delete();

        $this->assertSoftDeleted('vela_media_items', ['id' => $item->id]);
    }

    public function test_media_item_has_uploaded_by_relationship(): void
    {
        $user = VelaUser::factory()->create();
        $item = MediaItem::factory()->create(['uploaded_by' => $user->id]);

        $this->assertEquals($user->id, $item->uploadedBy->id);
    }

    public function test_media_item_registers_media_conversions(): void
    {
        Storage::fake('public');
        $item = MediaItem::factory()->create();

        $item->addMedia(UploadedFile::fake()->image('test.jpg', 100, 100))
            ->toMediaCollection('media_library');

        $this->assertEquals(1, $item->getMedia('media_library')->count());
        $media = $item->getMedia('media_library')->first();
        $this->assertNotEmpty($media->getUrl());
    }
}
