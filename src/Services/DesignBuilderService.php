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

    /**
     * Tools a round of corrections is not given.
     *
     * One theme was written for this design and the page was built onto one
     * page; a fix round that makes another theme, or switches away from the
     * one it is correcting, has thrown away the thing it was asked to improve
     * — and it did exactly that, twice in one run.
     *
     * @var array<int, string>
     */
    /**
     * Tools no part of a design build is given.
     *
     * A build goes onto a page of its own so that nobody trying a design out
     * has their site changed underneath them, and the frame has to keep that
     * promise too: switch_template dresses every page on the site in the new
     * theme the moment it is called. use_theme_for_preview is the way through.
     *
     * @var array<int, string>
     */
    public const TOOLS_A_BUILD_MAY_NOT_USE = [
        'switch_template',
    ];

    public const TOOLS_A_FIX_MAY_NOT_USE = [
        'create_theme',
        'switch_template',
        'create_page',
        'delete_page',
        // Withholding write_theme_file's guard is pointless while these can
        // reach the same file. Refused three times for writing a layout with
        // no <head>, a fix round went around by hand: search_files to find the
        // layout, read_file to read it, edit_file to change it. A guard that
        // can be walked around is a request, not a guard.
        'edit_file',
        'write_file',
        'run_command',
        'git',
    ];

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
        $availableTools = $this->toolsForBuilding($this->toolRegistry->forUser($user));
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

                $result = $this->refusePicture($toolCall['name']) ?? $this->toolExecutor->execute(
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
                . "not in that list, add it now with add_designed_section — its own markup and its own stylesheet, "
                . "as the design shows it — or with add_row and add_block if it is a listing of articles or topics. "
                . "Keep the design's own wording. If nothing is missing, reply with the word DONE and call nothing.",
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

            // A page built from written sections is a row of "html" all the
            // way down, and a list of nine of those tells the model nothing
            // about what it has already made — which is the one thing this
            // list is for. The row's name is what the section was called when
            // it was written, so say that instead.
            $what = $types === ['html'] && trim((string) $row->name) !== ''
                ? trim($row->name) . ' (a written section)'
                : ($types ? implode(', ', $types) : '(an empty row)');

            $lines[] = ($index + 1) . '. ' . $what;
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

        // A round of fixes is not a second build, and the prompt saying so was
        // not enough: a fix round called create_theme twice, switched to the
        // theme it had just made, and rewrote seven of its views. The site came
        // back answering 200 on every page in the browser's own serif, with
        // the design gone. What a correction may not do is taken away from it
        // here rather than asked for.
        $availableTools = $this->toolsForCorrecting($this->toolRegistry->forUser($user));
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

                $result = $this->refusePicture($toolCall['name']) ?? $this->toolExecutor->execute(
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
     * The tool list a round of corrections is given.
     *
     * @param  array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public function toolsForCorrecting(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            fn ($tool) => !in_array($tool['name'] ?? '', self::TOOLS_A_FIX_MAY_NOT_USE, true)
        ));
    }

    /**
     * The tool list the build itself is given.
     *
     * @param  array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public function toolsForBuilding(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            fn ($tool) => !in_array($tool['name'] ?? '', self::TOOLS_A_BUILD_MAY_NOT_USE, true)
        ));
    }

    /**
     * How many pictures one build may make.
     *
     * A build shown a design with three icons and a strip of customer logos
     * asked for ten: an illustration, three icons that came back looking like
     * emoji, and six approximated company trademarks. One good picture where
     * the design has a photograph is worth the wait; nine more are minutes and
     * money spent making the page look less like the design, not more.
     */
    public const MAX_PICTURES = 3;

    /**
     * A neutral stand-in, shipped with Vela, for a build making no pictures.
     *
     * A slot left empty is a hole the QA rounds try to fill; a slot with a
     * plain grey frame in it is a picture somebody has yet to choose, which is
     * what it actually is.
     */
    public const PLACEHOLDER = '/vendor/vela/images/picture-placeholder.svg';

    private int $picturesMade = 0;

    private bool $picturesAllowed = true;

    /**
     * Build this one without making any pictures.
     *
     * For a site whose owner already has their photographs. The slots stay in
     * the markup with their alt text, so the layout is still judged on the
     * same shapes and there is somewhere obvious to drop the real picture in.
     */
    public function makeNoPictures(): void
    {
        $this->picturesAllowed = false;
    }

    /**
     * @return array<string, mixed>|null a refusal, or null to let the call run
     */
    private function refusePicture(string $tool): ?array
    {
        if ($tool !== 'generate_image') {
            return null;
        }

        if (!$this->picturesAllowed) {
            return [
                'error' => 'This build was asked not to make pictures — its owner has their own. Where the design '
                    . 'shows one, put an <img> in at the size and place the design gives it, pointing at '
                    . self::PLACEHOLDER . ', with alt text describing the picture that belongs there. The '
                    . 'arrangement is then right and there is somewhere obvious to drop the real one in. Do not '
                    . 'invent an address for a file: an <img> pointing at nothing is refused.',
                'use_this_url' => self::PLACEHOLDER,
            ];
        }

        if (++$this->picturesMade <= self::MAX_PICTURES) {
            return null;
        }

        return [
            'error' => 'This build has already made ' . self::MAX_PICTURES . ' pictures, which is all it may make. '
                . 'Spend them on the photographs and illustrations the design shows, not on icons or logos: an icon '
                . 'is a shape, so draw it in the section\'s own CSS or as inline SVG, and a strip of company logos is '
                . 'a placeholder for marks the site\'s owner will upload. Build the rest of the page without them.',
        ];
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
        $pictures = self::MAX_PICTURES;

        return <<<PROMPT
You have a design to replicate. Two things carry it, and keeping them apart is
what makes the result both faithful and editable:

  The THEME carries the frame — the header, the navigation, the footer, the
  typeface, the colours. Every page on the site wears it, and it is set from a
  list of tokens rather than written by hand.

  SECTIONS carry the page. Each one goes on in one of two ways, and choosing
  between them for each section is the most consequential thing you do here.

HOW TO BUILD, IN ORDER:
1. get_theme_contract — read it first. It says what a theme's views are handed.
2. create_theme — and its `kind` is the first real decision of the build. Ask
   what the design IS, not what it contains:
     landing        one page selling or explaining a thing
     editorial      a publication — a masthead, articles, topics
     documentation  reference material in a narrow column
   The kind sets the furniture and the proportions the theme starts from. A
   magazine started as a landing page arrives wearing a banner it does not
   want, and every round afterwards is spent undoing that.
   Name it after the site the design is for, as the design
   itself gives that name: the wordmark in the header, the name in the footer.
   Not a word describing what it is — "theme", "custom", "active", "design"
   all name the same thing every time, and a site collects one of these per
   build. No "Theme" on the end either; they are all themes.
3. use_theme_for_preview — point the preview page at it, straight away, while
   it is still empty. From here everything you do is visible on that page. The
   rest of the site keeps the theme it has: someone who pressed Build to see
   what a design might look like has not agreed to wear it yet, and nothing
   you do here reaches their homepage until they say so.
4. The frame, in two calls, before you write a single section.

   set_menu, with scope "design_preview" every time — the design's navigation,
   staged for the preview page and left off the rest of the site until the
   design is kept. The links across the header go in
   "primary". Anything at its right-hand end that stands apart — Login, Sign
   up, Create account — goes in "header_actions", where the last one renders
   as a button. The footer's list goes in "footer_quick_links". Until these
   are set the site shows Home, Articles and Topics on every page: the single
   most visible thing on the screen that is not the design's, and the one
   thing no amount of rewriting the layout will change. A link to a page this
   site does not have needs create_page first, or it goes in as a plain url.

   set_theme_tokens — one call. This is the
   frame the sections sit in, and they inherit from it: the typeface, the
   background, the ink, the accent, the colour of the full-width bands, the
   corner rounding, the page width, how heavy the headings are, whether they
   are in capitals, how much air sits between sections. Call it with no tokens
   to see the list, each with what it does. Some of them describe parts a
   given design may not have — set those and leave the rest. Written sections may use these in their own
   CSS — var(--accent), var(--font-display), var(--page-width) — which is how
   the page holds together instead of reading as a dozen separate designs.
5. {$where}
6. Now go through the design section by section, top to bottom, and write each
   one with add_designed_section. This is where the work is, and it has one
   job: make the page look like the design. Send:
     html — the section's markup, with the design's REAL words in it: its
       headings, its sentences, its prices, its button labels. Pictures go in
       as <img>. What you put in as text and images is exactly what the site's
       owner will be offered as a form to edit, so a section written with
       placeholder words is a section they have to rewrite by hand.
     css — that section's stylesheet, written against class names you used in
       the html. It is rewritten on the way in so it reaches nothing outside
       the section; a selector naming a class that is not in your markup
       matches nothing and is dropped, and you are told how many were.
     name — what the section is: "Hero", "Features", "FAQ". Required.
   Reproduce what the design shows: the arrangement, the proportions, the
   spacing, the type sizes, the shapes. You are writing the CSS, so nothing is
   out of reach — where the design puts three things across the page, write a
   grid of three; where a card rises over the band above it, position it.

   THE ONE EXCEPTION, and it is not a judgement call: a section that has to
   keep up with what the site holds is a BLOCK, always. A grid of articles is
   posts_grid, a grid of topics is categories_grid, a form someone fills in
   and sends is contact_form. Written as markup they freeze into a picture of
   the site on the day it was built and never change again. Put those in with
   add_row and add_block. Only these block types can be edited in the admin:
   {$editableBlocks}.

   Do not build anything else out of blocks. Deciding section by section
   whether a block might do reproduces the design's running order and loses
   its design, because every section then arrives wearing whichever shape the
   library has — and the decision comes out differently every run. Write them,
   and afterwards each one is looked at again to see whether a block could
   carry it without losing anything.

7. ARRANGEMENT for the block sections. A row is a band across the page and its
   blocks stack inside it unless you place them, so a design laid out in
   columns comes out as one column after another unless you say otherwise.
   Where the design puts things BESIDE each other, they belong in ONE row,
   each block carrying its own column_index (0, 1, 2) and column_width out of
   twelve: 4/4/4 for three equal columns, 6/6 for halves, 7/5 for a picture
   with a narrower column of words beside it.
   add_row takes width "full" for a section the design runs edge to edge and
   "contained" otherwise.
   Two listings side by side are two posts_grid blocks in the same row — and
   they need different categories, or both show the same articles and the page
   says everything twice.

8. update_page — one call, to title the page with the name the design gives
   the site. Read it off the design: the wordmark in the header, the name in
   the footer. This is the name the site takes if the design is kept, so a
   page still called "Design preview" leaves it nameless.
9. create_category — one per section or topic the design shows.
10. create_article — one per article the design shows, with status "published".
    A listing holding five articles needs five articles to exist. Write real
    headlines and copy suited to the subject; an empty listing matches nothing.
11. generate_image — for PHOTOGRAPHS and ILLUSTRATIONS the design shows that
    no supplied asset covers, and for nothing else. You may make at most
    {$pictures} in a build, so spend them where a picture is the content: the
    one behind a hero, the one beside an article.
    Say what the picture is AND what kind of picture it is, read off the
    design: a flat vector illustration, an isometric drawing, a photograph —
    and the palette it is drawn in. A design built from flat two-colour
    illustrations came back with photographs of people at desks, which is the
    right subject in the wrong language and reads as a different site.
    NOT for icons. An icon is a shape: draw it in the section's own CSS or as
    inline SVG in its markup. Asked for "an icon representing alerts" a model
    returns something between a sticker and an emoji, three of which will not
    match each other, where the design has three flat marks cut from the same
    geometry.
    NEVER for a logo, and the tool refuses them. A strip of company logos
    across a design is a placeholder showing where the site's own customers
    or partners go. Drawn, it becomes a row of approximated trademarks saying
    those companies are involved with a site they have never heard of. Set the
    strip out with their names as text, or leave it out, and say in your final
    message that the owner should upload the real marks.
    Use the url it returns exactly as given. A file listed in the asset
    inventory is something you are reading from, not a picture the site can
    serve, and an address you invent — "/path-to-illustration.jpg" — is a
    broken picture in the most prominent place on the page. Both are refused.
12. update_site_config — the site's description. Its name comes from the page
    title you set in step 8, and only if this design is kept.
13. write_theme_file for "articles", "article", "categories_index" and
    "categories_show", styled to match. Anything you leave out falls back to a
    plain built-in view: the site still works, it just is not your design.

WRITING A SECTION:
- Never a header, a navigation bar or a footer. The theme draws those on every
  page; a second set in a section sits underneath them, dead, and is refused.
- No <script> and no <style> — both are stripped. The stylesheet goes in css,
  where it can be scoped to the section.
- You do not need script for the things that usually need it. A section is put
  through the same machinery a copied one is, every time it renders:
    an accordion works — write the questions as buttons carrying
      aria-controls, and the answers as panels with those ids and hidden or
      display:none. They are paired up and made to open and close.
    a carousel works — write a track of slides with the overflow hidden, and
      arrows or dots beside it. The track becomes a strip the browser scrolls
      and swipes on its own, and your arrows and dots are wired to it.
    a form works — write the fields and a submit button; it is pointed back at
      this site rather than nowhere.
    an element written as the first frame of an animation (opacity:0, held
      below where it belongs) is put where the animation would have left it,
      rather than staying invisible.
    every <img> is rewritten to serve WebP at several widths, so write plain
      <img src alt> and leave sizes alone.
  So build the section as the design draws it and let this carry the behaviour.
- The design shows one width. Write the section so it also holds together on a
  narrow phone: a max-width media query in the same stylesheet, no fixed pixel
  widths wider than about 360px, nothing that has to scroll sideways.
- Keep the words legible on what is behind them. A pale heading on a pale band
  passes every check here and is unreadable on the page.
- One section per call, so each can be checked and corrected on its own. To
  correct a section afterwards, call the tool again with its replace_row_id —
  adding a second one leaves both, and a second section of the same name is
  refused.
- A section that is nothing but shapes, with no wording, no picture and no
  link, is refused: there would be nothing for its owner to edit.

THE CLASS NAMES THE BLOCKS RENDER WITH:
For the listing sections you build from blocks, and for the inside-page views
written in the last step. A section you write yourself has its own class names
and does not need any of these.
{$blockClasses}

RULES:
- update_custom_css is for a small adjustment afterwards, not for the design.
  A written section's styling belongs in the call that adds the section.
- A section written as markup in the THEME is a section the owner cannot edit.
  Sections go on the page, never into a theme view.
- Blade that would not compile is refused with the reason. Fix it and write
  again; nothing broken reaches a visitor.
- Adding another empty listing block does not add content. Only
  create_article and create_category do.
- A design is not missing anything by not having a hero, a call to action or a
  quotation. Add nothing it does not show: a section invented to fill a gap
  the design does not have is a section its owner has to find and delete.
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
- Start with get_page_blocks. It gives you each row's id, and a row holding one
  html block is a section you wrote: markup and stylesheet of its own.
- To correct such a section — its arrangement, its spacing, its type, its
  wording, any of it — call add_designed_section again with replace_row_id set
  to that row. It rewrites the section and its stylesheet in place. Never add a
  second section for something the page already has: two heroes are a worse
  mismatch than the one you were fixing.
- The header's links, the button at its right-hand end and the footer's list
  are a MENU, not markup: set_menu with scope "design_preview", one call per
  slot. A header that reads
  "Home Articles Topics" where the design reads something else is this and
  nothing else. Rewriting the layout to put the words in by hand takes them
  away from the site's owner, and rounds have been spent on it in vain.
- The frame is the theme, and most of what is wrong with a frame is a token: a
  colour that is too blue, type that is not the design's, corners too round, a
  band the wrong shade, headings too light or too large. set_theme_tokens fixes
  those in one call and cannot break the page. Remember the sections read those
  tokens too, so a token put right corrects every section at once.
- Only rewrite a theme view with write_theme_file when what is wrong is the
  header, the navigation, the footer or an inside page, and no token covers it.
  Keep everything that was already right.
- Listings, articles and topics are blocks and content: update_block,
  update_row, edit_article_content, create_article, update_site_config.
- Remove what the design does not have: delete_block, delete_row.

WHEN THE PAGE ALREADY MATCHES:
- If this round finds nothing of substance left to correct, spend it giving the
  page back to its owner instead. A written section holds its arrangement in
  markup: they can reword it and change its pictures, but they cannot add a
  card to it or take one out. Where a block would carry the same section, they
  can.
- So: convert_section_to_block on the sections a block fits — a hero, a band
  inviting an action, a row of equal boxes, a quotation, a list of questions.
  It refuses rather than dropping wording the block has nowhere to put, and it
  tells you the row to write again if you change your mind.
- Then look at the page against the design one more time. A converted section
  is painted by the theme, so it will not look identical; if one has moved away
  from the design, put it back with add_designed_section and its
  replace_row_id. Keep the ones that still match.
- Never convert a listing, a form, or a section whose arrangement is the design
  — an uneven grid, overlapping shapes, a picture beside the words.

RULES:
- Never create_theme again. One theme was written for this design; correct it.
- Never call create_article or create_category for a title or name that
  already exists. Call list_articles or list_categories if you are unsure.
- A section appearing twice on the page is a worse mismatch than the fault you
  were fixing. When something looks wrong, ask whether to change or delete it
  before you ask whether to add anything.
- A screenshot much taller than the design usually means repeated sections, or
  empty space to remove.
- Never move a section's words into the theme to make it look right — that
  takes them away from the person who owns the site.
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
