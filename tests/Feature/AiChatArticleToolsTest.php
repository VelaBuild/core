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
