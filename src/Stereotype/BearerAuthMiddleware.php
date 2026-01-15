<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Stereotype;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastMiddleware;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;

/**
 * Middleware that automatically adds Bearer token authentication to requests.
 *
 * Supports both static tokens and dynamic token providers (closures) for
 * scenarios where tokens may change during application lifecycle.
 *
 * ---
 * ### Example 1: Static token
 *
 * ```
 * $client = new CastClient();
 * $client->addMiddleware(new BearerAuthMiddleware('your-api-token'));
 *
 * // All requests will include: Authorization: Bearer your-api-token
 * ```
 *
 * ---
 * ### Example 2: Dynamic token provider
 *
 * ```
 * $client = new CastClient();
 * $client->addMiddleware(new BearerAuthMiddleware(
 *     fn() => $tokenService->getAccessToken()
 * ));
 *
 * // Token is fetched fresh for each request
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Stereotype
 * @author Flytachi
 */
class BearerAuthMiddleware implements CastMiddleware
{
    /** @var string|\Closure(): string */
    private string|\Closure $tokenProvider;

    /**
     * @param string|callable(): string $token Static token string or callable that returns token
     */
    public function __construct(string|callable $token)
    {
        $this->tokenProvider = $token;
    }

    public function handle(CastRequest $request, callable $next): CastResponse
    {
        $token = is_callable($this->tokenProvider)
            ? ($this->tokenProvider)()
            : $this->tokenProvider;

        $headers = CastHeader::instance()->authBearer($token);

        return $next($request->withHeaders($headers));
    }
}
