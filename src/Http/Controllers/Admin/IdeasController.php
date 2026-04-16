<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\CsvImportTrait;
use VelaBuild\Core\Http\Requests\MassDestroyIdeaRequest;
use VelaBuild\Core\Http\Requests\StoreIdeaRequest;
use VelaBuild\Core\Http\Requests\UpdateIdeaRequest;
use VelaBuild\Core\Jobs\GenerateContentFromIdeaJob;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Idea;
use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class IdeasController extends Controller
{
    use CsvImportTrait;

    public function index(Request $request)
    {
        abort_if(Gate::denies('idea_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = Idea::with('category')->select(sprintf('%s.*', (new Idea)->table));

            // Apply status filter
            if ($request->has('status') && $request->status != '') {
                if ($request->status === 'open') {
                    $query->whereIn('status', ['new', 'planned']);
                } else {
                    $query->where('status', $request->status);
                }
            } elseif ($request->has('cleared')) {
                // If filters were cleared, show all statuses
                // No status filter applied
            } else {
                // Default to 'open' status on initial page load (when no status parameter)
                $query->whereIn('status', ['new', 'planned']);
            }

            // Apply category filter
            if ($request->has('category') && $request->category != '') {
                $query->where('category_id', $request->category);
            }

            $table = Datatables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $editGate      = 'idea_edit';
                $deleteGate    = 'idea_delete';
                $crudRoutePart = 'ideas';

                return view('vela::partials.ideaActions', compact(
                    'editGate',
                    'deleteGate',
                    'crudRoutePart',
                    'row'
                ));
            });

            $table->editColumn('id', function ($row) {
                return $row->id ? $row->id : '';
            });
            $table->editColumn('name', function ($row) {
                return $row->name ? $row->name : '';
            });
            $table->editColumn('details', function ($row) {
                return $row->details ? $row->details : '';
            });
            $table->editColumn('status', function ($row) {
                return $row->status ? __('vela::global.status_' . $row->status) : '';
            });
            $table->editColumn('category', function ($row) {
                return $row->category ? $row->category->name : '';
            });

            $table->rawColumns(['actions', 'placeholder']);

            return $table->make(true);
        }

        return view('vela::admin.ideas.index');
    }

    public function create()
    {
        abort_if(Gate::denies('idea_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::admin.ideas.create');
    }

    public function store(StoreIdeaRequest $request)
    {
        $idea = Idea::create($request->all());

        return redirect()->route('vela.admin.ideas.index');
    }

    public function edit(Idea $idea)
    {
        abort_if(Gate::denies('idea_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::admin.ideas.edit', compact('idea'));
    }

    public function update(UpdateIdeaRequest $request, Idea $idea)
    {
        $idea->update($request->all());

        return redirect()->route('vela.admin.ideas.index');
    }

    public function show(Idea $idea)
    {
        abort_if(Gate::denies('idea_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::admin.ideas.show', compact('idea'));
    }

    public function destroy(Idea $idea)
    {
        abort_if(Gate::denies('idea_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $idea->update(['status' => 'reject']);

        return back();
    }

    public function massDestroy(MassDestroyIdeaRequest $request)
    {
        $ideas = Idea::find(request('ids'));

        foreach ($ideas as $idea) {
            $idea->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Generate AI ideas
     */
    public function generateIdeas(Request $request, AiProviderManager $aiManager)
    {
        abort_if(Gate::denies('idea_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'topic' => 'nullable|string|max:255',
            'keyword' => 'nullable|string|max:1000',
            'count' => 'integer|min:1|max:50',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:vela_categories,id'
        ]);

        $topic = $request->input('topic');
        $keyword = $request->input('keyword');
        $count = $request->input('count', 20);
        $categories = $request->input('categories', []);

        try {
            $textProvider = $aiManager->resolveTextProvider();
            $ideas = $this->generateIdeasWithAI($textProvider, $topic, $keyword, $count, $categories);

            if (!$ideas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate ideas. Please try again.'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'ideas' => $ideas
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating ideas.'
            ], 500);
        }
    }

    /**
     * Generate ideas using AI service
     */
    private function generateIdeasWithAI(AiTextProvider $textProvider, ?string $topic = null, ?string $keyword = null, int $count = 20, array $categories = []): ?array
    {
        $topicText = $topic ? " focused on '{$topic}'" : '';
        $keywordText = $keyword ? " using keywords from this list: {$keyword}. Each idea must use exactly ONE keyword from this list." : '';

        // Get category names if categories are provided
        $categoryNames = [];
        if (!empty($categories)) {
            $categoryNames = Category::whereIn('id', $categories)->pluck('name')->toArray();
        }

        $categoryText = !empty($categoryNames) ? " related to these categories: " . implode(', ', $categoryNames) : '';

        $siteContext = app(SiteContext::class);
        $prompt = "Generate {$count} creative content ideas for {$siteContext->getDescription()}{$topicText}{$keywordText}{$categoryText}.

Each idea should be:
- SEO-friendly and shareable
- Between 5-15 words for the title
- Include a brief description (1-2 sentences)
- Include a suggested category from the available categories: " . implode(', ', Category::pluck('name')->toArray()) . "
- Include exactly ONE keyword from the provided keyword list for SEO
- Each idea must use a different keyword from the list
- Distribute the keywords evenly across all ideas

Return ONLY a valid JSON array with objects containing 'title', 'description', 'category', and 'keyword' fields. Do not wrap in markdown code blocks.

Example format:
[
  {
    \"title\": \"Best Dive Sites for Beginners in Southeast Asia\",";

        $content = $textProvider->generateText($prompt, 2000, 0.8);

        if (!$content) {
            return null;
        }

        // Clean the content - remove markdown code blocks if present
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        // Try to parse JSON response
        $ideas = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::error('Failed to parse AI-generated ideas JSON', [
                'content' => $content,
                'json_error' => json_last_error_msg()
            ]);
            return null;
        }

        // Ensure we have an array of ideas
        if (!is_array($ideas)) {
            \Log::error('AI response is not an array', ['content' => $content]);
            return null;
        }

        // Limit to requested count
        $ideas = array_slice($ideas, 0, $count);

        \Log::info('Successfully generated ideas', [
            'count' => count($ideas),
            'topic' => $topic
        ]);

        return $ideas;
    }

    /**
     * Save selected AI ideas
     */
    public function saveIdeas(Request $request)
    {
        abort_if(Gate::denies('idea_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'ideas' => 'required|array',
            'ideas.*.title' => 'required|string|max:255',
            'ideas.*.description' => 'required|string|max:1000',
            'ideas.*.category' => 'nullable|string|max:255',
            'ideas.*.keyword' => 'nullable|string|max:255'
        ]);

        $savedIdeas = [];
        $errors = [];

        foreach ($request->input('ideas') as $ideaData) {
            try {
                // Find category by name if provided
                $categoryId = null;
                if (!empty($ideaData['category'])) {
                    $category = Category::where('name', $ideaData['category'])->first();
                    $categoryId = $category ? $category->id : null;
                }

                $idea = Idea::create([
                    'name' => $ideaData['title'],
                    'details' => $ideaData['description'],
                    'keyword' => $ideaData['keyword'] ?? null,
                    'category_id' => $categoryId,
                    'status' => 'new'
                ]);
                $savedIdeas[] = $idea;
            } catch (\Exception $e) {
                $errors[] = "Failed to save idea: {$ideaData['title']}";
            }
        }

        return response()->json([
            'success' => true,
            'saved_count' => count($savedIdeas),
            'errors' => $errors,
            'message' => count($savedIdeas) . ' ideas saved successfully.'
        ]);
    }

    /**
     * Generate content for an idea using AI
     */
    public function generateContent(Request $request, AiProviderManager $aiManager)
    {
        abort_if(Gate::denies('idea_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'idea_id' => 'required|integer|exists:vela_ideas,id'
        ]);

        $idea = Idea::findOrFail($request->input('idea_id'));

        try {
            // Generate content using AI
            $textProvider = $aiManager->resolveTextProvider();
            $contentText = $this->generateContentWithAI($textProvider, $idea);

            if (!$contentText) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate content. Please try again.'
                ], 500);
            }

            // Create the article in contents table
            $article = $this->createArticleFromIdea($idea, $contentText);

            // Update idea status to created
            $idea->update(['status' => 'created']);

            return response()->json([
                'success' => true,
                'message' => 'Content generated successfully!',
                'article_id' => $article->id,
                'article_title' => $article->title
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating content.'
            ], 500);
        }
    }

    /**
     * Bulk generate content for multiple ideas using background jobs
     */
    public function bulkGenerateContent(Request $request)
    {
        abort_if(Gate::denies('idea_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'idea_ids' => 'required|array|min:1',
            'idea_ids.*' => 'integer|exists:vela_ideas,id'
        ]);

        $ideaIds = $request->input('idea_ids');
        $ideas = Idea::whereIn('id', $ideaIds)->get();

        if ($ideas->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No valid ideas found.'
            ], 400);
        }

        try {
            $queuedCount = 0;

            foreach ($ideas as $idea) {
                // Dispatch background job for each idea
                GenerateContentFromIdeaJob::dispatch($idea->id);
                $queuedCount++;
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully queued {$queuedCount} ideas for content generation.",
                'count' => $queuedCount
            ]);

        } catch (\Exception $e) {
            \Log::error('Bulk content generation failed', [
                'error' => $e->getMessage(),
                'idea_ids' => $ideaIds
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while queuing content generation.'
            ], 500);
        }
    }

    /**
     * Generate content using AI service
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
- Make it SEO-friendly
- Write in a professional but accessible tone
- Include a compelling conclusion
- Focus on {$siteContext->getNiche()} themes relevant to {$siteContext->getName()}
- Use proper markdown formatting for headings and emphasis
- Include 2-4 strategically placed image generation tags

Metadata Requirements
- Title: ≤60 characters, must include the main keyword.
- Description: ≤160 characters, concise and benefit-driven.


Writing Style
- You are a human writer.
- Authoritative yet approachable - like a journalist with deep understanding of Thailand, realestate and lifestyle topics.
- Use active voice where suitable, but do not overuse if it doesn't make sense.
- Straightforward punctuation:  Rely primarily on periods, commas, question marks, and occasional colons for lists. Do NOT use em dashes.
- Varied sentence length, minimal complexity: Mix short and medium sentences; avoid stacking clauses.
- Human cadence: Vary paragraph length. Ask a genuine question no more than once per 300 words and answer it immediately.
- Human speech tends to come in shorter sentences and phrases.
- Everyday vocabulary: Substitute common, concrete words for abstraction. Avoid overusing complicated words when simpler ones will do. Don't use three words when one will do.
- Storytelling:  include real-world scenarios or stories where possible.
- Avoid Obvious AI Markers
- Incorporate Human Perspective and introduce Human-like Imperfections
- Try starting sentences in different ways, use dependent clauses, etc. to make it sound less formulaic.
- Perplexity measures the complexity of text. High perplexity equals more variety and complexity - typical for human writing.
- Burstiness assesses variations between sentences. Humans mix long and short sentences.
- Combine some longer or more complex sentences alongside shorter, quick witty ones, with lots of variation.
- Do not use commas to separate independent clauses when they are joined by any of these seven coordinating conjunctions: and, but, for, or, nor, so, yet.
- Do NOT use any of 'In conclusion…', 'Firstly, secondly, thirdly…', 'Furthermore,' 'Moreover,' and 'In addition'

Content structure
- Intro/Hook: Highlight a common challenge or an engaging, attention grabbing topic
- Primary keyword: must appear in title, description, first paragraph, at least one H2 and at least one image alt tag
- Secondary keywords: weave in related and semantic keywords naturally
- Headings: descriptive, keyword-rich, no fluff.

Image Generation Tags:
CRITICAL INSTRUCTION: You MUST use this exact format for images: [IMAGE topic=\"detailed description of what the image should show\" alt=\"SEO optimized alt text\"]
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

        \Log::info('Successfully generated content for idea', [
            'idea_id' => $idea->id,
            'idea_title' => $idea->name
        ]);

        return $content;
    }

    /**
     * Create article from idea and content text
     */
    private function createArticleFromIdea(Idea $idea, string $contentText): Content
    {
        // Generate unique slug from title
        $slug = \Str::slug($idea->name);
        $originalSlug = $slug;
        $counter = 1;

        while (Content::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        // Convert content text to EditorJS format
        $editorJsContent = $this->convertTextToEditorJs($contentText);

        // Create the content record
        $article = Content::create([
            'title' => $idea->name,
            'slug' => $slug,
            'type' => 'post',
            'description' => $idea->details,
            'keyword' => $idea->keyword,
            'content' => $editorJsContent,
            'author_id' => auth('vela')->id(),
            'status' => 'draft',
            'written_at' => now(),
        ]);

        // Attach category if idea has one
        if ($idea->category_id) {
            $article->categories()->attach($idea->category_id);
        }

        return $article;
    }

    /**
     * Convert markdown text to EditorJS format
     */
    private function convertTextToEditorJs(string $contentText): string
    {
        $lines = explode("\n", $contentText);
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
                // End current list if exists
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

    /**
     * Process inline formatting (bold, italic, etc.)
     */
    private function processInlineFormatting(string $text): string
    {
        // Convert **bold** to <strong>bold</strong>
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        // Convert *italic* to <em>italic</em>
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        return $text;
    }
}
