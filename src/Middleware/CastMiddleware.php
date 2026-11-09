<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Middleware;

use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * A step every request of a client passes through: change the request, look at the response,
 * measure, retry with new credentials.
 *
 * ```
 * final class TimingMiddleware implements CastMiddleware
 * {
 *     public function process(CastRequest $request, callable $next): CastResponse
 *     {
 *         $start = hrtime(true);
 *         $response = $next($request);
 *         $this->metrics->observe((hrtime(true) - $start) / 1e6);
 *
 *         return $response;
 *     }
 * }
 * ```
 *
 * A middleware runs once per send — retries happen after it, closer to the network.
 */
interface CastMiddleware
{
    /**
     * @param callable(CastRequest): CastResponse $next the rest of the chain
     */
    public function process(CastRequest $request, callable $next): CastResponse;
}
