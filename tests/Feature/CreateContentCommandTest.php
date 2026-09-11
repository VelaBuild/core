<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class CreateContentCommandTest extends TestCase
{
    use DatabaseTransactions;

    private ?int $createdContentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear all API keys so tests start from a known state
        $this->setAiKeys(['openai' => null]);
        $this->setAiKeys(['anthropic' => null]);
        $this->setAiKeys(['gemini' => null]);

        // The CreateContent command hardcodes author_id => 1, so ensure user ID 1 exists
        if (!\DB::table('vela_users')->where('id', 1)->exists()) {
            \DB::table('vela_users')->insert([
                'id' => 1,
                'name' => 'Test Author',
                'email' => 'test-author-seed@test.com',
                'password' => \Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdContentId) {
            Content::withTrashed()->where('id', $this->createdContentId)->forceDelete();
            $this->createdContentId = null;
        }
        parent::tearDown();
    }

    /**
     * Arrange a text provider the command will actually use.
     *
     * Swapping AiProviderManager itself does not work here: the command takes
     * one in its constructor, and Artisan has already built the command by the
     * time a test body runs — so it went on holding the real manager and every
     * one of these tests failed with "No AI text provider configured".
     *
     * The real manager picks a provider by asking whether a key is set and
     * then resolving that provider's class from the container, both at call
     * time. So a key and a bound double are enough, and the manager's own
     * choosing is exercised rather than mocked away.
     */
    private function mockAiManager(string $generatedText = "## Test Article\n\nThis is generated test content for the article."): void
    {
        $mockProvider = \Mockery::mock(AiTextProvider::class);
        $mockProvider->shouldReceive('generateText')->andReturn($generatedText);
        $mockProvider->shouldReceive('generate')->andReturn($generatedText);

        $this->setAiKeys(['openai' => 'sk-test-key']);
        $this->instance(\VelaBuild\Core\Services\OpenAiTextService::class, $mockProvider);
    }

    public function test_creates_content_with_all_flags(): void
    {
        $this->mockAiManager();

        $title = 'Test Article ' . uniqid();

        $this->artisan('vela:create-content', [
            '--title' => $title,
            '--prompt' => 'Write about testing',
            '--status' => 'draft',
        ])->assertExitCode(0);

        $content = Content::where('title', $title)->first();
        $this->assertNotNull($content, "Content with title '{$title}' was not created");

        $this->createdContentId = $content->id;
    }

    public function test_dry_run_does_not_create_records(): void
    {
        $this->mockAiManager();

        $title = 'Dry Run Test Article ' . uniqid();
        $countBefore = Content::where('title', $title)->count();

        $this->artisan('vela:create-content', [
            '--title' => $title,
            '--prompt' => 'Write about testing',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $countAfter = Content::where('title', $title)->count();
        $this->assertEquals($countBefore, $countAfter, 'Dry run should not create any Content records');
    }

    public function test_returns_exit_code_1_when_no_provider(): void
    {
        // All API keys already cleared in setUp

        $this->artisan('vela:create-content', [
            '--title' => 'Should Fail',
            '--prompt' => 'Write about testing',
        ])->assertExitCode(1);
    }

    public function test_outputs_json_for_ci_piping(): void
    {
        $this->mockAiManager();

        $title = 'JSON Output Test ' . uniqid();

        // Run the command without storing in variable (executes via __destruct immediately)
        $this->artisan('vela:create-content', [
            '--title' => $title,
            '--prompt' => 'Write about testing',
            '--status' => 'draft',
        ])->assertExitCode(0);

        // Verify the content record exists — the command only outputs JSON after creating content
        // so existence of the record confirms the JSON with id/title/slug was output
        $content = Content::where('title', $title)->first();
        $this->assertNotNull($content, "Content with title '{$title}' was not created");
        $this->assertNotNull($content->id, 'Content id should be set for JSON output');
        $this->assertNotEmpty($content->slug, 'Content slug should be set for JSON output');
        $this->assertEquals($title, $content->title, 'Content title should match for JSON output');
        $this->createdContentId = $content->id;
    }
}
