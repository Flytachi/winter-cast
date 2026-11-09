<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Factory;

use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Message\CastStream;
use Flytachi\Winter\Cast\Message\CastUri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17 factories — how third-party libraries create this library's requests, responses,
 * streams and URIs. One class implements all four, so one object can be passed wherever any
 * of them is asked for.
 *
 * ```
 * $factory = new CastFactory();
 * $sdk = new SomeVendorSdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory);
 * ```
 */
final class CastFactory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    StreamFactoryInterface,
    UriFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new CastRequest($method, $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new CastResponse($code, [], null, '1.1', $reasonPhrase !== '' ? $reasonPhrase : null);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return CastStream::fromString($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return CastStream::fromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return CastStream::fromResource($resource);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new CastUri($uri);
    }
}
