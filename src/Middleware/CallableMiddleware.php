<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Middleware;

use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * A function passed to withMiddleware(), as a middleware.
 *
 * @internal
 */
final readonly class CallableMiddleware implements CastMiddleware
{
    /** @param \Closure(CastRequest, callable(CastRequest): CastResponse): CastResponse $handler */
    public function __construct(private \Closure $handler)
    {
    }

    public function process(CastRequest $request, callable $next): CastResponse
    {
        return ($this->handler)($request, $next);
    }
}
