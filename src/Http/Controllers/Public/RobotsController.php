<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;

class RobotsController extends Controller
{
    public function show()
    {
        $lines = [];
        $mode = config('vela.visibility.mode', 'public');
        $noindex = config('vela.visibility.noindex', false);
        $blockAi = config('vela.visibility.block_ai', false);

        if ($mode === 'restricted' && $noindex) {
            // Disallow all crawlers
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        if ($mode === 'restricted' && $blockAi) {
            // Block known AI training crawlers
            $aiCrawlers = [
                'GPTBot',
                'ChatGPT-User',
                'Google-Extended',
                'CCBot',
                'anthropic-ai',
                'ClaudeBot',
                'Claude-Web',
                'Bytespider',
                'Diffbot',
                'FacebookBot',
                'Applebot-Extended',
                'PerplexityBot',
                'Amazonbot',
                'Cohere-ai',
                'AI2Bot',
                'Scrapy',
                'img2dataset',
            ];

            foreach ($aiCrawlers as $bot) {
                $lines[] = "User-agent: {$bot}";
                $lines[] = 'Disallow: /';
                $lines[] = '';
            }
        }

        // Default: allow everything if no restrictions
        if (empty($lines)) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Allow: /';
            $lines[] = '';

            // Add sitemap if available
            $sitemapUrl = url('/sitemap.xml');
            $lines[] = "Sitemap: {$sitemapUrl}";
        }

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
