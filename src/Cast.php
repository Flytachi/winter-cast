<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Exception\CastException;

/**
 * A static facade providing a simple, zero-configuration entry point for making HTTP requests.
 *
 * The Cast facade offers the most convenient way to make HTTP requests without needing to
 * manually instantiate clients or manage dependencies. It maintains a global singleton
 * CastClient instance that is shared across all requests.
 *
 * For advanced usage such as dependency injection, custom timeouts, or multiple client
 * configurations, it is recommended to use CastClient and CastRequest directly.
 *
 * ---
 * ### Example 1: Simple GET request
 *
 * ```
 * use Flytachi\Winter\Cast\Cast;
 *
 * // One-liner - send immediately
 * $response = Cast::sendGet('https://api.com/users');
 * $users = $response->json();
 *
 * // Or build request first
 * $response = Cast::get('https://api.com/users')
 *     ->withHeaders(
 *         CastHeader::instance()->json()->authBearer($token)
 *     )
 *     ->send();
 * ```
 *
 * ---
 * ### Example 2: POST/PUT with JSON body
 *
 * ```
 * $response = Cast::post('https://api.com/users')
 *     ->withHeaders(CastHeader::instance()->json())
 *     ->withJsonBody([
 *         'name' => 'John Doe',
 *         'email' => 'john@example.com'
 *     ])
 *     ->send();
 *
 * if ($response->isSuccess()) {
 *     $newUser = $response->json();
 *     echo "Created user with ID: {$newUser['id']}";
 * }
 * ```
 *
 * ---
 * ### Example 3: With custom global client
 *
 * ```
 * // Configure global client with custom timeouts
 * $customClient = new CastClient(
 *     defaultTimeout: 30,
 *     defaultConnectTimeout: 10
 * );
 * Cast::setGlobalClient($customClient);
 *
 * // All subsequent requests use custom client
 * $response = Cast::sendGet('https://slow-api.com/data');
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast
 * @author Flytachi
 *
 * @method static CastRequest get(string $url, ?array $queryParams = null)
 * @method static CastRequest post(string $url, ?array $queryParams = null)
 * @method static CastRequest put(string $url, ?array $queryParams = null)
 * @method static CastRequest patch(string $url, ?array $queryParams = null)
 * @method static CastRequest delete(string $url, ?array $queryParams = null)
 * @method static CastRequest head(string $url, ?array $queryParams = null)
 *
 * @method static CastResponse sendGet(string $url, ?array $queryParams = null)
 * @method static CastResponse sendPost(string $url, ?array $queryParams = null)
 * @method static CastResponse sendPut(string $url, ?array $queryParams = null)
 * @method static CastResponse sendPatch(string $url, ?array $queryParams = null)
 * @method static CastResponse sendDelete(string $url, ?array $queryParams = null)
 * @method static CastResponse sendHead(string $url, ?array $queryParams = null)
 *
 * @see CastClient
 * @see CastRequest
 * @see CastResponse
 */
final class Cast
{
    private static ?CastClient $globalClient = null;

    /**
     * Returns the global CastClient instance, creating it if it doesn't exist.
     *
     * @return CastClient The shared client instance.
     */
    public static function getGlobalClient(): CastClient
    {
        if (self::$globalClient === null) {
            self::$globalClient = new CastClient();
        }
        return self::$globalClient;
    }

    /**
     * Allows replacing the default global client with a custom-configured one.
     * This is useful for setting global timeouts, proxies, or other options application-wide.
     *
     * @param CastClient $client The new client instance to use globally.
     */
    public static function setGlobalClient(CastClient $client): void
    {
        self::$globalClient = $client;
    }

    /**
     * Forwards static calls to the CastRequest class to start building a request,
     * then immediately sends it using the global client.
     *
     * This magic method allows for simple, one-line requests like:
     * Cast::sendGet('https://example.com' );
     *
     * @param string $method The name of the static method called (e.g., 'sendGet', 'sendPost').
     * @param array $args The arguments passed to the method.
     * @return CastRequest|CastResponse
     * @throws CastException|\BadMethodCallException
     */
    public static function __callStatic(string $method, array $args): CastRequest|CastResponse
    {
        $buildMethods = ['get', 'post', 'put', 'patch', 'delete', 'head'];
        if (in_array($method, $buildMethods)) {
            return CastRequest::$method(...$args);
        }

        $sendPrefix = 'send';
        if (str_starts_with($method, $sendPrefix)) {
            $requestMethod = lcfirst(substr($method, strlen($sendPrefix)));

            if (in_array($requestMethod, $buildMethods)) {
                $request = CastRequest::$requestMethod(...$args);
                return $request->send(self::getGlobalClient());
            }
        }

        throw new \BadMethodCallException("Static method {$method} does not exist on Cast facade.");
    }
}
