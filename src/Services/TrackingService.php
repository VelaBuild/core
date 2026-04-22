<?php

namespace VelaBuild\Core\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Marketing / analytics tracking for the public site.
 *
 * Surfaces four capabilities:
 *
 *   1. {@see headConfig()} — returns the set of pixel IDs active on this
 *      site, so the head partial can emit GA4 / GTM / Meta Pixel / Google
 *      Ads loader tags. GDPR-gated on the client via `vela:consent:analytics`.
 *
 *   2. {@see queueEvent()} — stashes an ecommerce event for the current
 *      request. The tracking-events partial serialises the queue into
 *      `window.__velaTrack` which the dispatcher script fires to gtag/fbq
 *      once consent has been granted.
 *
 *   3. {@see sendMetaCapiEvent()} — server-side Conversions API sender for
 *      critical events (purchase). Hashes email/phone to SHA-256 per Meta's
 *      requirements. Uses an event_id that matches the browser-fired event
 *      so Meta de-duplicates.
 *
 *   4. {@see consentGateEnabled()} — true when GDPR mode is on. Callers
 *      shouldn't usually care — the partials handle it — but a handful of
 *      controllers need to know.
 *
 * Why no marketing-category consent? The existing consent blade has only
 * `functional` + `analytics`. For v0 we gate marketing pixels on
 * `analytics` too. A separate category is a straightforward follow-up
 * (see cookie-consent.blade.php to add it).
 */
class TrackingService
{
    /** Queue of events for the CURRENT request only. Flushed by the partial. */
    private array $queue = [];

    /**
     * Advanced Matching identity blob for the current request, in two forms:
     *   - 'meta'   → hashed per Meta's spec (em, ph, fn, ln, ct, st, zp, country)
     *   - 'google' → plain (Google hashes server-side via gtag('set','user_data',…))
     * Set by setAdvancedMatching(); read (without clearing) by the partials.
     */
    private ?array $advancedMatching = null;

    public function __construct(
        private ToolSettingsService $tools,
    ) {}

    // ── Pixel presence helpers ──────────────────────────────────────────────

    public function ga4Id(): ?string
    {
        return $this->tools->get('ga_measurement_id') ?: null;
    }

    public function gtmId(): ?string
    {
        return $this->tools->get('gtm_container_id') ?: null;
    }

    public function metaPixelId(): ?string
    {
        return $this->tools->get('meta_pixel_id') ?: null;
    }

    public function googleAdsId(): ?string
    {
        return $this->tools->get('google_ads_id') ?: null;
    }

    public function googleAdsPurchaseLabel(): ?string
    {
        return $this->tools->get('google_ads_purchase_label') ?: null;
    }

    public function hasAnyClientPixel(): bool
    {
        return (bool) ($this->ga4Id() || $this->gtmId() || $this->metaPixelId() || $this->googleAdsId());
    }

    public function hasMetaCapi(): bool
    {
        return !empty($this->metaPixelId()) && !empty($this->tools->get('meta_capi_access_token'));
    }

    public function hasGa4Mp(): bool
    {
        return !empty($this->ga4Id()) && !empty($this->tools->get('ga4_api_secret'));
    }

    public function consentGateEnabled(): bool
    {
        return (bool) config('vela.gdpr.enabled', false);
    }

    /**
     * Snapshot for the head partial.
     *
     * @return array{
     *   ga4_id: ?string,
     *   gtm_id: ?string,
     *   meta_pixel_id: ?string,
     *   google_ads_id: ?string,
     *   consent_gate: bool,
     * }
     */
    public function headConfig(): array
    {
        return [
            'ga4_id'        => $this->ga4Id(),
            'gtm_id'        => $this->gtmId(),
            'meta_pixel_id' => $this->metaPixelId(),
            'google_ads_id' => $this->googleAdsId(),
            'consent_gate'  => $this->consentGateEnabled(),
        ];
    }

    // ── Client-side event queue ─────────────────────────────────────────────

    /**
     * Queue an event to be flushed into `window.__velaTrack` by the partial.
     *
     * Event shape (GA4-compatible names; partial maps to fbq too):
     *   $name = 'view_item' | 'view_item_list' | 'add_to_cart' | 'view_cart'
     *         | 'begin_checkout' | 'purchase'
     *   $data = ['currency' => 'USD', 'value' => 9.99, 'items' => [...]]
     *           plus event-specific fields.
     *
     * For `purchase`, include `transaction_id` and `event_id` so Meta
     * browser+CAPI dedup works.
     */
    public function queueEvent(string $name, array $data = []): void
    {
        $this->queue[] = ['event' => $name, 'data' => $data];
    }

    /** @return array<int, array{event: string, data: array}> */
    public function pullQueue(): array
    {
        $out = $this->queue;
        $this->queue = [];
        return $out;
    }

