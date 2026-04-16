<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAiDescriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = [30, 60];

    protected int $contentId;

    public function __construct(int $contentId)
    {
        $this->contentId = $contentId;
    }

    public function handle(): void
    {
        $content = \VelaBuild\Core\Models\Content::find($this->contentId);
        if (!$content) {
            Log::warning('GenerateAiDescriptionJob: Content not found', ['id' => $this->contentId]);
            return;
        }

        // Re-check: only generate if description is still empty (race condition guard)
        if (!empty($content->description)) {
            return;
        }

        $service = new \VelaBuild\Core\Services\OpenAiTextService();

        // Extract plaintext from EditorJS content
        $plaintext = $this->extractPlaintext($content->content);
        if (empty($plaintext)) {
            return;
        }

        $description = $service->generateDescription($content->title, $plaintext);

        if ($description) {
            // Atomic update: only set description if still empty
            \VelaBuild\Core\Models\Content::where('id', $this->contentId)
                ->where(function ($q) {
                    $q->whereNull('description')->orWhere('description', '');
                })
                ->update(['description' => $description]);
        }
    }

    private function extractPlaintext(?string $editorJsJson): string
    {
        if (empty($editorJsJson)) return '';

        $data = json_decode($editorJsJson, true);
        if (!$data || !isset($data['blocks'])) {
            return strip_tags($editorJsJson); // fallback for non-JSON content
        }

        $texts = [];
        foreach ($data['blocks'] as $block) {
            if (isset($block['data']['text'])) {
                $texts[] = strip_tags($block['data']['text']);
            }
            if (isset($block['data']['items'])) {
                foreach ($block['data']['items'] as $item) {
                    $texts[] = strip_tags(is_string($item) ? $item : '');
                }
            }
        }
        return implode(' ', $texts);
    }
}
