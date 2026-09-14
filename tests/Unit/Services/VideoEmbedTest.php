<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use VelaBuild\Core\Services\VideoEmbed;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The player address a video block's link and settings produce.
 *
 * The cases live in tests/fixtures/video-embeds.json and are run by the
 * JavaScript test too: the page editor previews with its own copy of this, and
 * the previous two copies — one regex each — had drifted into both missing the
 * time in a shared link and neither recognising a Shorts link.
 */
class VideoEmbedTest extends PackageTestCase
{
    public static function cases(): array
    {
        $table = json_decode(file_get_contents(__DIR__ . '/../../fixtures/video-embeds.json'), true);
        $out = [];

        foreach ($table['cases'] as $case) {
            $out[$case['name']] = [$case['url'], $case['settings'], $case['embed']];
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_a_link_and_its_settings_become_the_player_address(string $url, array $settings, ?string $embed): void
    {
        $this->assertSame($embed, VideoEmbed::url($url, $settings));
    }

    public static function times(): array
    {
        $table = json_decode(file_get_contents(__DIR__ . '/../../fixtures/video-embeds.json'), true);
        $out = [];

        foreach ($table['times'] as [$typed, $seconds]) {
            $out['"' . $typed . '"'] = [$typed, $seconds];
        }

        return $out;
    }

    /**
     * Reported as "Start at and End at do not work": the times as typed were
     * not in a shape this read, and were saved as nothing without a word.
     */
    #[DataProvider('times')]
    public function test_a_time_is_read_the_ways_people_write_one(string $typed, ?int $seconds): void
    {
        $this->assertSame($seconds, VideoEmbed::seconds($typed));
    }

    public function test_a_tall_shape_is_held_to_a_width_a_screen_can_show(): void
    {
        $this->assertSame('177.7778%', VideoEmbed::padding(['aspect_ratio' => '9:16']));
        $this->assertSame('400px', VideoEmbed::maxWidth(['aspect_ratio' => '9:16']));
        $this->assertNull(VideoEmbed::maxWidth(['aspect_ratio' => '16:9']));
        // Something that is not a shape is the default shape.
        $this->assertSame('56.25%', VideoEmbed::padding(['aspect_ratio' => '100:1']));
    }
}
