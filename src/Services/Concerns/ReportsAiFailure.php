<?php

namespace VelaBuild\Core\Services\Concerns;

/**
 * Keeps the provider's own words for the last refused call.
 *
 * The AiTextProvider contract returns null on failure, which tells a caller
 * that something went wrong but never what — so an operator was shown
 * "no response" while the log held "You have no credits remaining" or
 * "API key is invalid". Providers record the reason here and callers that
 * want to show it can ask for it.
 */
trait ReportsAiFailure
{
    private ?string $lastAiError = null;

    public function lastError(): ?string
    {
        return $this->lastAiError;
    }

    /**
     * Record a refusal, preferring the message the provider itself wrote.
     */
    private function recordAiFailure(int $status, string $body): void
    {
        $decoded = json_decode($body, true);

        $message = is_array($decoded)
            ? ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? null)
            : null;

        $this->lastAiError = is_string($message) && $message !== ''
            ? rtrim($message, '.') . ' (HTTP ' . $status . ')'
            : 'The provider refused the request with HTTP ' . $status . '.';
    }

    private function recordAiException(\Throwable $e): void
    {
        $this->lastAiError = $e->getMessage() ?: get_class($e);
    }
}
