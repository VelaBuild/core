<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Services\AiProviderManager;
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

            if (in_array($ext, $skipExtensions)) {
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
     * Execute the initial build by driving the chat tool system with design context.
     */
    public function runBuildLoop(array &$context, string $designPath, string $url): void
    {
        $textProvider = $this->aiManager->resolveTextProvider();
        $user = VelaUser::where('is_admin', 1)->first() ?? VelaUser::first();

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
            Log::error('DesignBuilder: AI provider returned null response during build loop');
            return;
        }

        $maxToolIterations = 10;
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
        $textProvider = $this->aiManager->resolveTextProvider();

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
            Log::error('DesignBuilder: AI returned no response during QA comparison');
            return [
                'passed' => false,
                'summary' => 'AI provider returned no response.',
                'fixes' => [],
                'report' => '# QA Report\n\nAI provider returned no response.',
                'usage' => ['input' => 0, 'output' => 0],
            ];
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
        $textProvider = $this->aiManager->resolveTextProvider();
        $user = VelaUser::where('is_admin', 1)->first() ?? VelaUser::first();

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => 'Design Builder Fix - ' . now()->format('Y-m-d H:i'),
        ]);

        $systemPrompt = $this->buildSystemPrompt($context);

        $fixPrompt = 'The visual QA comparison found these issues that need fixing:' . "\n"
            . json_encode($fixes, JSON_PRETTY_PRINT)
            . "\n\nApply these fixes using the available tools. Focus on the most impactful changes first."
            . "\nUse update_custom_css for styling changes. Check current CSS first with get_custom_css.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $fixPrompt],
        ];

        $availableTools = $this->toolRegistry->forUser($user);
        $formattedTools = $this->getFormattedTools($textProvider, $availableTools);

        $this->progress('Applying QA fixes...');
        $response = $textProvider->chat($messages, $formattedTools, 4096);

        if (!$response) {
            Log::error('DesignBuilder: AI provider returned null response during fix loop');
            return;
        }

        $maxToolIterations = 10;
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
     */
    private function buildSystemPrompt(array $context): string
    {
        $siteDesc = $this->siteContext->getDescription();
        $instructionsJson = json_encode($context['instructions'] ?? [], JSON_PRETTY_PRINT);
        $assetsJson = json_encode($context['assets'] ?? [], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a site builder AI for {$siteDesc}.
You have design assets to replicate. Your job is to build the site to match the design as closely as possible.

AVAILABLE ACTIONS:
- Switch templates to best match the design
- Create pages with appropriate content
- Update CSS colors and custom CSS to match the design
- Update site configuration (site name, etc.)
- Create articles/content

DESIGN CONTEXT:
{$instructionsJson}

ASSET INVENTORY:
{$assetsJson}

IMPORTANT RULES:
- Start by examining the design and choosing the best template
- Then customize colors and CSS to match
- Create content that matches the design text/layout
- Use update_custom_css for ALL visual styling changes
- Check existing CSS with get_custom_css before making changes
- Work methodically: template first, then colors, then content, then fine-tuning
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
