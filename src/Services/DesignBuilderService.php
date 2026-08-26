<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\PermissionGates;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Models\VelaConfig;
use Illuminate\Support\Facades\Log;

class DesignBuilderService
{
    private AiProviderManager $aiManager;
    private ChatToolRegistry $toolRegistry;
    private ChatToolExecutor $toolExecutor;
    private SiteContext $siteContext;
    private ?\Closure $progressCallback = null;
    private ?AiTextProvider $provider = null;

    /**
     * Turns of tool calling a build or fix pass is allowed.
     *
     * Ten was not enough to both survey the site and build it — the survey
     * won every time, and runs ended having written nothing.
     */
    public const MAX_TOOL_ITERATIONS = 25;

    public function __construct(
        AiProviderManager $aiManager,
        ChatToolRegistry $toolRegistry,
        ChatToolExecutor $toolExecutor,
        SiteContext $siteContext
    ) {
        $this->aiManager = $aiManager;
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = $toolExecutor;
        $this->siteContext = $siteContext;
    }

    public function onProgress(\Closure $callback): void
    {
        $this->progressCallback = $callback;
    }

    private function progress(string $message): void
    {
        if ($this->progressCallback) {
            ($this->progressCallback)($message);
        }
        Log::info('DesignBuilder: ' . $message);
    }

    /**
     * The account the builder's conversations are recorded against.
     *
     * Vela has no is_admin column — administrators are users holding the
     * "Admin" role — so asking for one by column threw before the builder
     * had done anything at all. Any user will do if no administrator exists;
     * the conversation only needs an owner.
     */
    private function adminUser(): VelaUser
    {
        $user = VelaUser::whereHas('roles', fn ($query) => $query->where('title', 'Admin'))->first()
            ?? VelaUser::first();

        if (!$user) {
            throw new \RuntimeException('No user account exists to record the build against. Run "php artisan vela:install" first.');
        }

        return $user;
    }

    /**
     * The provider every stage of one run talks to.
     *
     * Resolved once and kept: tool definitions are formatted per provider, so
     * a conversation cannot change hands halfway through.
     */
    public function provider(): AiTextProvider
    {
        return $this->provider ??= $this->resolveWorkingProvider();
    }

    /**
     * The first configured provider that actually answers.
     *
     * Providers were previously chosen on "is a key present", so a key with no
     * credit left, or one that had been revoked, was picked and every call in
     * the run silently returned nothing. A single cheap call up front settles
     * it, and the ones that refuse say why — the operator sees "no credits
     * remaining" instead of hunting through the log for it.
     */
    public function resolveWorkingProvider(): AiTextProvider
    {
        $refusals = [];

        foreach ($this->providerCandidates() as $name => $candidate) {
            $this->progress('Checking the ' . $name . ' provider...');

            if ($candidate->generateText('Reply with OK.', 5) !== null) {
                $this->progress('Using the ' . $name . ' provider.');
                return $candidate;
            }

            $refusals[] = '  ' . $name . ': ' . $this->refusalReason($candidate);
        }

        if (!$refusals) {
            throw new \RuntimeException(
                'No AI provider that can read images is configured. Add a key for OpenAI, Anthropic or Gemini under admin → Settings → AI.'
            );
        }

        throw new \RuntimeException(
            "No configured AI provider would accept the request:\n" . implode("\n", $refusals)
        );
    }

    /**
     * Every configured provider that can read an image, the preferred one first.
     */
    private function providerCandidates(): array
    {
        $primary = $this->aiManager->resolveTextProvider();

        // The gateway is a lockdown mode: when it is in force it is the only
        // provider a site may talk to, so there is nothing to fall back to.
        if ($primary instanceof VelaGatewayTextService) {
            return ['vela gateway' => $primary];
        }

        $candidates = [];
        if ($primary->supportsVision()) {
            $candidates[$this->providerName($primary)] = $primary;
        }

        foreach ($this->aiManager->availableProviders('text') as $name) {
            if (isset($candidates[$name])) {
                continue;
            }

            try {
                $candidate = $this->aiManager->resolveTextProvider($name);
            } catch (\Throwable $e) {
                continue;
            }

            if ($candidate->supportsVision()) {
                $candidates[$name] = $candidate;
            }
        }

        return $candidates;
    }

