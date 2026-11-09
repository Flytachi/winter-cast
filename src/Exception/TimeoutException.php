<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * A timeout expired: connecting, the whole attempt (`total`), the stall limit (`idle`),
 * or the deadline of the whole operation including retries.
 */
class TimeoutException extends NetworkException
{
    public function __construct(
        string $message,
        RequestInterface $request,
        private readonly bool $connect = false,
        private readonly bool $deadline = false,
        ?int $curlErrno = CURLE_OPERATION_TIMEDOUT,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $request, $curlErrno, $previous);
    }

    /** No connection was established in time. */
    public function isConnect(): bool
    {
        return $this->connect;
    }

    /** The deadline of the whole operation (with retries) ran out, not one attempt's timeout. */
    public function isDeadline(): bool
    {
        return $this->deadline;
    }

    public function isTransient(): bool
    {
        return !$this->deadline;
    }
}
