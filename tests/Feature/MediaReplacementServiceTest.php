<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;
use VelaBuild\Core\Services\MediaReplacementService;
use VelaBuild\Core\Models\Content;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class MediaReplacementServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MediaReplacementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MediaReplacementService();
    }

    public function test_replaces_url_in_content_body(): void
    {
        $content = Content::factory()->create([
            'content' => '{"blocks":[{"type":"image","data":{"file":{"url":"/storage/99/old-image.jpg"}}}]}',
        ]);

        $affected = $this->service->replaceUrls('/storage/99/old-image.jpg', '/storage/100/new-image.jpg');

        $this->assertGreaterThanOrEqual(1, $affected);
        $content->refresh();
        $this->assertStringContainsString('/storage/100/new-image.jpg', $content->content);
        $this->assertStringNotContainsString('/storage/99/old-image.jpg', $content->content);
    }

    public function test_replaces_url_in_content_description(): void
    {
        $url = '/storage/desc-' . uniqid() . '/old-image.jpg';
        $newUrl = '/storage/desc-new-' . uniqid() . '/new-image.jpg';

        $content = Content::factory()->create([
            'description' => 'Image at ' . $url . ' is used here',
        ]);

        $this->service->replaceUrls($url, $newUrl);

        $content->refresh();
        $this->assertStringContainsString($newUrl, $content->description);
        $this->assertStringNotContainsString($url, $content->description);
    }

    public function test_no_false_positive_matches(): void
    {
        $content = Content::factory()->create([
            'content' => 'Has /storage/99/old-image.jpg and /storage/999/other-image.jpg',
        ]);

        $this->service->replaceUrls('/storage/99/old-image.jpg', '/storage/100/new-image.jpg');

        $content->refresh();
        $this->assertStringContainsString('/storage/999/other-image.jpg', $content->content);
        $this->assertStringContainsString('/storage/100/new-image.jpg', $content->content);
    }

    public function test_handles_no_matching_rows(): void
    {
        $result = $this->service->replaceUrls('/storage/nonexistent/file.jpg', '/storage/new/file.jpg');
        $this->assertEquals(0, $result);
    }

    public function test_count_references(): void
    {
        $url = '/storage/unique-test-' . uniqid() . '/file.jpg';
        Content::factory()->create(['content' => 'text with ' . $url . ' embedded']);

        $count = $this->service->countReferences($url);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_find_content_references(): void
    {
        $url = '/storage/unique-ref-' . uniqid() . '/file.jpg';
        $content = Content::factory()->create(['content' => 'text with ' . $url . ' embedded']);

        $refs = $this->service->findContentReferences($url);
        $this->assertTrue($refs->contains('id', $content->id));
    }
}
