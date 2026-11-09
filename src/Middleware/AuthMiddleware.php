<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Middleware;

use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;

/**
 * Credentials on every request — when they change while the client lives (a token that is
 * refreshed); a constant one is simpler as the client's withBearerToken() / withHeader().
 *
 * ```
 * $client->withMiddleware(AuthMiddleware::bearer(fn() => $tokens->current()));
 * ```
 *
 * A credential the request already carries is left as it is.
 */
final readonly class AuthMiddleware implements CastMiddleware
{
    /** @param \Closure(): string $value */
    private function __construct(
        private string $header,
        private \Closure $value,
    ) {
    }

    /** `Authorization: Bearer {token}`; a function is asked for the token on every request. */
    public static function bearer(string|callable $token): self
    {
        $token = self::provider($token);

        return new self('Authorization', static fn(): string => 'Bearer ' . $token());
    }

    /** `Authorization: Basic {base64(user:password)}`. */
    public static function basic(string $user, string $password): self
    {
        $value = 'Basic ' . base64_encode($user . ':' . $password);

        return new self('Authorization', static fn(): string => $value);
    }

    /** A key in a header of its own: `AuthMiddleware::header('X-Api-Key', $key)`. */
    public static function header(string $name, string|callable $value): self
    {
        return new self($name, self::provider($value));
    }

    public function process(CastRequest $request, callable $next): CastResponse
    {
        if (!$request->hasHeader($this->header)) {
            $request = $request->withHeader($this->header, ($this->value)());
        }
        $request = $request->markSecret($this->header);

        return $next($request);
    }

    /** @return \Closure(): string */
    private static function provider(string|callable $value): \Closure
    {
        if (is_string($value)) {
            return static fn(): string => $value;
        }
        $value = $value(...);

        return static fn(): string => (string) $value();
    }
}
