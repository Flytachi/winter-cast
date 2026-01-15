<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Stereotype;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastMiddleware;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;

/**
 * Middleware that adds custom headers to all requests.
 *
 * Useful for adding API keys, correlation IDs, custom User-Agent,
 * or any other headers that should be present on every request.
 *
 * ---
 * ### Example 1: Static headers
 *
 * ```
 * $client = new CastClient();
 * $client->addMiddleware(new HeadersMiddleware([
 *     'X-API-Key' => 'your-api-key',
 *     'X-Client-Version' => '1.0.0',
 *     'Accept-Language' => 'en-US',
 * ]));
 * ```
 *
 * ---
 * ### Example 2: Using CastHeader builder
 *
 * ```
 * $headers = CastHeader::instance()
 *     ->json()
 *     ->userAgent('MyApp/1.0');
 *
 * $client = new CastClient();
 * $client->addMiddleware(new HeadersMiddleware($headers));
 * ```
 *
 * ---
 * ### Example 3: Dynamic headers with callback
 *
 * ```
 * $client = new CastClient();
 * $client->addMiddleware(new HeadersMiddleware(
 *     fn() => ['X-Request-ID' => uniqid('req_')]
 * ));
 *
 * // Each request gets a unique X-Request-ID
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Stereotype
 * @author Flytachi
 */
class HeadersMiddleware implements CastMiddleware
{
    /** @var array<string, string>|CastHeader|\Closure(): array<string, string> */
    private array|CastHeader|\Closure $headers;

    /**
     * @param array<string, string>|CastHeader|callable(): array<string, string> $headers
     *        Headers as associative array, CastHeader instance, or callable returning array
     */
    public function __construct(array|CastHeader|callable $headers)
    {
        $this->headers = is_callable($headers) && !($headers instanceof CastHeader)
            ? $headers(...)
            : $headers;
    }

    public function handle(CastRequest $request, callable $next): CastResponse
    {
        $headers = $this->resolveHeaders();

        return $next($request->withHeaders($headers));
    }

    private function resolveHeaders(): CastHeader
    {
        if ($this->headers instanceof CastHeader) {
            return $this->headers;
        }

        $headerArray = is_callable($this->headers)
            ? ($this->headers)()
            : $this->headers;

        $castHeader = CastHeader::instance();
        foreach ($headerArray as $name => $value) {
            $castHeader->set($name, $value);
        }

        return $castHeader;
    }
}
