<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;
use VelaBuild\Core\Vela;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AppDownloadBlockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_block_is_registered()
    {
        $blocks = app(Vela::class)->blocks();
        $this->assertArrayHasKey('app_download', $blocks->all());
    }

    public function test_block_renders_with_store_urls()
    {
        VelaConfig::updateOrCreate(['key' => 'app_ios_url'], ['value' => 'https://apps.apple.com/app/test/id123']);
        VelaConfig::updateOrCreate(['key' => 'app_android_url'], ['value' => 'https://play.google.com/store/apps/details?id=com.test']);

        $html = view('vela::public.pages.blocks.app_download', [
            'block' => (object) [
                'content' => ['heading' => 'Get Our App', 'description' => 'Download now'],
                'settings' => ['text_alignment' => 'center'],
            ],
        ])->render();

        $this->assertStringContainsString('https://apps.apple.com/app/test/id123', $html);
        $this->assertStringContainsString('https://play.google.com/store/apps/details?id=com.test', $html);
        $this->assertStringContainsString('Get Our App', $html);
    }

    public function test_block_renders_empty_without_urls()
    {
        // Remove any existing URLs
        VelaConfig::where('key', 'app_ios_url')->delete();
        VelaConfig::where('key', 'app_android_url')->delete();

        $html = view('vela::public.pages.blocks.app_download', [
            'block' => (object) [
                'content' => ['heading' => 'Get Our App'],
                'settings' => [],
            ],
        ])->render();

        $this->assertStringNotContainsString('<a ', $html);
    }
}
