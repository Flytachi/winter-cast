<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Exception\CastException;

/**
 * Class Cast
 *
 * A static facade providing a simple, zero-configuration entry point for making
 * HTTP requests. It offers a convenient way to send requests for basic use cases
 * without needing to manually instantiate a client.
 *
 * For advanced usage, such as dependency injection or custom client configuration,
 * it is recommended to use CastClient and CastRequest directly.
 *
 * @version 1.0
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
 * @method static CastResponse sendHead(string $url, ?array $queryParams = null):
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
            // Creates a client with the default responsive timeouts we discussed.
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
        // This allows creating a request and sending it immediately.
        $buildMethods = ['get', 'post', 'put', 'patch', 'delete', 'head'];
        if (in_array($method, $buildMethods)) {
            return CastRequest::$method(...$args);
        }

        $sendPrefix = 'send';
        if (str_starts_with($method, $sendPrefix)) {
            // Extract the actual method name: "sendGet" -> "get"
            $requestMethod = lcfirst(substr($method, strlen($sendPrefix))); // "Get" -> "get"

            if (in_array($requestMethod, $buildMethods)) {
                // 1. Create the CastRequest object
                $request = CastRequest::$requestMethod(...$args);

                // 2. Immediately send it using the global client
                return $request->send(self::getGlobalClient());
            }
        }

        throw new \BadMethodCallException("Static method {$method} does not exist on Cast facade.");
    }
}
