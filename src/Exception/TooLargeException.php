<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * The response body exceeds the limit set by withMaxResponseSize(). Detected from
 * Content-Length up front, or while receiving. Stream it to a file with sinkTo() instead.
 */
class TooLargeException extends CastException
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly int $limit,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