    private function providerName(AiTextProvider $provider): string
    {
        return match (true) {
            $provider instanceof OpenAiTextService => 'openai',
            $provider instanceof ClaudeTextService => 'anthropic',
            $provider instanceof GeminiTextService => 'gemini',
            default => class_basename($provider),
        };
    }

    /**
     * What the provider said when it refused, if it kept a record.
     */
    private function refusalReason(AiTextProvider $provider): string
    {
        $reason = method_exists($provider, 'lastError') ? $provider->lastError() : null;

        return $reason ?: 'no response (see storage/logs for the provider\'s reply)';
    }

    /**
     * Why a stage could not go on.
     *
     * A stage that gets nothing back has built nothing, so it stops the run
     * rather than letting the caller report a site that was never made. The
     * provider's own words come along, because "no response" on its own sends
     * the operator to the log file to find out they are out of credit.
     */
    private function stageFailure(string $stage, AiTextProvider $provider): string
    {
        $message = 'The AI provider stopped answering during the ' . $stage . ' step: '
            . $this->refusalReason($provider);

        Log::error('DesignBuilder: ' . $message);

        return $message;
    }

    /**
     * Scan the design folder, catalog assets and instructions, write context.json.
     */
    public function generateContext(string $designPath): array
    {
        $imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
        $instructionExtensions = ['md', 'txt'];
        $skipExtensions = ['json', 'psd', 'ai'];

        $assets = [];
        $instructions = [];

        if (!is_dir($designPath)) {
            return ['assets' => [], 'instructions' => [], 'created_resources' => []];
        }

        $files = scandir($designPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $designPath . '/' . $file;
            if (!is_file($filePath)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (in_array($ext, $skipExtensions) || $this->isOwnOutput($file)) {
                continue;
            }

            if (in_array($ext, $imageExtensions)) {
                $assets[] = [
                    'file' => $file,
                    'type' => 'image',
                    'size' => filesize($filePath),
                    'role' => $this->detectRole($file),
                ];
            } elseif (in_array($ext, $instructionExtensions)) {
                $instructions[] = [
                    'file' => $file,
                    'content' => file_get_contents($filePath),
                ];
            }
        }

        $this->progress('Catalogued ' . count($assets) . ' assets and ' . count($instructions) . ' instruction files');

        $context = [
            'assets' => $assets,
            'instructions' => $instructions,
            'created_resources' => [],
        ];

        file_put_contents(
            $designPath . '/context.json',
            json_encode($context, JSON_PRETTY_PRINT)
        );

        return $context;
    }

    /**
     * Detect role from filename heuristics.
     */
    private function detectRole(string $filename): string
    {
        $lower = strtolower($filename);
        if (
            str_contains($lower, 'design') ||
            str_contains($lower, 'mockup') ||
            str_contains($lower, 'screenshot') ||
            str_contains($lower, 'comp') ||
            str_contains($lower, 'wireframe')
        ) {
            return 'design';
        }
        if (
            str_contains($lower, 'logo') ||
            str_contains($lower, 'icon') ||
            str_contains($lower, 'favicon')
        ) {
            return 'asset';
        }
        return 'reference';
    }

    /**
     * Whether a file in the design folder is something a previous run wrote.
     *
     * Runs used to leave their screenshots and reports beside the design they
     * were built from, and the next run read them straight back in: a capture
     * named loop_1_screenshot.png matched on "screenshot" and became one of
     * the designs to copy, so re-running after a poor result made it worse.
     * Runs now write to a subfolder, and this keeps older leftovers out.
     */
    private function isOwnOutput(string $filename): bool
    {
        return (bool) preg_match('/^loop_\d+_(screenshot|report)\./i', $filename);
    }

    /**
     * Execute the initial build by driving the chat tool system with design context.
     */
    public function runBuildLoop(array &$context, string $designPath, string $url): void
    {
        $textProvider = $this->provider();
        $user = $this->adminUser();

        // Permission gates are normally defined by HTTP middleware, which no
        // console command ever passes through. Without them every gate denies,
        // and the tool registry below hands back only the read-only tools.
        app(PermissionGates::class)->register();

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => 'Design Builder - ' . now()->format('Y-m-d H:i'),
        ]);

