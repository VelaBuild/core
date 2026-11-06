<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The static generator (vela:generate-static, and every Vela Hosted import)
 * renders blocks from the console, where no session middleware has shared
 * `$errors`. The html block read it unguarded, so any page with one was left
 * out of the static cache and served stale.
 */
class HtmlBlockOutsideRequestTest extends PackageTestCase
{
    public function test_the_html_block_renders_without_a_web_request(): void
    {
        $block = new \VelaBuild\Core\Models\PageBlock(['type' => 'html', 'content' => ['html' => '<p>from the console</p>'], 'settings' => []]);

        $html = view('vela::public.pages.blocks.html', ['block' => $block])->render();

        $this->assertStringContainsString('from the console', $html);
    }
}
