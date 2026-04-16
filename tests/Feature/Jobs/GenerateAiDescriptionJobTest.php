<?php

namespace VelaBuild\Core\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Jobs\GenerateAiDescriptionJob;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Tests\TestCase;

class GenerateAiDescriptionJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_job_generates_description_for_content_without_one(): void
    {
        $editorJsContent = json_encode([
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'data' => ['text' => str_repeat('Test content word ', 50)],
                ],
            ],
        ]);

        $content = Content::factory()->create([
            'description' => null,
            'content' => $editorJsContent,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'AI generated description for the article about test content.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        (new GenerateAiDescriptionJob($content->id))->handle();

        $content->refresh();
        $this->assertNotNull($content->description);
        $this->assertNotEmpty($content->description);
    }

    public function test_job_skips_when_description_already_exists(): void
    {
        $content = Content::factory()->create([
            'description' => 'Existing description',
        ]);

        // No HTTP calls expected — job returns early before calling the service
        Http::fake();

        (new GenerateAiDescriptionJob($content->id))->handle();

        $content->refresh();
        $this->assertEquals('Existing description', $content->description);
    }

    public function test_job_handles_missing_content_gracefully(): void
    {
        // Should not throw — job logs a warning and returns
        (new GenerateAiDescriptionJob(999999))->handle();
        $this->assertTrue(true);
    }

    public function test_job_skips_when_content_is_empty(): void
    {
        $content = Content::factory()->create([
            'description' => null,
            'content' => null,
        ]);

        // With no content, extractPlaintext returns '' and the job returns early
        Http::fake();

        (new GenerateAiDescriptionJob($content->id))->handle();

        $content->refresh();
        $this->assertNull($content->description);
    }
}
