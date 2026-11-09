<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Middleware;

use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * On 401 gets a new token and sends the request again with it.
 *
 * ```
 * $client
 *     ->withMiddleware(new RefreshTokenMiddleware(fn() => $tokens->refresh()))
 *     ->withMiddleware(AuthMiddleware::bearer(fn() => $tokens->current()));
 * ```
 *
 * `$refresh` returns the new token — sent as `Authorization: Bearer {token}` — or null when
 * there is none (the 401 is returned then). Keeping the token for later requests is the
 * caller's job (the store `$refresh` writes to), as above.
 *
 * The request is sent again with all its headers and its body; a body that cannot be
 * rewound is not sent twice — the 401 is returned.
 */
final readonly class RefreshTokenMiddleware implements CastMiddleware
{
    /** @var \Closure(CastResponse): ?string */
    private \Closure $refresh;

    /**
     * @param callable(CastResponse): ?string $refresh gets the 401 response, returns the new token
     */
    public function __construct(callable $refresh, private int $maxRetries = 1)
    {
        $this->refresh = $refresh(...);
    }

    public function process(CastRequest $request, callable $next): CastResponse
    {
        $response = $next($request);

        for ($i = 0; $i < $this->maxRetries && $response->getStatusCode() === 401; $i++) {
            if (!$request->rewindBody()) {
                break;
            }
            $token = ($this->refresh)($response);
            if ($token === null || $token === '') {
                break;
            }
            $request = $request->withBearerToken($token);
            $response = $next($request);
        }

        return $response;
    }
}
