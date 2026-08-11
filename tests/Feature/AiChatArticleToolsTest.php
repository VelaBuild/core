<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\AiChat\Tools\CreateArticleTool;
use VelaBuild\Core\Services\AiChat\Tools\EditArticleContentTool;
use VelaBuild\Core\Services\AiChat\Tools\MarkdownToEditorJs;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Articles reach the database as markdown and leave it as EditorJS, and the
 * summary printed on the blog listing is derived along the way. These pin the
 * points where that derivation used to leak the source syntax to visitors.
 */
class AiChatArticleToolsTest extends PackageTestCase
{
    private const MARKDOWN = "# Introduction\nDiving is thrilling, but beginners repeat the same handful of mistakes.\n\n## 1. Skipping the check\nAlways inspect your gear.\n";

    public function test_the_listing_summary_is_prose_rather_than_the_markdown_source(): void
    {
        $result = (new CreateArticleTool())->execute([
            'title'   => 'Beginner Diving Mistakes',
            'content' => self::MARKDOWN,
        ]);

        $this->assertTrue($result['success']);

        $description = Content::find($result['article']['id'])->description;
        $this->assertStringStartsWith('Diving is thrilling', $description);
        $this->assertStringNotContainsString('#', $description, 'visitors read this verbatim on the blog listing');
    }

    public function test_rewriting_an_article_refreshes_the_summary_the_same_way(): void
    {
        $created = (new CreateArticleTool())->execute([
            'title'   => 'Gear Guide',
            'content' => self::MARKDOWN,
        ]);

        (new EditArticleContentTool())->execute([
            'article_id' => $created['article']['id'],
            'content'    => "## Updated\nA regulator is the one piece worth renting new.\n",
        ]);

        $this->assertSame(
            'A regulator is the one piece worth renting new.',
            Content::find($created['article']['id'])->description
        );
    }

    public function test_content_without_a_paragraph_still_yields_a_summary(): void
    {
        $excerpt = MarkdownToEditorJs::excerpt("## Checklist\n- Mask\n- Fins\n");

        $this->assertNotSame('', trim($excerpt));
        $this->assertStringNotContainsString('##', $excerpt);
    }

    public function test_a_picture_on_its_own_line_becomes_a_picture(): void
    {
        // It used to fall through to the paragraph branch, where the inline
        // link rule stranded the "!" in front of an anchor — visitors read
        // "!A diver checking gear" and saw no image at all.
        $document = json_decode(
            MarkdownToEditorJs::convert("## Gear\n\n![A diver checking gear](/images/dive.png)\n\nAfter.\n"),
            true
        );

        $image = collect($document['blocks'])->firstWhere('type', 'image');

        $this->assertNotNull($image, 'the markdown image must survive as an image block');
        $this->assertSame('/images/dive.png', $image['data']['file']['url']);
        $this->assertSame('A diver checking gear', $image['data']['caption']);
        $this->assertStringNotContainsString('!<a', json_encode($document));
    }

    public function test_an_invented_image_filename_is_refused(): void
    {
        // The model reaches for a name that describes the picture instead of
        // the url generate_image handed back, and the page renders a gap.
        $created = (new CreateArticleTool())->execute([
            'title'   => 'Gear Photos',
            'content' => "Intro paragraph.\n\n![A diver](/images/a-diver-checking-gear.png)\n",
        ]);

        $this->assertArrayHasKey('error', $created);
        $this->assertSame('/images/a-diver-checking-gear.png', $created['missing_image']);
        $this->assertSame(0, Content::where('title', 'Gear Photos')->count());
    }

    public function test_a_picture_hosted_elsewhere_is_left_alone(): void
    {
        // Only this site's own files are ours to vouch for.
        $result = (new CreateArticleTool())->execute([
            'title'   => 'Remote Photos',
            'content' => "Intro paragraph.\n\n![Reef](https://images.example.com/reef.jpg)\n",
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_an_article_keeps_the_body_it_was_written_with(): void
    {
        $result = (new CreateArticleTool())->execute([
            'title'   => 'Structure Check',
            'content' => self::MARKDOWN,
        ]);

        $document = json_decode(Content::find($result['article']['id'])->content, true);
        $types = array_column($document['blocks'] ?? [], 'type');

        $this->assertSame(['header', 'paragraph', 'header', 'paragraph'], $types);
    }
}
