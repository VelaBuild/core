<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use VelaBuild\Core\Services\TrackingService;

/**
 * Ship a Meta Conversions API event from a worker, not the web request.
 *
 * Why: CAPI is a network call to Meta that can take 500ms+ and sometimes
 * times out. Blocking checkout's success page on it is wrong — Meta accepts
 * events up to 7 days late, so a queued delivery is strictly better.
 *
 * The event payload here is ALREADY enriched with IP/UA/fbp/fbc (those live
 * on the Request and don't serialise) — the queue method on TrackingService
 * extracts them before dispatching.
 */
class SendMetaCapiEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Exponential-ish backoff: 10s, 30s, 2m, 5m, 15m. */
    public array $backoff = [10, 30, 120, 300, 900];

    public function __construct(
        public array $event,
    ) {}

    public function handle(TrackingService $tracking): void
    {
        // Request is null — user_data enrichment happened at dispatch time.
        $tracking->sendMetaCapiEvent($this->event, null);
    }
}
