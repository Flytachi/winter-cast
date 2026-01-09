<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Flytachi\Winter\Cast\Common\CastResponse;

/**
 * Base exception for HTTP errors (4xx and 5xx responses).
 * Contains the failed response object for inspection.
 */
class RequestException extends CastException
{
    public function __construct(
        public readonly CastResponse $response,
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
