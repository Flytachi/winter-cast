<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Stereotype;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastMiddleware;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;

/**
 * Middleware that automatically retries requests on 401 Unauthorized.
 *
 * When a 401 response is received, this middleware calls the token refresh
 * callback and retries the request with the new token. Useful for OAuth2
 * flows where access tokens expire.
 *
 * ---
 * ### Example usage
 *
 * ```
 * $client = new CastClient();
 * $client->addMiddleware(new RetryOnUnauthorizedMiddleware(
 *     tokenRefresher: function (): string {
 *         // Call your refresh token endpoint
 *         $response = Cast::sendPost('https://auth.api.com/refresh', [
 *             'refresh_token' => $storedRefreshToken
 *         ]);
 *         $newToken = $response->json()['access_token'];
 *
 *         // Store new token for future requests
 *         $this->tokenStorage->save($newToken);
 *
 *         return $newToken;
 *     }
 * ));
 *
 * // If request returns 401:
 * // 1. tokenRefresher is called to get new token
 * // 2. Request is retried with new token
 * // 3. If still 401, original response is returned
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Stereotype
 * @author Flytachi
 */
class RetryOnUnauthorizedMiddleware implements CastMiddleware
{
    /** @var \Closure(): string */
    private \Closure $tokenRefresher;

    /**
     * @param callable(): string $tokenRefresher Callback that refreshes and returns new token
     * @param int $maxRetries Maximum retry attempts (default: 1)
     */
    public function __construct(
        callable $tokenRefresher,
        private readonly int $maxRetries = 1
    ) {
        $this->tokenRefresher = $tokenRefresher(...);
    }

    public function handle(CastRequest $request, callable $next): CastResponse
    {
        $response = $next($request);

        if ($response->statusCode !== 401) {
            return $response;
        }

        // Attempt to refresh token and retry
        $retries = 0;
        while ($retries < $this->maxRetries && $response->statusCode === 401) {
            $retries++;

            try {
                $newToken = ($this->tokenRefresher)();
            } catch (\Throwable) {
                // Token refresh failed, return original 401 response
                return $response;
            }

            $retryRequest = $request->withHeaders(
                CastHeader::instance()->authBearer($newToken)
            );

            $response = $next($retryRequest);
        }

        return $response;
    }
}