    // ── Advanced Matching ──────────────────────────────────────────────────

    /**
     * Set Advanced Matching identity from a customer record (e.g. on the
     * checkout success page once the buyer's details are known). Builds
     * BOTH the Meta-shaped (hashed) and Google-shaped (plain) variants
     * from the same input — partials pick the right one for each pixel.
     *
     * Expected keys (any subset):
     *   email, phone, first_name, last_name, city, state, postal_code, country
     */
    public function setAdvancedMatching(array $u): void
    {
        $email = isset($u['email']) ? trim(strtolower((string) $u['email'])) : null;
        $phone = isset($u['phone']) ? preg_replace('/\D+/', '', (string) $u['phone']) : null;

        // First/last from a single "name" if not split. Best-effort split on
        // first space — order forms typically have one Name field.
        $first = $u['first_name'] ?? null;
        $last  = $u['last_name']  ?? null;
        if ((!$first || !$last) && !empty($u['name'])) {
            $parts = preg_split('/\s+/', trim((string) $u['name']), 2);
            $first = $first ?: ($parts[0] ?? null);
            $last  = $last  ?: ($parts[1] ?? null);
        }

        $city    = $u['city']        ?? null;
        $state   = $u['state']       ?? null;
        $zip     = $u['postal_code'] ?? null;
        $country = $u['country']     ?? null;

        $hash = fn ($v) => $v ? hash('sha256', strtolower(trim((string) $v))) : null;

        $meta = array_filter([
            'em'      => $hash($email),
            'ph'      => $phone ? hash('sha256', $phone) : null,
            'fn'      => $hash($first),
            'ln'      => $hash($last),
            'ct'      => $hash($city),
            'st'      => $hash($state),
            'zp'      => $hash($zip),
            'country' => $hash($country),
        ], fn ($v) => $v !== null);

        $google = array_filter([
            'email'        => $email,
            'phone_number' => $phone ? '+' . ltrim($phone, '+') : null,
            'address'      => array_filter([
                'first_name'  => $first,
                'last_name'   => $last,
                'city'        => $city,
                'region'      => $state,
                'postal_code' => $zip,
                'country'     => $country,
            ], fn ($v) => $v !== null && $v !== '') ?: null,
        ], fn ($v) => $v !== null);

        $this->advancedMatching = [
            'meta'   => $meta,
            'google' => $google,
        ];
    }

    public function getAdvancedMatching(): ?array
    {
        return $this->advancedMatching;
    }

    // ── Server-side Meta CAPI ───────────────────────────────────────────────

    /**
     * Enqueue a Conversions API event via the queue worker. Enriches
     * user_data with IP/UA/fbp/fbc and the source URL FROM THE REQUEST NOW,
     * then dispatches a job — so the queued payload has everything it needs
     * and doesn't reach into a non-existent Request on the worker.
     *
     * Prefer this over {@see sendMetaCapiEvent()} for reliability: checkout
     * success doesn't block on Meta's latency, and retries on transient
     * failure are automatic.
     *
     * @param array $event See sendMetaCapiEvent for shape.
     */
    public function queueMetaCapiEvent(array $event, ?Request $request = null): bool
    {
        if (!$this->hasMetaCapi()) {
            return false;
        }

        // Enrich now — Request isn't serialisable for the queue.
        $event['user_data'] = $this->enrichUserData($event['user_data'] ?? [], $request);
        $event['event_source_url'] = $event['event_source_url'] ?? url()->current();
        $event['event_time'] = $event['event_time'] ?? time();

        \VelaBuild\Core\Jobs\SendMetaCapiEventJob::dispatch($event);
        return true;
    }

    /**
     * Synchronously send a Conversions API event. Called by the queued job
     * from the worker. Can also be called inline if you really need blocking
     * behaviour — but you probably want queueMetaCapiEvent() instead.
     *
     * @param array{
     *   event_name: string,
     *   event_time?: int,
     *   event_id?: string,
     *   event_source_url?: string,
     *   action_source?: string,
     *   user_data?: array,
     *   custom_data?: array,
     * } $event
     */
    public function sendMetaCapiEvent(array $event, ?Request $request = null): bool
    {
        if (!$this->hasMetaCapi()) {
            return false;
        }

        $pixelId = $this->metaPixelId();
        $token   = $this->tools->get('meta_capi_access_token');
        $testCode = $this->tools->get('meta_capi_test_event_code');

        $userData = $this->enrichUserData($event['user_data'] ?? [], $request);

        $payload = [
            'event_name'       => $event['event_name'],
            'event_time'       => $event['event_time'] ?? time(),
            'action_source'    => $event['action_source'] ?? 'website',
            'event_source_url' => $event['event_source_url'] ?? url()->current(),
            'user_data'        => $userData,
        ];
        if (!empty($event['event_id'])) {
            $payload['event_id'] = $event['event_id'];
        }
        if (!empty($event['custom_data'])) {
            $payload['custom_data'] = $event['custom_data'];
        }

        $body = ['data' => [$payload]];
        if ($testCode) {
            $body['test_event_code'] = $testCode;
        }

        try {
            $resp = Http::timeout(10)
                ->withQueryParameters(['access_token' => $token])
                ->post("https://graph.facebook.com/v18.0/{$pixelId}/events", $body);
        } catch (\Throwable $e) {
            Log::warning('Meta CAPI network error', ['error' => $e->getMessage()]);
            return false;
        }

        if (!$resp->successful()) {
            Log::warning('Meta CAPI non-2xx', [
                'status'     => $resp->status(),
                'body'       => $resp->body(),
                'event_name' => $event['event_name'],
            ]);
            return false;
        }

        return true;
    }

