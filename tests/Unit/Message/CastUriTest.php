<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Message;

use Flytachi\Winter\Cast\Message\CastUri;
use Flytachi\Winter\Cast\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CastUriTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function joins(): iterable
    {
        yield 'host only' => ['https://api.com', 'users/42', 'https://api.com/users/42'];
        yield 'base path kept' => ['https://api.com/v1', 'users', 'https://api.com/v1/users'];
        yield 'slashes collapse' => ['https://api.com/v1/', '/users', 'https://api.com/v1/users'];
        yield 'queries merge' => ['https://api.com/v1?key=1', 'users?page=2', 'https://api.com/v1/users?key=1&page=2'];
        yield 'empty relative' => ['https://api.com/v1', '', 'https://api.com/v1'];
        yield 'only a query' => ['https://api.com/v1', '?a=1', 'https://api.com/v1?a=1'];
        yield 'absolute wins' => ['https://api.com/v1', 'http://other.org/x', 'http://other.org/x'];
    }

    #[DataProvider('joins')]
    public function testResolveAppendsToTheBase(string $base, string $relative, string $expected): void
    {
        self::assertSame($expected, (string) CastUri::parse($base)->resolve($relative));
    }

    public function testWithQueryParamsMergesAndEncodes(): void
    {
        $uri = CastUri::parse('https://a.com/x?a=1&b=2')->withQueryParams(['b' => 'x y', 'c' => [1, 2]]);

        self::assertSame('a=1&b=x%20y&c%5B0%5D=1&c%5B1%5D=2', $uri->getQuery());
    }

    public function testWithQueryParamsKeepsTheExistingQueryAsItIs(): void
    {
        $uri = CastUri::parse('https://a.com/s?user.id=5&tag=a&tag=b&page=1')->withQueryParams(['page' => 2]);

        self::assertSame('user.id=5&tag=a&tag=b&page=2', $uri->getQuery());
    }

    public function testWithQueryParamsReplacesArrayKeys(): void
    {
        $uri = CastUri::parse('https://a.com/s?ids%5B%5D=1&ids%5B%5D=2&x=1')->withQueryParams(['ids' => [3]]);

        self::assertSame('x=1&ids%5B0%5D=3', $uri->getQuery());
    }

    public function testRedactedDropsCredentialsAndQuery(): void
    {
        self::assertSame('https://api.com:8443/x', CastUri::parse('https://u:p@api.com:8443/x?key=K#f')->redacted());
    }

    public function testIsAbsolute(): void
    {
        self::assertTrue(CastUri::parse('http://a.com')->isAbsolute());
        self::assertFalse(CastUri::parse('/users')->isAbsolute());
        self::assertFalse(CastUri::parse('users')->isAbsolute());
    }

    public function testDefaultPortIsDropped(): void
    {
        self::assertSame('https://a.com/x', (string) CastUri::parse('https://a.com:443/x'));
        self::assertSame('http://a.com:8080/x', (string) CastUri::parse('http://a.com:8080/x'));
    }
}
