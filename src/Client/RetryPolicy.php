<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Client;

use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Psr\Http\Message\ResponseInterface;

/**
 * What to retry and how. Retries run inside the client, after middleware: middleware runs
 * once per request, each attempt goes from there to the network.
 *
 * By default, once enabled:
 * - methods: GET, HEAD, PUT, DELETE, OPTIONS, TRACE — idempotent (RFC 9110); POST and PATCH
 *   only with withNonIdempotent(), since a retried POST can do the thing twice;
 * - causes: transient network failures only (refused, dropped, timed out, empty reply) — not
 *   TLS errors, an unknown host or a malformed URL, which a retry never fixes;
 * - statuses: none, until withStatuses() names some (e.g. 429, 502, 503, 504).
 *
 * ```
 * RetryPolicy::exponential(3);                                   // 500 ms, 1 s (±30 %)
 * RetryPolicy::exponential(5)->withStatuses(429, 503)->withMaxDelay(10_000);
 * ```
 */
final readonly class RetryPolicy
{
    private const array IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    /**
     * @param list<string> $methods upper-cased
     * @param list<int>    $statuses
     */
    private function __construct(
        public int $attempts,
        public int $delayMs,
        public float $multiplier,
        public float $jitter,
        public int $maxDelayMs,
        public array $methods,
        public array $statuses,
        public bool $respectRetryAfter,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Attempts must be at least 1');
        }
        if ($delayMs < 0 || $maxDelayMs < 0) {
            throw new InvalidArgumentException('Delays must not be negative');
        }
    }

    /** No retries — the default. */
    public static function none(): self
    {
        return new self(1, 0, 1.0, 0.0, 0, self::IDEMPOTENT, [], true);
    }

    /** `$attempts` in total; pauses `$delayMs`, ×2, ×4 … with ±30 % jitter, at most 30 s. */
    public static function exponential(int $attempts = 3, int $delayMs = 500): self
    {
        return new self($attempts, $delayMs, 2.0, 0.3, 30_000, self::IDEMPOTENT, [], true);
    }

    /** `$attempts` in total with equal pauses; a longer `Retry-After` is honoured up to 30 s. */
    public static function fixed(int $attempts, int $delayMs): self
    {
        return new self($attempts, $delayMs, 1.0, 0.0, max($delayMs, 30_000), self::IDEMPOTENT, [], true);
    }

    /** Which methods may be retried. */
    public function withMethods(Method|string ...$methods): self
    {
        $names = array_values(array_unique(array_map(
            static fn(Method|string $m) => strtoupper($m instanceof Method ? $m->name : $m),
            $methods,
        )));

        return new self(
            $this->attempts,
            $this->delayMs,
            $this->multiplier,
            $this->jitter,
            $this->maxDelayMs,
            $names,
            $this->statuses,
            $this->respectRetryAfter,
        );
    }

    /** Allow POST and PATCH too — only when the API makes repeating them safe (idempotency keys). */
    public function withNonIdempotent(): self
    {
        return $this->withMethods(...self::IDEMPOTENT, ...['POST', 'PATCH']);
    }

    /** Retry on these response statuses as well (429, 502, 503, 504 are the usual ones). */
    public function withStatuses(int ...$statuses): self
    {
        return new self(
            $this->attempts,
            $this->delayMs,
            $this->multiplier,
            $this->jitter,
            $this->maxDelayMs,
            $this->methods,
            array_values(array_unique($statuses)),
            $this->respectRetryAfter,
        );
    }

    public function withMaxDelay(int $ms): self
    {
        return new self(
            $this->attempts,
            $this->delayMs,
            $this->multiplier,
            $this->jitter,
            $ms,
            $this->methods,
            $this->statuses,
            $this->respectRetryAfter,
        );
    }

    /** Wait as long as `Retry-After` asks (capped by maxDelay); on by default. */
    public function respectRetryAfter(bool $respect = true): self
    {
        return new self(
            $this->attempts,
            $this->delayMs,
            $this->multiplier,
            $this->jitter,
            $this->maxDelayMs,
            $this->methods,
            $this->statuses,
            $respect,
        );
    }

    public function enabled(): bool
    {
        return $this->attempts > 1;
    }

    /** Whether attempt number `$attempt` (from 1) that failed with `$e` may be followed by another. */
    public function retriesFailure(string $method, int $attempt, NetworkException $e): bool
    {
        return $attempt < $this->attempts
            && in_array(strtoupper($method), $this->methods, true)
            && $e->isTransient();
    }

    /** Whether attempt number `$attempt` (from 1) that got `$response` may be followed by another. */
    public function retriesResponse(string $method, int $attempt, ResponseInterface $response): bool
    {
        return $attempt < $this->attempts
            && in_array(strtoupper($method), $this->methods, true)
            && in_array($response->getStatusCode(), $this->statuses, true);
    }

    /** Milliseconds to wait after attempt number `$attempt` (from 1). */
    public function delayAfter(int $attempt, ?ResponseInterface $response = null): int
    {
        if ($this->respectRetryAfter && $response !== null) {
            $retryAfter = self::retryAfterMs($response->getHeaderLine('Retry-After'));
            if ($retryAfter !== null) {
                return min($retryAfter, $this->maxDelayMs);
            }
        }

        $delay = $this->delayMs * ($this->multiplier ** ($attempt - 1));
        if ($this->jitter > 0) {
            $range = $delay * $this->jitter;
            $delay += random_int((int) -$range, (int) $range);
        }

        return (int) min(max(0, $delay), $this->maxDelayMs);
    }

    /** `Retry-After` is seconds or an HTTP date. */
    private static function retryAfterMs(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $seconds = (int) $value;   // PHP_INT_MAX for an absurdly large value

            return $seconds > intdiv(PHP_INT_MAX, 1000) ? PHP_INT_MAX : $seconds * 1000;
        }
        $time = strtotime($value);

        return $time === false ? null : max(0, ($time - time()) * 1000);
    }
}
