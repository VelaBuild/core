<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\Page;

/**
 * x402 Payment Required middleware.
 *
 * When enabled, AI agents (detected by User-Agent) must pay per-request
 * using the x402 protocol (HTTP 402 + signed USDC payment).
 * Regular browsers are never affected.
 *
 * Flow:
 * 1. AI agent requests page → 402 with PAYMENT-REQUIRED header
 * 2. Agent signs payment → retries with PAYMENT-SIGNATURE header
 * 3. Middleware verifies via facilitator → serves content or rejects
 *
 * @see https://x402.org
 */
class VelaX402Payment
{
    /**
     * Known AI crawler/agent User-Agent strings.
     */
    private const AI_USER_AGENTS = [
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
        'ClaudeBot', 'Claude-Web', 'anthropic-ai',
        'Google-Extended', 'Googlebot-Extended',
        'CCBot', 'PerplexityBot', 'Bytespider',
        'Amazonbot', 'Cohere-ai', 'AI2Bot',
        'Applebot-Extended', 'FacebookBot', 'Diffbot',
        'img2dataset', 'Scrapy', 'PetalBot',
    ];

    /**
     * Network configurations: CAIP-2 chain IDs and USDC contract addresses.
     */
    private const NETWORKS = [
        'base'     => ['chain_id' => 'eip155:8453',  'usdc' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'],
        'ethereum' => ['chain_id' => 'eip155:1',     'usdc' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48'],
        'polygon'  => ['chain_id' => 'eip155:137',   'usdc' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359'],
        'arbitrum' => ['chain_id' => 'eip155:42161', 'usdc' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831'],
        'optimism' => ['chain_id' => 'eip155:10',    'usdc' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85'],
    ];

    public function handle(Request $request, Closure $next)
    {
        if (!config('vela.x402.enabled') || !config('vela.x402.pay_to')) {
            return $next($request);
        }

        // Only gate AI agents — regular browsers always pass
        if (!$this->isAiAgent($request)) {
            return $next($request);
        }

        // Per-page mode: only gate pages that have x402 explicitly enabled
        $pageOverride = null;
        if (config('vela.x402.mode') === 'per_page') {
            $pageOverride = $this->resolvePageForRequest($request);
            if (!$pageOverride || !$pageOverride->x402_enabled) {
                return $next($request);
            }
        }

        // Check for payment signature header
        $signature = $request->header('PAYMENT-SIGNATURE')
            ?? $request->header('X-PAYMENT')
            ?? $request->header('X-Payment-Signature');

        if (!$signature) {
            return $this->respondPaymentRequired($request, $pageOverride);
        }

        // Verify the payment
        $verification = $this->verifyPayment($signature, $request, $pageOverride);

        if ($verification['valid']) {
            $response = $next($request);

            // Attach settlement receipt
            if (!empty($verification['receipt'])) {
                $response->headers->set('PAYMENT-RESPONSE', base64_encode(json_encode($verification['receipt'])));
            }

            return $response;
        }

        return response()->json([
            'error' => 'Payment verification failed',
            'message' => $verification['reason'] ?? 'The payment could not be verified.',
        ], 402);
    }

    private function isAiAgent(Request $request): bool
    {
        $ua = $request->userAgent() ?? '';
        if ($ua === '') {
            return false;
        }
        foreach (self::AI_USER_AGENTS as $bot) {
            if (stripos($ua, $bot) !== false) {
                return true;
            }
        }
        return false;
    }

    private function resolvePageForRequest(Request $request): ?Page
    {
        $path = trim($request->path(), '/');

        // Home page
        if ($path === '' || $path === '/') {
            $slug = 'home';
        } else {
            // Strip known prefixes (posts/categories are content, not pages)
            if (str_starts_with($path, 'posts/') || str_starts_with($path, 'categories/')) {
                return null;
            }
            $slug = $path;
        }

        return Page::where('slug', $slug)
            ->whereIn('status', ['published', 'unlisted'])
            ->select('id', 'slug', 'x402_enabled', 'x402_price_usd')
            ->first();
    }

    private function getEffectivePrice(?Page $page): float
    {
        if ($page && $page->x402_price_usd !== null && $page->x402_price_usd !== '') {
            return (float) $page->x402_price_usd;
        }
        return (float) config('vela.x402.price_usd', '0.01');
    }

    private function buildPaymentRequirements(Request $request, ?Page $page = null): array
    {
        $network = config('vela.x402.network', 'base');
        $net = self::NETWORKS[$network] ?? self::NETWORKS['base'];
        $priceUsd = $this->getEffectivePrice($page);

        // USDC has 6 decimal places: $0.01 = 10000 atomic units
        $amountAtomic = (string) (int) round($priceUsd * 1_000_000);

        return [
            'x402Version' => 2,
            'accepts' => [
                [
                    'scheme' => 'exact',
                    'network' => $net['chain_id'],
                    'maxAmountRequired' => $amountAtomic,
                    'asset' => $net['usdc'],
                    'payTo' => config('vela.x402.pay_to'),
                    'maxTimeoutSeconds' => 30,
                    'resource' => '/' . ltrim($request->path(), '/'),
                    'description' => config('vela.x402.description', 'Access to website content'),
                ],
            ],
        ];
    }

    private function respondPaymentRequired(Request $request, ?Page $page = null)
    {
        $requirements = $this->buildPaymentRequirements($request, $page);
        $encoded = base64_encode(json_encode($requirements));

        return response()->json([
            'error' => 'Payment Required',
            'message' => 'This content requires payment for AI agent access. Include a signed payment in the PAYMENT-SIGNATURE header.',
            'x402' => $requirements,
        ], 402)->withHeaders([
            'PAYMENT-REQUIRED' => $encoded,
            'X-Payment-Required' => $encoded,
            'Access-Control-Expose-Headers' => 'PAYMENT-REQUIRED, PAYMENT-RESPONSE',
        ]);
    }

    private function verifyPayment(string $signature, Request $request, ?Page $page = null): array
    {
        $facilitatorUrl = rtrim(config('vela.x402.facilitator_url', 'https://x402.org/facilitator'), '/');

        try {
            $payload = json_decode(base64_decode($signature, true), true);
            if (!$payload) {
                return ['valid' => false, 'reason' => 'Invalid payment signature encoding'];
            }

            $requirements = $this->buildPaymentRequirements($request, $page);

            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$facilitatorUrl}/verify", [
                    'payload' => $payload,
                    'paymentRequirements' => $requirements,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $isValid = $data['isValid'] ?? $data['success'] ?? false;

                if ($isValid) {
                    Log::info('x402 payment verified', [
                        'resource' => $request->path(),
                        'network' => config('vela.x402.network'),
                        'amount' => $this->getEffectivePrice($page),
                    ]);
                }

                return [
                    'valid' => (bool) $isValid,
                    'receipt' => $data['settlement'] ?? $data,
                    'reason' => $isValid ? null : ($data['reason'] ?? 'Facilitator rejected payment'),
                ];
            }

            return ['valid' => false, 'reason' => 'Facilitator returned ' . $response->status()];

        } catch (\Exception $e) {
            Log::error('x402 verification error: ' . $e->getMessage());
            return ['valid' => false, 'reason' => 'Payment verification service unavailable'];
        }
    }
}
