<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Message\CastUri;
use Psr\Http\Message\RequestInterface;

/**
 * A response with a 4xx or 5xx status — thrown only on request: `$response->throw()`, or
 * withThrowOnError(). By default (PSR-18) an error status is an ordinary response.
 */
class HttpException extends CastException
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly CastResponse $response,
    ) {
        parent::__construct($message, $response->getStatusCode());
    }

    /** The exception that fits the response's status: ClientError for 4xx, ServerError for 5xx. */
    public static function from(CastResponse $response, RequestInterface $request): self
    {
        $status = $response->getStatusCode();
        $message = sprintf(
            'HTTP %d %s: %s %s',
            $status,
            $response->getReasonPhrase(),
            $request->getMethod(),
            CastUri::parse($request->getUri())->redacted(),
        );

        return match (true) {
            $status >= 500 => new ServerErrorException($message, $request, $response),
            $status >= 400 => new ClientErrorException($message, $request, $response),
            default => new self($message, $request, $response),
        };
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): CastResponse
    {
        return $this->response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }
}
