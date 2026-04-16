<?php

namespace VelaBuild\Core\Jobs;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\Idea;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateContentFromIdeaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ideaId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $ideaId)
    {
        $this->ideaId = $ideaId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $idea = Idea::findOrFail($this->ideaId);

            Log::info('Starting content generation for idea', [
                'idea_id' => $idea->id,
                'idea_title' => $idea->name
            ]);

            $aiManager = app(AiProviderManager::class);
            $textProvider = $aiManager->resolveTextProvider();

            // Generate content using AI
            $contentText = $this->generateContentWithAI($textProvider, $idea);

            if (!$contentText) {
                Log::error('Failed to generate content for idea', [
                    'idea_id' => $idea->id
                ]);
                return;
            }

            // Create the article in contents table
            $article = $this->createArticleFromIdea($idea, $contentText);

            // Update idea status to 'created'
            $idea->update(['status' => 'created']);

            Log::info('Successfully generated content for idea', [
                'idea_id' => $idea->id,
                'article_id' => $article->id,
                'article_title' => $article->title
            ]);

        } catch (\Exception $e) {
            Log::error('Content generation job failed', [
                'idea_id' => $this->ideaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Generate content using AI service (reused from IdeasController)
     */
    private function generateContentWithAI(AiTextProvider $textProvider, Idea $idea): ?string
    {
        $categoryContext = $idea->category ? " in the {$idea->category->name} category" : '';
        $siteContext = app(SiteContext::class);

        $prompt = "Generate a complete blog post content for the following idea{$categoryContext}:

Title: {$idea->name}
Description: {$idea->details}

CRITICAL: You must return ONLY markdown text with [IMAGE] tags. Do NOT return JSON, EditorJS, or any other format.

Requirements:
- Write a comprehensive blog post (800-2000 words)
- Start directly with the content (no title repetition)
- Use clear section headings with ## for main sections
- Use ### for subsections
- Include practical tips and insights
- Make it engaging and informative
- Write in a professional but accessible tone
- Include relevant examples and context
- Ensure content is SEO-friendly
- Focus on {$siteContext->getNiche()} themes relevant to {$siteContext->getName()}

Image Generation Tags:
Use this format for images: [IMAGE topic=\"detailed description of what the image should show\" alt=\"SEO optimized alt text\"]
- Place images at natural break points in the content
- Include a hero image near the beginning
- Add images that support the content sections
- Make topic descriptions specific and detailed
- Make alt text SEO-friendly and descriptive
- DO NOT use any other image format - only use the [IMAGE] tag format above
- DO NOT generate EditorJS format or any other image blocks

IMPORTANT: You must return the content in MARKDOWN format, NOT EditorJS format.

Format the output with proper markdown structure:
- Use ## for main section headings
- Use ### for subsection headings
- Use **bold** for emphasis
- Use bullet points with - for lists
- Use numbered lists with 1. 2. 3. for steps
- Include [IMAGE] tags where appropriate

CRITICAL: Return ONLY markdown text with [IMAGE] tags. Do NOT return JSON, EditorJS format, or wrap in code blocks.
Return ONLY the blog post content with proper markdown formatting and [IMAGE] tags.";

        $content = $textProvider->generateText($prompt, 2000, 0.7);

        if (!$content) {
            return null;
        }

        // Clean the content - remove any markdown code blocks if present
        $content = preg_replace('/^```(?:markdown)?\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        Log::info('Successfully generated content for idea', [
            'idea_id' => $idea->id,
            'idea_title' => $idea->name
        ]);

        return $content;
    }

    /**
     * Create article from idea and content (reused from IdeasController)
     */
    private function createArticleFromIdea(Idea $idea, string $contentText)
    {
        // Convert markdown to EditorJS format
        $editorJsContent = $this->convertTextToEditorJs($contentText);

        // Create the article
        $article = \VelaBuild\Core\Models\Content::create([
            'title' => $idea->name,
            'slug' => \Illuminate\Support\Str::slug($idea->name),
            'type' => 'post',
            'description' => $idea->details,
            'content' => $editorJsContent,
            'author_id' => 1, // Default author
            'status' => 'draft',
            'written_at' => now(),
        ]);

        // Attach categories if idea has them
        if ($idea->category) {
            $article->categories()->attach($idea->category->id);
        }

        return $article;
    }

    /**
     * Convert markdown text to EditorJS format (reused from IdeasController)
     */
    private function convertTextToEditorJs(string $text): string
    {
        $lines = explode("\n", $text);
        $blocks = [];
        $blockId = 1;
        $currentList = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                // End current list if exists
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }
                continue;
            }

            // Handle image tags - keep as raw text for background processing
            if (preg_match('/\[IMAGE\s+topic="([^"]+)"\s+alt="([^"]+)"\]/i', $line, $matches)) {
                // End current list if exists
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }

                // Keep the [IMAGE] tag as raw text in a paragraph block
                $blocks[] = [
                    'id' => 'paragraph-' . $blockId++,
                    'type' => 'paragraph',
                    'data' => [
                        'text' => $line // Keep the original [IMAGE] tag as text
                    ]
                ];
            }
            // Handle headings
            elseif (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                // End current list if exists
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }

                $level = strlen($matches[1]);
                $blocks[] = [
                    'id' => 'heading-' . $blockId++,
                    'type' => 'header',
                    'data' => [
                        'text' => $matches[2],
                        'level' => $level
                    ]
                ];
            }
            // Handle unordered lists
            elseif (preg_match('/^-\s+(.+)$/', $line, $matches)) {
                if (!$currentList || $currentList['type'] !== 'list') {
                    if ($currentList) {
                        $blocks[] = $currentList;
                    }
                    $currentList = [
                        'id' => 'list-' . $blockId++,
                        'type' => 'list',
                        'data' => [
                            'style' => 'unordered',
                            'items' => []
                        ]
                    ];
                }
                $currentList['data']['items'][] = $matches[1];
            }
            // Handle ordered lists
            elseif (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
                if (!$currentList || $currentList['type'] !== 'list' || $currentList['data']['style'] !== 'ordered') {
                    if ($currentList) {
                        $blocks[] = $currentList;
                    }
                    $currentList = [
                        'id' => 'list-' . $blockId++,
                        'type' => 'list',
                        'data' => [
                            'style' => 'ordered',
                            'items' => []
                        ]
                    ];
                }
                $currentList['data']['items'][] = $matches[1];
            }
            // Handle regular paragraphs
            else {
                // End current list if exists
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }

                $blocks[] = [
                    'id' => 'paragraph-' . $blockId++,
                    'type' => 'paragraph',
                    'data' => [
                        'text' => $line
                    ]
                ];
            }
        }

        // Add any remaining list
        if ($currentList) {
            $blocks[] = $currentList;
        }

        return json_encode([
            'time' => time() * 1000,
            'blocks' => $blocks
        ]);
    }
}
