<?php

namespace VelaBuild\Core\Tests\Feature;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * vela_image() as theme templates actually call it.
 *
 * Generated themes passed the media item instead of its url — which went out
 * as a src made of the model's JSON — and put the loading keyword where the
 * attributes array goes, which was a TypeError and a 500 on the article page.
 */
class VelaImageCallShapesTest extends PackageTestCase
{
    public function test_a_media_item_is_read_as_its_url(): void
    {
        // What $post->main_image is: a media model with url set on it. No
        // file behind it — this is about the call, and a real upload here
        // pushed the full suite past its memory limit.
        $media = new Media();
        $media->forceFill(['id' => 7, 'model_type' => Content::class, 'model_id' => 1, 'uuid' => 'test', 'collection_name' => 'main_image', 'file_name' => 'picture.jpg', 'disk' => 'public', 'conversions_disk' => 'public', 'custom_properties' => [], 'generated_conversions' => [], 'manipulations' => [], 'responsive_images' => []]);
        $media->url = 'https://elsewhere.example/picture.jpg';
        $this->assertStringContainsString('model_type', (string) $media, 'a model made a string is its JSON — the old src');

        $html = vela_image($media, 'Pictured', [400]);

        $this->assertStringContainsString('src="https://elsewhere.example/picture.jpg"', $html);
        $this->assertStringNotContainsString('model_type', $html);
    }

    public function test_the_loading_keyword_given_in_place_of_the_attributes(): void
    {
        $url = 'https://elsewhere.example/picture.jpg';

        $this->assertSame(vela_image($url, 'A', [400], 'fit', [], 'eager'), vela_image($url, 'A', [400], '', 'eager'));
        $this->assertSame(vela_image($url, 'A', [400], 'fit', [], 'preload'), vela_image($url, 'A', [400], null, 'preload'));
        $this->assertSame(vela_image($url, 'A', [400]), vela_image($url, 'A', [400], null, null, null));
    }

    public function test_nothing_to_show_draws_nothing(): void
    {
        $this->assertSame('', vela_image(null, 'Missing'));
    }

    public function test_generated_themes_pass_the_url(): void
    {
        foreach (['ThemeSkeleton', 'ThemeAuthor'] as $class) {
            $source = file_get_contents(__DIR__ . "/../../src/Services/{$class}.php");
            $this->assertStringNotContainsString('vela_image($post->main_image,', $source, "{$class} still hands vela_image the media item");
        }
    }
}
