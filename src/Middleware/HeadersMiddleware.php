<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Middleware;

use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * Headers computed for every request — a request id, a signature, the current locale.
 * Constant headers need no middleware: the client's withHeaders() does it.
 *
 * ```
 * $client->withMiddleware(new HeadersMiddleware(fn(CastRequest $request) => [
 *     'X-Request-ID' => bin2hex(random_bytes(8)),
 * ]));
 * ```
 *
 * The headers are set over the request's (same names replaced, others kept).
 */
final readonly class HeadersMiddleware implements CastMiddleware
{
    /** @var \Closure(CastRequest): array<string, string|list<string>> */
    private \Closure $headers;

    /**
     * @param array<string, string|list<string>>|callable(CastRequest): array<string, string|list<string>> $headers
     */
    public function __construct(array|callable $headers)
    {
        if (is_array($headers) && !is_callable($headers)) {
            $this->headers = static fn(): array => $headers;
        } else {
            $this->headers = $headers(...);
        }
    }

    public function process(CastRequest $request, callable $next): CastResponse
    {
        return $next($request->withHeaders(($this->headers)($request)));
    }
}
