<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Client;

/**
 * The settings one send ends up with: the client's, overridden by what the request set.
 *
 * @internal
 */
final readonly class TransferOptions
{
    /**
     * @param array<int, mixed>                                 $curl
     * @param string|resource|\Psr\Http\Message\StreamInterface|null $sink
     */
    public function __construct(
        public Timeouts $timeouts,
        public RetryPolicy $retry,
        public Redirects $redirects,
        public ?int $maxResponseSize,
        public bool $throwOnError,
        public bool $userAgentExact,
        public array $curl = [],
        public mixed $sink = null,
        public ?\Closure $onProgress = null,
    ) {
    }

    /**
     * The client's settings with the request's overrides on top.
     *
     * @param array<string, mixed> $overrides CastRequest::transferOptions()
     */
    public function merge(array $overrides): self
    {
        $timeouts = $overrides['timeouts'] ?? $this->timeouts;
        if (isset($overrides['timeout'])) {   // withTimeout() on the request: total and connect only
            $timeouts = $timeouts->withTotal($overrides['timeout']['total']);
            if ($overrides['timeout']['connect'] !== null) {
                $timeouts = $timeouts->withConnect($overrides['timeout']['connect']);
            }
        }

        return new self(
            timeouts: $timeouts,
            retry: $overrides['retry'] ?? $this->retry,
            redirects: $overrides['redirects'] ?? $this->redirects,
            maxResponseSize: array_key_exists('maxResponseSize', $overrides)
                ? $overrides['maxResponseSize']
                : $this->maxResponseSize,
            throwOnError: $overrides['throwOnError'] ?? $this->throwOnError,
            userAgentExact: $overrides['userAgentExact'] ?? $this->userAgentExact,
            curl: array_replace($this->curl, $overrides['curl'] ?? []),
            sink: $overrides['sink'] ?? $this->sink,
            onProgress: $overrides['onProgress'] ?? $this->onProgress,
        );
    }

    /** The same settings with one attempt's total timeout capped at `$seconds` (for a deadline). */
    public function withAttemptLimit(float $seconds): self
    {
        $total = $this->timeouts->total === null ? $seconds : min($this->timeouts->total, $seconds);

        return new self(
            $this->timeouts->withTotal(max(0.001, $total)),
            $this->retry,
            $this->redirects,
            $this->maxResponseSize,
            $this->throwOnError,
            $this->userAgentExact,
            $this->curl,
            $this->sink,
            $this->onProgress,
        );
    }
}
