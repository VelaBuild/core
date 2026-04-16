<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Jobs\CreateContentImagesJob;

class CreateContent extends Command
{
    protected $signature = 'vela:create-content
                            {--title= : Content title}
                            {--prompt= : AI prompt to generate content (alternative to providing full content)}
                            {--category= : Category name or ID}

                            {--status=draft : Content status: draft, published, planned, scheduled}
                            {--with-images : Generate images for the content}
                            {--dry-run : Show what would be created without saving}';

    protected $description = 'Create content using AI generation';

    private AiProviderManager $aiManager;

    public function __construct(AiProviderManager $aiManager)
    {
        parent::__construct();
        $this->aiManager = $aiManager;
    }

    public function handle(): int
    {
        // 1. Validate AI provider available
        if (!$this->aiManager->hasTextProvider()) {
            $this->error('No AI text provider configured. Set OPENAI_API_KEY, ANTHROPIC_API_KEY, or GEMINI_API_KEY in .env');
            return 1;
        }

        // 2. Gather inputs (interactive prompts if flags not provided)
        $title = $this->option('title') ?? $this->ask('Content title');
        $prompt = $this->option('prompt') ?? $this->ask('AI prompt (describe the content you want)', $title);
        $status = $this->option('status');
        $categoryInput = $this->option('category');

        // 3. Resolve category (by name or ID)
        $categoryId = null;
        if ($categoryInput) {
            $category = is_numeric($categoryInput)
                ? Category::find($categoryInput)
                : Category::where('name', $categoryInput)->first();
            $categoryId = $category?->id;
        } elseif (!$this->option('title')) {
            // Interactive: let user choose category
            $categories = Category::pluck('name', 'id')->toArray();
            if (!empty($categories)) {
                $chosen = $this->choice('Select category', array_values($categories), 0);
                $categoryId = array_search($chosen, $categories);
            }
        }

        // 4. Generate content via AI
        $textProvider = $this->aiManager->resolveTextProvider();
        $siteContext = app(SiteContext::class);

        $fullPrompt = "Generate a complete blog post for {$siteContext->getDescription()}.\n\n"
            . "Title: {$title}\nInstructions: {$prompt}\n\n"
            . "Requirements:\n- 800-2000 words\n- Proper markdown with ## headings\n"
            . "- Include 2-4 [IMAGE topic=\"...\" alt=\"...\"] tags\n"
            . "- Return ONLY markdown text. No JSON or code blocks.";

        if ($this->option('dry-run')) {
            $this->info('Dry run - would generate content with prompt:');
            $this->line($fullPrompt);
            return 0;
        }

        $this->info('Generating content...');
        $contentText = $textProvider->generateText($fullPrompt, 2000, 0.7);

        if (!$contentText) {
            $this->error('Failed to generate content.');
            return 1;
        }

        // 5. Create Content record
        $slug = \Str::slug($title);
        $article = Content::create([
            'title' => $title,
            'slug' => $slug,
            'description' => \Str::limit($contentText, 160),
            'content' => $this->convertToEditorJs($contentText),
            'author_id' => 1,
            'status' => $status,
            'written_at' => now(),
        ]);

        if ($categoryId) {
            $article->categories()->attach($categoryId);
        }

        // 6. Optionally generate images
        if ($this->option('with-images')) {
            CreateContentImagesJob::dispatch($article);
            $this->info('Image generation queued.');
        }

        $this->info("Content created: ID {$article->id} - {$article->title}");

        // 7. Output JSON for CI/CD piping
        $this->line(json_encode(['id' => $article->id, 'title' => $article->title, 'slug' => $article->slug]));

        return 0;
    }

    private function convertToEditorJs(string $contentText): string
    {
        $lines = explode("\n", $contentText);
        $blocks = [];
        $blockId = 1;
        $currentList = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }
                continue;
            }

            // Handle image tags - keep as raw text for background processing
            if (preg_match('/\[IMAGE\s+topic="([^"]+)"\s+alt="([^"]+)"\]/i', $line, $matches)) {
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
            // Handle headings
            elseif (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
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
                        'level' => min($level, 6)
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
                $currentList['data']['items'][] = $this->processInlineFormatting($matches[1]);
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
                $currentList['data']['items'][] = $this->processInlineFormatting($matches[1]);
            }
            // Handle regular paragraphs
            else {
                if ($currentList) {
                    $blocks[] = $currentList;
                    $currentList = null;
                }

                $blocks[] = [
                    'id' => 'paragraph-' . $blockId++,
                    'type' => 'paragraph',
                    'data' => [
                        'text' => $this->processInlineFormatting($line)
                    ]
                ];
            }
        }

        // Add final list if exists
        if ($currentList) {
            $blocks[] = $currentList;
        }

        return json_encode([
            'time' => time() * 1000,
            'blocks' => $blocks
        ]);
    }

    private function processInlineFormatting(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        return $text;
    }
}
