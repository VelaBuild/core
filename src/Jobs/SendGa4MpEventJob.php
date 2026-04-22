<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use VelaBuild\Core\Services\TrackingService;

/**
 * Send a GA4 Measurement Protocol event from a worker. Used for
 * server-side events the customer's browser can't fire itself —
 * admin-initiated refunds, webhook-triggered state changes, etc.
 *
 * GA4 MP is best-effort: Google doesn't return errors for malformed events
 * unless you use the /debug/ endpoint. We log the response body anyway so
 * integration problems show up in logs.
 */
class SendGa4MpEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 600];

    public function __construct(
        public string $eventName,
        public array $params,
        public string $clientId,
    ) {}

    public function handle(TrackingService $tracking): void
    {
        $tracking->sendGa4MpEvent($this->eventName, $this->params, $this->clientId);
    }
}
