<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast;

use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Exception\BatchException;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * A request in one line, without configuring anything — for scripts, checks, tests.
 *
 * ```
 * $ip = Cast::get('https://api.ipify.org', ['format' => 'json'])->send()->json()['ip'];
 * ```
 *
 * Everything goes through one default client. In an application prefer a CastClient of your
 * own (a bean): its settings are visible where it is used, and it is easy to replace in tests.
 */
final class Cast
{
    private static ?CastClient $client = null;

    private function __construct()
    {
    }

    /** @param array<string, mixed> $query */
    public static function get(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->get($uri, $query);
    }

    /** @param array<string, mixed> $query */
    public static function post(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->post($uri, $query);
    }

    /** @param array<string, mixed> $query */
    public static function put(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->put($uri, $query);
    }

    /** @param array<string, mixed> $query */
    public static function patch(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->patch($uri, $query);
    }

    /** @param array<string, mixed> $query */
    public static function delete(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->delete($uri, $query);
    }

    /** @param array<string, mixed> $query */
    public static function head(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->head($uri, $query);
    }

    /** @param array<string, mixed> $query */
    public static function options(string|UriInterface $uri, array $query = []): CastRequest
    {
        return self::client()->options($uri, $query);
    }

    /** @throws CastException */
    public static function send(RequestInterface $request): CastResponse
    {
        return self::client()->send($request);
    }

    /**
     * @template TKey of array-key
     * @param iterable<TKey, RequestInterface> $requests
     * @return array<TKey, CastResponse>
     *
     * @throws BatchException
     */
    public static function sendAll(iterable $requests, ?int $concurrency = null): array
    {
        return self::client()->sendAll($requests, $concurrency);
    }

    /** The default client: created on first use with the library's defaults. */
    public static function client(): CastClient
    {
        return self::$client ??= new CastClient();
    }

    /**
     * Replaces the default client — once, at application start. Requests created before keep
     * the client they were created with.
     */
    public static function setClient(?CastClient $client): void
    {
        self::$client = $client;
    }
}
