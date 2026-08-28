<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\PermissionGates;
use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Page;
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
     * won every time, and runs ended having written nothing. Twenty-five was
     * not enough either once a build also had to compose a homepage out of
     * blocks and write the theme that styles them: a run spent all of it on
     * the blocks and stopped before the stylesheet.
     */
    public const MAX_TOOL_ITERATIONS = 40;

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
     * What a given file would be treated as, for anything that needs to say
     * so before a build runs.
     */
    public function roleFor(string $filename): string
    {
        return $this->detectRole($filename);
    }

    /**
     * What a picture in the design folder is for.
     *
     * Only assets marked "design" are ever shown to the model, so this decides
     * whether a build can see anything at all. It used to say "design" only
     * when the filename said so — which held for files a developer had named
     * restaurant-design.jpg, and failed silently for everything a real person
     * uploads: a camera roll IMG_4821.jpg, a browser's download hash, a name
     * in another language. The build then ran with no design in front of it,
     * described the site it already had, and reported success.
     *
     * So the default is now the other way round: a picture someone put in the
     * design folder is the design, unless it is plainly a logo or an icon.
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

        return 'design';
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

        // A build with no picture in front of it does not fail — it describes
        // the site it already has and reports success, which reads as "the
        // design was ignored". Say so instead.
        if (count($userContent) < 2) {
            throw new \RuntimeException(
                'There is no design to build from. Upload a picture of what the site should look like '
                . '— a screenshot, a mockup, a photo of a sketch — and start the build again.'
            );
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
        $askedAgain = false;

        // The build stops when the model stops calling tools, and it stops
        // early: the same design and brief produced seven sections one run and
        // two the next, well inside a budget of forty turns. Nothing checked
        // the page against the design before calling it finished, so whatever
        // it had built when it ran out of interest was the result, and the QA
        // rounds then spent themselves building rather than correcting. It is
        // asked once more, with the design in front of it and a list of what
        // it actually made.
        while (true) {

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
                                : json_encode($tc['arguments'] ?? new \stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
                    'content' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall['id'],
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }

            $response = $textProvider->chat($messages, $formattedTools, 4096);
            if (!$response) {
                break;
            }
        }

            // Out of turns, or already asked: nothing more to do here.
            if ($askedAgain || !$response || $iteration >= $maxToolIterations) {
                break;
            }

            $askedAgain = true;
            $this->progress('Checking the page against the design...');

            $messages[] = [
                'role' => 'user',
                'content' => $this->completenessPrompt($context, $designPath),
            ];

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
     * What to send when a build says it is finished.
     *
     * The design again, and a plain list of what is now on the page — the
     * model has been working through tool results and cannot see the page as
     * a whole. Being shown both is what turns "I have done some of it" into
     * either the rest of it or a straight answer that nothing is missing.
     */
    private function completenessPrompt(array $context, string $designPath): array
    {
        $content = [[
            'type' => 'text',
            'text' => "You have stopped building. Here is the design again, and here is what the page now holds:\n\n"
                . $this->pageOutline($context)
                . "\n\nCompare them section by section, top to bottom. For every section the design shows that is "
                . "not in that list, add it now with add_row and add_block. Keep the design's own wording. "
                . "If nothing is missing, reply with the word DONE and call nothing.",
        ]];

        foreach ($context['assets'] ?? [] as $asset) {
            if (($asset['role'] ?? '') !== 'design') {
                continue;
            }

            $filePath = $designPath . '/' . $asset['file'];

            if (file_exists($filePath)) {
                $content[] = [
                    'type' => 'image',
                    'source' => $this->resizeImageForVision($filePath),
                    'media_type' => $this->detectMimeType($filePath),
                ];
            }
        }

        return $content;
    }

    /**
     * The page the build was for, section by section, in one short list.
     */
    private function pageOutline(array $context): string
    {
        $page = isset($context['target_page']['id'])
            ? Page::find((int) $context['target_page']['id'])
            : Page::where('slug', 'home')->first();

        if (!$page) {
            return '(the page could not be read)';
        }

        $lines = [];

        foreach ($page->rows()->orderBy('order_column')->get() as $index => $row) {
            $types = $row->blocks()->orderBy('order_column')->pluck('type')->all();
            $lines[] = ($index + 1) . '. ' . ($types ? implode(', ', $types) : '(an empty row)');
        }

        return $lines ? implode("\n", $lines) : '(the page has no sections at all)';
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
            . " add something only where the design shows a section the page does not have at all."
            . "\n\nThe design itself is below. Where a fix mentions wording, take the wording from"
            . " the design and use it exactly; do not compose something in its place.";

        // The fix loop used to be told the design was wrong about the words
        // and never shown the design. Asked to correct "text content differs",
        // it wrote plausible marketing copy over sentences that had been read
        // off the design correctly — a headline the design gives as "Real-Time
        // Monitoring Your Infrastructure" came back as "Zercurity", and one
        // reading "Fire, smoke, and slow time." came back as the restaurant's
        // name. Both times the build had got it right and the fix undid it.
        $userContent = [['type' => 'text', 'text' => $fixPrompt]];

        foreach ($context['assets'] ?? [] as $asset) {
            if (($asset['role'] ?? '') !== 'design') {
                continue;
            }

            $filePath = $designPath . '/' . $asset['file'];

            if (file_exists($filePath)) {
                $userContent[] = [
                    'type' => 'image',
                    'source' => $this->resizeImageForVision($filePath),
                    'media_type' => $this->detectMimeType($filePath),
                ];
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
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
                                : json_encode($tc['arguments'] ?? new \stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
                    'content' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall['id'],
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
        $target = $context['target_page'] ?? null;

        $task = $correcting
            ? $this->correctingInstructions($steps, $target)
            : $this->buildingInstructions($steps, $target);

        return <<<PROMPT
You are a site builder AI for {$siteDesc}.

DESIGN CONTEXT:
{$instructionsJson}

ASSET INVENTORY:
{$assetsJson}

{$task}
PROMPT;
    }

    /**
     * Which page the build is for, in the terms the model works in.
     *
     * A build is given a page of its own so the design is not weighed against
     * whatever the site happens to hold: told to work on a homepage already
     * full of the last design, the model corrects that towards the new one and
     * the old site shows through. Named explicitly, and told to leave the rest
     * alone, it builds what the design shows.
     */
    private function wherePagePart(?array $target): string
    {
        if (!$target) {
            return 'delete_row — a fresh install ships a homepage of its own: a welcome hero,' . "\n"
                . '   an article listing, a call to action. Call get_page_blocks on the home page' . "\n"
                . '   and delete every row of it. What you build next replaces it.';
        }

        $id = (int) ($target['id'] ?? 0);
        $slug = (string) ($target['slug'] ?? '');

        return <<<PART
Build on page id {$id} ("/{$slug}") and on no other. It has been emptied for
   you, so there is nothing to delete first. Do not touch the homepage or any
   other page: this one is what will be shown as the result, and the site's
   own pages are not yours to rewrite. Call update_page once to title it with
   the name the design gives the site — that name is taken up as the site's
   own if this design is the one they keep. Do not call update_site_config
   for the site's name: nothing here is theirs yet.
PART;
    }

    private function buildingInstructions(int $steps, ?array $target = null): string
    {
        $blockClasses = $this->blockClassReference();
        $editableBlocks = implode(', ', app(\VelaBuild\Core\Vela::class)->blocks()->editableNames());
        $where = $this->wherePagePart($target);

        return <<<PROMPT
You have a design to replicate. Two things carry it, and keeping them apart is
what makes the result both faithful and editable:

  The THEME carries the frame and the look — the header, the navigation, the
  footer, the typeface, the colours, and the CSS that gives every block on
  every page the shape the design gives it.

  BLOCKS carry the page — the hero, the panels, the cards, the quote. They are
  what the site's owner sees and edits in the admin. Anything you write into
  the theme instead of a block is content they can never change again.

So: build the homepage out of blocks, and write a theme whose stylesheet makes
those blocks look exactly like the design.

HOW TO BUILD, IN ORDER:
1. get_theme_contract and list_block_types — read both first. The contract
   says what a theme's views are handed; list_block_types names every block
   and, for each, the CSS classes it renders with. Your stylesheet targets
   those class names, so guessing them means writing CSS that matches nothing.
2. create_theme — name it after the site the design is for, as the design
   itself gives that name: the wordmark in the header, the name in the footer.
   Not a word describing what it is — "theme", "custom", "active", "design"
   all name the same thing every time, and a site collects one of these per
   build. No "Theme" on the end either; they are all themes.
3. switch_template — to it, straight away, while it is still empty. An empty
   theme falls back to plain built-in views, so the site keeps working, and
   everything you do from here is visible instead of waiting behind a switch
   you might not reach.
4. {$where}
5. add_row and add_block — build the design's page, section by section,
   from the block types that fit:
     a full-width headline over an image  -> hero
     a row of figures or short claims     -> icon_box
     cards carrying a price               -> pricing_tiers
     a quotation with an attribution      -> testimonials
     a grid of articles                   -> posts_grid
     a grid of topics                     -> categories_grid
     a band inviting an action            -> cta
     pictures                             -> image or gallery
   Use html only where nothing else fits. Put the design's real words in —
   its headlines, its prices, its quote — not placeholders describing them.
   Only these block types can be edited in the admin, so only these may be
   used: {$editableBlocks}. Any other renders for a visitor but shows the
   site's owner "Unknown block type", and they could never change it.
6. update_page — one call, to title the page with the name the design gives
   the site. Read it off the design: the wordmark in the header, the name in
   the footer. This is the name the site takes if the design is kept, so a
   page still called "Design preview" leaves it nameless.
7. set_theme_tokens — this is what makes the site look like the design rather
   than like Vela, and it is one call. The theme you created already has a
   frame, navigation, a footer and a rule for every block; all of it reads
   from a set of tokens. Read the design and set them: the typeface, the
   background, the ink, the accent, the colour of the full-width bands, the
   corner rounding, the page width. Call it with no tokens to see the list.
   Do not stop before this: without it the design's structure is there in
   somebody else's colours.
8. write_theme_file — only if a token cannot express something the design
   needs, and only for the view at fault. The skeleton is a working theme;
   replacing it wholesale usually loses the header and footer.
9. create_category — one per section or topic the design shows.
10. create_article — one per article the design shows, with status "published".
    A listing holding five articles needs five articles to exist. Write real
    headlines and copy suited to the subject; an empty listing matches nothing.
11. generate_image — for pictures the design shows that no supplied asset
    covers. Use the url it returns exactly as given. A file listed in the
    asset inventory is something you are reading from, not a picture the site
    can serve: passing one of those names as an image leaves a broken one.
12. update_site_config — the site's description. Its name comes from the page
    title you set in step 6, and only if this design is kept.
13. write_theme_file for "articles", "article", "categories_index" and
    "categories_show", styled to match. Anything you leave out falls back to a
    plain built-in view: the site still works, it just is not your design.

THE CLASS NAMES YOUR STYLESHEET MUST USE:
{$blockClasses}

RULES:
- Reach for set_theme_tokens first, every time. Most of what separates two
  designs is a typeface, a palette and a corner radius, and the theme is
  built to take them.
- If you do write a stylesheet, style those class names and no others. A rule
  written against a name that is not in that list matches nothing, changes
  nothing, and reports nothing — the quietest way to end up with the design's
  structure in Vela's colours. A layout whose stylesheet mentions none of
  them is refused.
- update_custom_css is for a small adjustment afterwards, not for the design.
- A section written as markup in the theme is a section the owner cannot edit.
  Only put something there when no block can hold it, and never the homepage's
  words, prices or headings.
- Blade that would not compile is refused with the reason. Fix it and write
  again; nothing broken reaches a visitor.
- Adding another empty listing block does not add content. Only
  create_article and create_category do.
- You have about {$steps} turns. Spend them changing the site, not surveying
  it: read a thing only when you cannot act without knowing it.
PROMPT;
    }

    /**
     * Every block and the classes it renders with, for the prompt itself.
     *
     * Left to ask for these, a run skipped the asking and wrote a stylesheet
     * against invented names — .block-accent, .block-text-primary — which
     * matched nothing and left the site in Vela's own colours. They are small
     * enough to simply hand over.
     */
    private function blockClassReference(): string
    {
        $author = app(ThemeAuthor::class);
        $lines = [];

        foreach (array_keys(app(\VelaBuild\Core\Vela::class)->blocks()->all()) as $type) {
            $classes = $author->blockClasses($type);

            if ($classes) {
                $lines[] = '  ' . $type . ': .' . implode(' .', $classes);
            }
        }

        return implode("\n", $lines);
    }

    private function correctingInstructions(int $steps, ?array $target = null): string
    {
        $onlyPage = $target
            ? 'Everything you change on a page belongs to page id ' . (int) $target['id']
                . ' ("/' . $target['slug'] . '"). Leave every other page alone.'
            : 'A section on the page that you did not design is a leftover row from the'
                . ' install\'s own homepage: find it with get_page_blocks and delete_row it.';

        return <<<PROMPT
The site is already built and has a theme written for it. Your job is to
correct what is there so it matches the design more closely — not to build it
again.

HOW TO CORRECT:
- {$onlyPage}
- How a section looks is the theme's stylesheet; what it says is a block.
  A wrong colour, size or spacing is fixed with write_theme_file; wrong words
  or a missing card with update_block or add_block. Never move a homepage
  section into the theme as markup to make it look right — that takes it away
  from the person who owns the site.
- Most differences are the theme's, and most of those are a token: a colour
  that is too blue, type that is not the design's, corners too round, a band
  the wrong shade. set_theme_tokens fixes those in one call and cannot break
  the page. Only rewrite a view with write_theme_file when no token covers
  what is wrong, and then keep everything that was already right.
- Content differences: update_block, update_row, edit_article_content,
  update_site_config.
- Remove what the design does not have: delete_block, delete_row.
- Only add a row or block for a section the design shows and the page has
  none of. If the page already has an articles listing, a topics listing or a
  hero, correct that one — never add a second.

RULES:
- Never create_theme again. One theme was written for this design; correct it.
- Never call create_article or create_category for a title or name that
  already exists. Call list_articles or list_categories if you are unsure.
- A section appearing twice on the page is a worse mismatch than the fault you
  were fixing. When something looks wrong, ask whether to change or delete it
  before you ask whether to add anything.
- A screenshot much taller than the design usually means repeated sections, or
  empty space to remove.
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
