<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport;

use Flytachi\Winter\Cast\Client\TransferOptions;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Message\TransferInfo;

/**
 * A transport for tests: answers from a queue instead of the network and records what was sent.
 *
 * ```
 * $mock = new MockTransport(
 *     CastResponse::fromJson(['id' => 42], 201),
 *     fn(CastRequest $request) => CastResponse::fromString('', 204),
 *     MockTransport::fail(),
 * );
 * $client = CastClient::create()->withBaseUrl('https://api.test')->withTransport($mock);
 *
 * // … code under test …
 *
 * $mock->lastRequest()?->getUri();   // https://api.test/users
 * ```
 *
 * Everything around the transport stays real: base URL, default headers, middleware, retries,
 * redirects (queue a 302 with a Location and then the target's response), throwOnError.
 */
final class MockTransport implements Transport
{
    /** @var list<CastResponse|\Closure|\Throwable> */
    private array $queue = [];

    /** @var list<CastRequest> */
    private array $requests = [];

    /**
     * @param CastResponse|\Throwable|callable(CastRequest, TransferOptions): CastResponse ...$responses
     *        answers in the order of sending; a function decides itself, a Throwable is thrown
     */
    public function __construct(CastResponse|\Throwable|callable ...$responses)
    {
        $this->append(...$responses);
    }

    /**
     * A queue entry that fails like a dropped connection.
     *
     * @param int $curlErrno the cURL error; the default (7, could not connect) is retried by RetryPolicy
     */
    public static function fail(
        string $message = 'Connection refused',
        int $curlErrno = CURLE_COULDNT_CONNECT,
    ): \Closure {
        return static fn(CastRequest $request): never => throw new NetworkException($message, $request, $curlErrno);
    }

    /** Adds answers to the end of the queue. */
    public function append(CastResponse|\Throwable|callable ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = is_callable($response) && !$response instanceof CastResponse
                ? $response(...)
                : $response;
        }

        return $this;
    }

    public function execute(CastRequest $request, TransferOptions $options): CastResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue)
            ?? throw new CastException(sprintf(
                'MockTransport has no response queued for %s %s (request #%d)',
                $request->getMethod(),
                $request->getUri(),
                count($this->requests),
            ));

        if ($next instanceof \Throwable) {
            throw $next;
        }
        $response = $next instanceof \Closure ? $next($request, $options) : $next;

        return $response->withTransfer(
            $request,
            new TransferInfo(effectiveUri: (string) $request->getUri(), protocol: $response->getProtocolVersion()),
            false,
        );
    }

    /** @return list<CastRequest> what was sent, in order (each attempt and each redirect hop) */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?CastRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    /** How many queued answers are still unused — 0 at the end of a test means all were consumed. */
    public function remaining(): int
    {
        return count($this->queue);
    }
}
