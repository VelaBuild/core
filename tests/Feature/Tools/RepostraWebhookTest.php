<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\ToolSettingsService;
use VelaBuild\Core\Tests\TestCase;

class RepostraWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_webhook_creates_content(): void
    {
        $settings = app(ToolSettingsService::class);
        $settings->set('repostra_webhook_secret', 'test-secret');
        $settings->set('repostra_default_status', 'draft');

        $payload = json_encode([
            'title' => 'Test Article from Repostra',
            'description' => 'A test description',
            'content' => 'Article body content.',
        ]);

        $signature = hash_hmac('sha256', $payload, 'test-secret');

        $response = $this->call(
            'POST',
            '/webhook/repostra',
            [],
            [],
            [],
            ['HTTP_X-Repostra-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['success', 'id']);

        $this->assertDatabaseHas('vela_articles', [
            'title' => 'Test Article from Repostra',
            'status' => 'draft',
        ]);
    }

    public function test_invalid_signature_rejected(): void
    {
        $settings = app(ToolSettingsService::class);
        $settings->set('repostra_webhook_secret', 'test-secret');

        $response = $this->postJson('/webhook/repostra', [
            'title' => 'Bad Actor',
        ], [
            'X-Repostra-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_without_config_returns_503(): void
    {
        // Ensure no secret is set
        $settings = app(ToolSettingsService::class);
        $settings->set('repostra_webhook_secret', null);

        $response = $this->postJson('/webhook/repostra', [
            'title' => 'No Config',
        ]);

        $response->assertStatus(503);
    }
}
