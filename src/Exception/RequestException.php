<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The request cannot be sent as it is: a relative URI without a base URL, a scheme other
 * than http/https, a body stream that cannot be read. Retrying the same request never helps.
 */
class RequestException extends CastException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
