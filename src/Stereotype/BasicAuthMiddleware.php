<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Stereotype;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastMiddleware;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;

/**
 * Middleware that automatically adds Basic authentication to requests.
 *
 * Encodes username and password as Base64 and adds the Authorization header.
 *
 * ---
 * ### Example usage
 *
 * ```
 * $client = new CastClient();
 * $client->addMiddleware(new BasicAuthMiddleware('username', 'password'));
 *
 * // All requests will include: Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Stereotype
 * @author Flytachi
 */
class BasicAuthMiddleware implements CastMiddleware
{
    /**
     * @param string $username The username for authentication
     * @param string $password The password for authentication
     */
    public function __construct(
        private readonly string $username,
        private readonly string $password
    ) {}

    public function handle(CastRequest $request, callable $next): CastResponse
    {
        $headers = CastHeader::instance()->authBasic($this->username, $this->password);

        return $next($request->withHeaders($headers));
    }
}
