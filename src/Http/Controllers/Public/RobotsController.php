<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\VelaConfig;

class RobotsController extends Controller
{
    public function show()
    {
        $cached = public_path('robots.txt');
        if (is_file($cached)) {
            return response(file_get_contents($cached), 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($this->generate(), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function generate(): string
    {
        $lines = [];

        try {
            $settings = VelaConfig::whereIn('key', [
                'visibility_mode', 'visibility_noindex', 'visibility_block_ai',
                'content_signal_ai_train', 'content_signal_search', 'content_signal_ai_input',
            ])->pluck('value', 'key')->toArray();
        } catch (\Throwable $e) {
            $settings = [];
        }

        $mode = $settings['visibility_mode'] ?? config('vela.visibility.mode', 'public');
        $noindex = ($settings['visibility_noindex'] ?? '0') === '1' || config('vela.visibility.noindex', false);
        $blockAi = ($settings['visibility_block_ai'] ?? '0') === '1' || config('vela.visibility.block_ai', false);

        if ($mode === 'restricted' && $noindex) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        if ($mode === 'restricted' && $blockAi) {
            $aiCrawlers = [
                'GPTBot', 'ChatGPT-User', 'Google-Extended', 'CCBot',
                'anthropic-ai', 'ClaudeBot', 'Claude-Web', 'Bytespider',
                'Diffbot', 'FacebookBot', 'Applebot-Extended', 'PerplexityBot',
                'Amazonbot', 'Cohere-ai', 'AI2Bot', 'Scrapy', 'img2dataset',
            ];

            foreach ($aiCrawlers as $bot) {
                $lines[] = "User-agent: {$bot}";
                $lines[] = 'Disallow: /';
                $lines[] = '';
            }
        }

        if (empty($lines)) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . url('/sitemap.xml');
        }

        $aiTrain = $settings['content_signal_ai_train'] ?? 'no';
        $search = $settings['content_signal_search'] ?? 'yes';
        $aiInput = $settings['content_signal_ai_input'] ?? 'no';

        $lines[] = '';
        $lines[] = '# Content Signals (draft-romm-aipref-contentsignals)';
        $lines[] = "Content-Signal: ai-train={$aiTrain}, search={$search}, ai-input={$aiInput}";

        return implode("\n", $lines);
    }
}
