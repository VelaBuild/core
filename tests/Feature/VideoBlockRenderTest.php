<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a video block puts on the page, from its settings.
 *
 * The address itself is covered case by case in VideoEmbedTest; this is the
 * frame around it — the attributes a setting is useless without.
 */
class VideoBlockRenderTest extends PackageTestCase
{
    private function render(array $content, array $settings): string
    {
        $page = Page::create(['title' => 'Video', 'slug' => 'video-check', 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'video', 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/video-check')->assertOk()->getContent();
    }

    public function test_the_player_gets_the_address_its_settings_ask_for(): void
    {
        $html = $this->render(
            ['url' => 'https://youtu.be/RK3QuH9avVA?t=90', 'title' => 'If the bank fails'],
            ['autoplay' => true, 'loop' => true]
        );

        $this->assertStringContainsString(
            'src="https://www.youtube-nocookie.com/embed/RK3QuH9avVA?start=90&amp;autoplay=1&amp;mute=1&amp;playsinline=1&amp;loop=1&amp;playlist=RK3QuH9avVA"',
            $html
        );
        $this->assertStringContainsString('title="If the bank fails"', $html);
        // Asked for in the address and not allowed by the page, autoplay is refused anyway.
        $this->assertMatchesRegularExpression('/allow="[^"]*autoplay/', $html);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $html);
    }

    public function test_an_upright_video_is_held_to_a_phone_width(): void
    {
        $html = $this->render(['url' => 'https://youtube.com/shorts/aBcDeFgHiJk'], ['aspect_ratio' => '9:16']);

        $this->assertStringContainsString('max-width:400px', $html);
        $this->assertStringContainsString('padding-bottom:177.7778%', $html);
    }

    public function test_a_block_saved_before_the_settings_existed_still_plays(): void
    {
        $html = $this->render(['url' => 'https://www.youtube.com/watch?v=RK3QuH9avVA'], ['aspect_ratio' => '16:9']);

        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/RK3QuH9avVA"', $html);
        // And a frame with no title still says what it is.
        $this->assertMatchesRegularExpression('/<iframe[^>]+title="[^"]+"/', $html);
    }

    public function test_a_link_that_is_not_a_video_draws_nothing(): void
    {
        $html = $this->render(['url' => 'https://example.com/clip'], []);

        $this->assertStringNotContainsString('block-video', $html);
    }
}
