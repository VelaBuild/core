<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiImageProvider;
use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AiWizardCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $createdContentIds = [];
    private array $createdConfigKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('vela.ai.openai.api_key', '');
        config()->set('vela.ai.anthropic.api_key', '');
        config()->set('vela.ai.gemini.api_key', '');

        // The CreateContent command hardcodes author_id => 1
        if (!\DB::table('vela_users')->where('id', 1)->exists()) {
            \DB::table('vela_users')->insert([
                'id' => 1,
                'name' => 'Test Author',
                'email' => 'test-author-wizard@test.com',
                'password' => \Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdContentIds as $id) {
            Content::withTrashed()->where('id', $id)->forceDelete();
        }
        foreach ($this->createdConfigKeys as $key) {
            VelaConfig::where('key', $key)->delete();
        }
        parent::tearDown();
    }

    private function mockAiManager(): void
    {
        $mockTextProvider = \Mockery::mock(AiTextProvider::class);
        $mockTextProvider->shouldReceive('generateText')
            ->andReturn("## Test Article\n\nThis is wizard-generated test content.");

        $mockImageProvider = \Mockery::mock(AiImageProvider::class);
        $mockImageProvider->shouldReceive('generateImage')
            ->andReturn(['data' => [['b64_json' => base64_encode('fake-image')]]]);
        $mockImageProvider->shouldReceive('saveBase64Image')
            ->andReturn('images/test.png');

        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(true);
        $mockManager->shouldReceive('hasImageProvider')->andReturn(true);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockTextProvider);
        $mockManager->shouldReceive('resolveImageProvider')->andReturn($mockImageProvider);

        $this->instance(AiProviderManager::class, $mockManager);
    }

    public function test_calls_sub_commands(): void
    {
        $this->mockAiManager();

        $uniqueColor = '#' . substr(md5(uniqid()), 0, 6);
        $wizardTitle = 'Wizard Test Article ' . uniqid();

        // Skip template, graphics, and categories to avoid dynamic category selection prompt.
        // Colors step calls vela:customize-template, content step calls vela:create-content.
        $this->artisan('vela:wizard', [
            '--skip' => 'template,graphics,categories',
        ])
            ->expectsQuestion('Primary color (hex)', $uniqueColor)
            ->expectsQuestion('How many articles to generate?', '1')
            ->expectsQuestion('Content title', $wizardTitle)
            ->assertExitCode(0);

        // Verify colors sub-command effect: VelaConfig should have css_--primary set
        $this->createdConfigKeys[] = 'css_--primary';
        $configRecord = VelaConfig::where('key', 'css_--primary')->first();
        $this->assertNotNull($configRecord, 'VelaConfig for css_--primary should be set by colors step');
        $this->assertEquals($uniqueColor, $configRecord->value);

        // Verify content sub-command effect: Content record created with wizard title
        $createdContent = Content::where('title', $wizardTitle)->first();
        $this->assertNotNull($createdContent, 'Content should be created by content step');
        $this->createdContentIds[] = $createdContent->id;
    }

    public function test_returns_exit_code_1_when_no_providers(): void
    {
        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('hasTextProvider')->andReturn(false);
        $mockManager->shouldReceive('hasImageProvider')->andReturn(false);
        $this->instance(AiProviderManager::class, $mockManager);

        $this->artisan('vela:wizard', [
            '--skip' => 'template,colors,graphics,categories,content',
        ])->assertExitCode(1);
    }
}