    // ── Server-side GA4 (Measurement Protocol) ──────────────────────────────

    /**
     * Enqueue a GA4 Measurement Protocol event. Used for server-to-server
     * events — admin refunds, webhook state transitions. The customer's
     * browser doesn't fire these, so server MP is the only option.
     */
    public function queueGa4MpEvent(string $eventName, array $params, string $clientId): bool
    {
        if (!$this->hasGa4Mp()) {
            return false;
        }
        \VelaBuild\Core\Jobs\SendGa4MpEventJob::dispatch($eventName, $params, $clientId);
        return true;
    }

    /**
     * Synchronously send a GA4 MP event. Called from the worker; also
     * callable inline if truly needed. See
     * https://developers.google.com/analytics/devguides/collection/protocol/ga4
     */
    public function sendGa4MpEvent(string $eventName, array $params, string $clientId): bool
    {
        if (!$this->hasGa4Mp()) {
            return false;
        }

        $measurementId = $this->ga4Id();
        $apiSecret     = $this->tools->get('ga4_api_secret');

        $body = [
            'client_id' => $clientId,
            'events'    => [[
                'name'   => $eventName,
                'params' => $params,
            ]],
        ];

        try {
            $resp = Http::timeout(10)
                ->withQueryParameters([
                    'measurement_id' => $measurementId,
                    'api_secret'     => $apiSecret,
                ])
                ->post('https://www.google-analytics.com/mp/collect', $body);
        } catch (\Throwable $e) {
            Log::warning('GA4 MP network error', ['error' => $e->getMessage()]);
            return false;
        }

        // GA4 MP returns 204 for success. Non-2xx is rare — log if it happens.
        if (!$resp->successful() && $resp->status() !== 204) {
            Log::warning('GA4 MP non-2xx', [
                'status'     => $resp->status(),
                'body'       => $resp->body(),
                'event_name' => $eventName,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Attach IP/UA/fbp/fbc from the Request (idempotent — only fills blanks)
     * and SHA-256 hash the PII fields Meta expects hashed. Safe to run twice.
     */
    private function enrichUserData(array $userData, ?Request $request): array
    {
        if ($request) {
            if (empty($userData['client_ip_address']) && $request->ip()) {
                $userData['client_ip_address'] = $request->ip();
            }
            if (empty($userData['client_user_agent']) && $request->userAgent()) {
                $userData['client_user_agent'] = $request->userAgent();
            }
            if (empty($userData['fbc']) && $fbc = $request->cookie('_fbc')) {
                $userData['fbc'] = $fbc;
            }
            if (empty($userData['fbp']) && $fbp = $request->cookie('_fbp')) {
                $userData['fbp'] = $fbp;
            }
        }

        return $this->hashUserData($userData);
    }

    /**
     * SHA-256 + lowercase + trim the fields Meta wants hashed. See
     * https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters
     */
    private function hashUserData(array $u): array
    {
        $hashFields = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country'];

        // Support the friendlier keys callers might pass — email/phone — and remap.
        if (!empty($u['email'])) {
            $u['em'] = $u['email'];
            unset($u['email']);
        }
        if (!empty($u['phone'])) {
            // Strip non-digits before hashing — Meta expects normalised e164-ish.
            $u['ph'] = preg_replace('/\D+/', '', (string) $u['phone']);
            unset($u['phone']);
        }

        foreach ($hashFields as $k) {
            if (!empty($u[$k]) && !$this->looksHashed($u[$k])) {
                $u[$k] = hash('sha256', strtolower(trim((string) $u[$k])));
            }
        }

        return $u;
    }

    private function looksHashed(string $v): bool
    {
        // 64-char hex = already SHA-256.
        return strlen($v) === 64 && ctype_xdigit($v);
    }
}
