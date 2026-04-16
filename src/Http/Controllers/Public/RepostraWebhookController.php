<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\ToolSettingsService;

class RepostraWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $settings = app(ToolSettingsService::class);
        $secret = $settings->get('repostra_webhook_secret');

        if (!$secret) {
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        // Verify webhook signature
        $signature = $request->header('X-Repostra-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!$signature || !hash_equals($expectedSignature, $signature)) {
            Log::warning('Repostra webhook: invalid signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = $request->json()->all();

        // Create content from webhook payload
        $defaultStatus = $settings->get('repostra_default_status', 'draft');
        $defaultAuthorId = $settings->get('repostra_default_author_id');

        $content = Content::create([
            'title' => $data['title'] ?? 'Imported Article',
            'type' => 'post',
            'description' => $data['description'] ?? $data['excerpt'] ?? '',
            'keyword' => 'repostra,imported',
            'content' => $data['content'] ?? $data['body'] ?? '',
            'status' => $defaultStatus,
            'author_id' => $defaultAuthorId,
            'published_at' => $defaultStatus === 'published' ? now() : null,
        ]);

        Log::info('Repostra webhook: content created', ['id' => $content->id, 'title' => $content->title]);

        return response()->json(['success' => true, 'id' => $content->id], 201);
    }
}