        $systemPrompt = $this->buildSystemPrompt($context);

        // Build user message with design images
        $userContent = [
            ['type' => 'text', 'text' => 'Here is the design to replicate. Build the site to match this design.'],
        ];

        foreach ($context['assets'] as $asset) {
            if (($asset['role'] ?? '') === 'design') {
                $filePath = $designPath . '/' . $asset['file'];
                if (file_exists($filePath)) {
                    $base64 = $this->resizeImageForVision($filePath);
                    $mimeType = $this->detectMimeType($filePath);
                    $userContent[] = [
                        'type' => 'image',
                        'source' => $base64,
                        'media_type' => $mimeType,
                    ];
                }
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ];

        // Get formatted tools for this provider
        $availableTools = $this->toolRegistry->forUser($user);
        $formattedTools = $this->getFormattedTools($textProvider, $availableTools);

        $this->progress('Calling AI to build site...');
        $response = $textProvider->chat($messages, $formattedTools, 4096);

        if (!$response) {
            throw new \RuntimeException($this->stageFailure('build', $textProvider));
        }

        $maxToolIterations = self::MAX_TOOL_ITERATIONS;
        $iteration = 0;

        while ($iteration < $maxToolIterations && !empty($response['tool_calls'])) {
            $iteration++;

            $assistantMsg = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response['content'] ?? null,
                'tool_calls' => $response['tool_calls'],
                'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
            ]);

            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'] ?? '',
                'tool_calls' => array_map(function ($tc) {
                    return [
                        'id' => $tc['id'] ?? ('call_' . uniqid()),
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'] ?? '',
                            'arguments' => is_string($tc['arguments'] ?? null)
                                ? $tc['arguments']
                                : json_encode($tc['arguments'] ?? new \stdClass),
                        ],
                    ];
                }, $response['tool_calls']),
            ];

            foreach ($response['tool_calls'] as $toolCall) {
                $this->progress('Executing tool: ' . $toolCall['name']);

                $result = $this->toolExecutor->execute(
                    $toolCall['name'],
                    $toolCall['arguments'],
                    $conversation->id,
                    $assistantMsg->id,
                    $user
                );

                $context['created_resources'][] = [
                    'tool' => $toolCall['name'],
                    'result' => $result,
                ];

                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'tool',
                    'content' => json_encode($result),
                    'tool_call_id' => $toolCall['id'],
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result),
                ];
            }

            $response = $textProvider->chat($messages, $formattedTools, 4096);
            if (!$response) {
                break;
            }
        }

        // Save final assistant response
        if ($response && ($response['content'] ?? null)) {
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response['content'],
                'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
            ]);
        }

        $this->updateContextFile($designPath, $context);
        $this->progress('Build loop complete after ' . $iteration . ' tool iterations');
    }

    /**
     * Send original design + current screenshot to vision AI for qualitative comparison.
     */
    public function runQaComparison(array $context, string $screenshotPath, string $designPath): array
    {
        $textProvider = $this->provider();

        $prompt = <<<'PROMPT'
Compare the DESIGN (reference) with the SCREENSHOT (current site build).

Assess how closely the screenshot matches the design. Consider:
- Overall layout and structure
- Color scheme and branding
- Typography and text content
- Spacing and alignment
- Header/footer structure
- Hero/banner areas
- Navigation elements

Respond with this exact JSON structure:
{
  "passed": true/false,
  "summary": "One sentence overall assessment",
  "score_description": "Brief qualitative description (e.g., 'Close match with minor spacing issues')",
  "fixes": [
    {"area": "header", "issue": "...", "fix": "..."},
    {"area": "colors", "issue": "...", "fix": "..."}
  ]
}

Set "passed" to true ONLY if the screenshot is a close visual match to the design.
Be specific about what needs fixing. Each fix should be actionable.
PROMPT;

        $userContent = [
            ['type' => 'text', 'text' => $prompt],
        ];

        // Add design images
        foreach ($context['assets'] as $asset) {
            if (($asset['role'] ?? '') === 'design') {
                $filePath = $designPath . '/' . $asset['file'];
                if (file_exists($filePath)) {
                    $base64 = $this->resizeImageForVision($filePath);
                    $mimeType = $this->detectMimeType($filePath);
                    $userContent[] = [
                        'type' => 'image',
                        'source' => $base64,
                        'media_type' => $mimeType,
                    ];
                }
            }
        }

        // Add screenshot
        if (file_exists($screenshotPath)) {
            $screenshotBase64 = $this->resizeImageForVision($screenshotPath);
            $userContent[] = [
                'type' => 'image',
                'source' => $screenshotBase64,
                'media_type' => $this->detectMimeType($screenshotPath),
            ];
        }

        $messages = [
            ['role' => 'system', 'content' => 'You are a visual QA expert comparing a design mockup with a website screenshot.'],
            ['role' => 'user', 'content' => $userContent],
        ];

        $this->progress('Running visual QA comparison...');
        $response = $textProvider->chat($messages, [], 4096);

        if (!$response || !($response['content'] ?? null)) {
            throw new \RuntimeException($this->stageFailure('visual QA', $textProvider));
        }

        // Parse JSON from response
        $assessment = $this->parseJsonFromResponse($response['content']);

        $report = $this->buildQaReport($assessment);

        return [
            'passed' => (bool) ($assessment['passed'] ?? false),
            'summary' => $assessment['summary'] ?? 'No summary provided.',
            'fixes' => $assessment['fixes'] ?? [],
            'report' => $report,
            'usage' => $response['usage'] ?? ['input' => 0, 'output' => 0],
        ];
    }

    /**
     * Apply fixes from QA comparison by driving the chat tool system.
     */
    public function runFixLoop(array $fixes, array &$context, string $designPath, string $url): void
    {
        $textProvider = $this->provider();
        $user = $this->adminUser();

        // Permission gates are normally defined by HTTP middleware, which no
        // console command ever passes through. Without them every gate denies,
        // and the tool registry below hands back only the read-only tools.
        app(PermissionGates::class)->register();

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => 'Design Builder Fix - ' . now()->format('Y-m-d H:i'),
        ]);

        $systemPrompt = $this->buildSystemPrompt($context, true);

        $fixPrompt = 'The visual QA comparison of the built site against the design found these issues:' . "\n"
            . json_encode($fixes, JSON_PRETTY_PRINT)
            . "\n\nCorrect the existing page so these are resolved, largest visual difference first."
            . "\nInspect the page with get_page_blocks and change or remove what is there;"
            . " add something only where the design shows a section the page does not have at all.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $fixPrompt],
        ];

        $availableTools = $this->toolRegistry->forUser($user);
        $formattedTools = $this->getFormattedTools($textProvider, $availableTools);

        $this->progress('Applying QA fixes...');
        $response = $textProvider->chat($messages, $formattedTools, 4096);

        if (!$response) {
            throw new \RuntimeException($this->stageFailure('fix', $textProvider));
        }

        $maxToolIterations = self::MAX_TOOL_ITERATIONS;
        $iteration = 0;

        while ($iteration < $maxToolIterations && !empty($response['tool_calls'])) {
            $iteration++;

            $assistantMsg = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response['content'] ?? null,
                'tool_calls' => $response['tool_calls'],
                'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
            ]);

            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'] ?? '',
                'tool_calls' => array_map(function ($tc) {
                    return [
                        'id' => $tc['id'] ?? ('call_' . uniqid()),
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'] ?? '',
                            'arguments' => is_string($tc['arguments'] ?? null)
                                ? $tc['arguments']
                                : json_encode($tc['arguments'] ?? new \stdClass),
                        ],
                    ];
                }, $response['tool_calls']),
            ];

            foreach ($response['tool_calls'] as $toolCall) {
                $this->progress('Executing tool: ' . $toolCall['name']);

                $result = $this->toolExecutor->execute(
                    $toolCall['name'],
                    $toolCall['arguments'],
                    $conversation->id,
                    $assistantMsg->id,
                    $user
                );

                $context['created_resources'][] = [
                    'tool' => $toolCall['name'],
                    'result' => $result,
                ];

                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'tool',
                    'content' => json_encode($result),
                    'tool_call_id' => $toolCall['id'],
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result),
                ];
            }

            $response = $textProvider->chat($messages, $formattedTools, 4096);
            if (!$response) {
                break;
            }
        }

        if ($response && ($response['content'] ?? null)) {
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response['content'],
                'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
            ]);
        }

        $this->updateContextFile($designPath, $context);
        $this->progress('Fix loop complete after ' . $iteration . ' tool iterations');
    }

    /**
     * Resize image to max 2048px on longest edge, return base64-encoded string.
     */
    public function resizeImageForVision(string $imagePath): string
    {
        $contents = file_get_contents($imagePath);

        if (!function_exists('imagecreatefromstring')) {
            return base64_encode($contents);
        }

        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo) {
            return base64_encode($contents);
        }

        [$width, $height] = $imageInfo;
        $maxEdge = 2048;

        if ($width <= $maxEdge && $height <= $maxEdge) {
            return base64_encode($contents);
        }

        $img = @imagecreatefromstring($contents);
        if (!$img) {
            return base64_encode($contents);
        }

        if ($width >= $height) {
            $newWidth = $maxEdge;
            $newHeight = (int) round($height * ($maxEdge / $width));
        } else {
            $newHeight = $maxEdge;
            $newWidth = (int) round($width * ($maxEdge / $height));
        }

        $resized = imagescale($img, $newWidth, $newHeight);
        imagedestroy($img);

        if (!$resized) {
            return base64_encode($contents);
        }

        ob_start();
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'])) {
            imagejpeg($resized);
        } else {
            imagepng($resized);
        }
        $resizedContents = ob_get_clean();
        imagedestroy($resized);

        return base64_encode($resizedContents);
    }

    /**
     * Return MIME type from file extension.
     */
    public function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    /**
     * Build system prompt with design context.
     *
     * The tools that change the site are named here, and named as tools. The
     * prompt used to describe them only as prose capabilities — "Create
     * articles/content" — while telling the model to inspect things first,
     * and it spent its whole budget of turns reading: a run would finish with
     * the right template and a page of "No articles yet" placeholders, because
     * create_article had never been called once.
     *
     * A QA pass is given the correcting form. Both passes used to share the
     * building one, so every round of fixes read "create a category per topic,
     * an article per article, add_row for structure" against a site that
     * already had all of them — and dutifully made a second copy of each.
     */
    private function buildSystemPrompt(array $context, bool $correcting = false): string
    {
        $siteDesc = $this->siteContext->getDescription();
        $instructionsJson = json_encode($context['instructions'] ?? [], JSON_PRETTY_PRINT);
        $assetsJson = json_encode($context['assets'] ?? [], JSON_PRETTY_PRINT);
        $steps = self::MAX_TOOL_ITERATIONS;

        $task = $correcting
            ? $this->correctingInstructions($steps)
            : $this->buildingInstructions($steps);

        return <<<PROMPT
You are a site builder AI for {$siteDesc}.

DESIGN CONTEXT:
{$instructionsJson}

ASSET INVENTORY:
{$assetsJson}

{$task}
PROMPT;
    }

    private function buildingInstructions(int $steps): string
    {
        return <<<PROMPT
You have a design to replicate. Build the site to match it as closely as you can.

HOW TO BUILD, IN ORDER:
1. switch_template — pick the template whose layout is closest to the design.
2. create_category — one per section or topic the design shows.
3. create_article — one per article the design shows, with status "published".
   A listing in the design that holds five articles needs five articles to
   exist. Write real headlines and copy that suit the design's subject; a
   listing with nothing in it renders as "No articles yet" and matches nothing.
4. generate_image — for pictures the design shows that no supplied asset
   covers. Use the url it returns exactly as given.
5. update_site_config — site name and description.
6. update_template_colors, then update_custom_css — colours, type and spacing.
7. add_row / add_block / update_block — only for structure the template does
   not already provide.

RULES:
- Content first, styling second. A page styled perfectly with nothing in it is
  further from the design than a plain page with the right articles on it.
- Adding another empty listing block does not add content. Only
  create_article and create_category do.
- Use update_custom_css for all visual styling.
- You have about {$steps} turns. Spend them changing the site, not surveying
  it: read a thing only when you cannot act without knowing it.
PROMPT;
    }

    private function correctingInstructions(int $steps): string
    {
        return <<<PROMPT
The site is already built. Your job is to correct what is there so it matches
the design more closely — not to build it again.

HOW TO CORRECT:
- Call get_page_blocks first and work from what the page actually contains.
- Change what exists: update_block, update_row, update_custom_css,
  update_template_colors, update_site_config, edit_article_content.
- Remove what the design does not have: delete_block, delete_row.
- Only add a row or block for a section the design shows and the page has
  none of. If the page already has an articles listing, a topics listing or a
  hero, correct that one — never add a second.

RULES:
- Never call create_article or create_category for a title or name that
  already exists. Call list_articles or list_categories if you are unsure.
- A section appearing twice on the page is a worse mismatch than the fault you
  were fixing. When something looks wrong, ask whether to change or delete it
  before you ask whether to add anything.
- A screenshot that is much taller than the design usually means the page has
  repeated sections, or empty space to remove.
- You have about {$steps} turns. Spend them on the largest visual differences
  first.
PROMPT;
    }

    /**
     * Get formatted tools for the current provider.
     */
    private function getFormattedTools($textProvider, array $availableTools): array
    {
        $providerClass = get_class($textProvider);
        if (str_contains($providerClass, 'Claude')) {
            return $this->toolRegistry->toAnthropicFormat($availableTools);
        } elseif (str_contains($providerClass, 'Gemini')) {
            return $this->toolRegistry->toGeminiFormat($availableTools);
        } else {
            return $this->toolRegistry->toOpenAiFormat($availableTools);
        }
    }

    /**
     * Parse JSON object from AI response text.
     */
    private function parseJsonFromResponse(string $content): array
    {
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Build a markdown QA report from the assessment.
     */
    private function buildQaReport(array $assessment): string
    {
        $passed = ($assessment['passed'] ?? false) ? 'PASSED' : 'FAILED';
        $summary = $assessment['summary'] ?? 'No summary provided.';
        $scoreDesc = $assessment['score_description'] ?? '';
        $fixes = $assessment['fixes'] ?? [];

        $report = "# Visual QA Report\n\n";
        $report .= "**Status:** {$passed}\n\n";
        $report .= "**Summary:** {$summary}\n\n";
        if ($scoreDesc) {
            $report .= "**Assessment:** {$scoreDesc}\n\n";
        }

        if (!empty($fixes)) {
            $report .= "## Issues Found\n\n";
            foreach ($fixes as $fix) {
                $area = $fix['area'] ?? 'Unknown';
                $issue = $fix['issue'] ?? '';
                $fixText = $fix['fix'] ?? '';
                $report .= "### {$area}\n";
                $report .= "- **Issue:** {$issue}\n";
                $report .= "- **Fix:** {$fixText}\n\n";
            }
        } else {
            $report .= "## No Issues Found\n\n";
            $report .= "The site matches the design.\n";
        }

        return $report;
    }

    /**
     * Write updated context back to context.json.
     */
    private function updateContextFile(string $designPath, array $context): void
    {
        file_put_contents(
            $designPath . '/context.json',
            json_encode($context, JSON_PRETTY_PRINT)
        );
    }
}
