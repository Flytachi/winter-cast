<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Middleware;

use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Middleware\AuthMiddleware;
use Flytachi\Winter\Cast\Middleware\HeadersMiddleware;
use Flytachi\Winter\Cast\Middleware\RefreshTokenMiddleware;
use Flytachi\Winter\Cast\Tests\TestCase;
use Flytachi\Winter\Cast\Transport\MockTransport;

final class MiddlewareTest extends TestCase
{
    private function client(MockTransport $mock): CastClient
    {
        return CastClient::create()->withBaseUrl('https://api.test')->withTransport($mock);
    }

    public function testBearerFromAFunctionIsAskedEveryTime(): void
    {
        $n = 0;
        $mock = new MockTransport(CastResponse::fromString(''), CastResponse::fromString(''));
        $client = $this->client($mock)->withMiddleware(AuthMiddleware::bearer(function () use (&$n) {
            return 't' . ++$n;
        }));

        $client->get('a')->send();
        $client->get('b')->send();

        self::assertSame('Bearer t1', $mock->requests()[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer t2', $mock->requests()[1]->getHeaderLine('Authorization'));
    }

    public function testAuthLeavesAnExplicitCredential(): void
    {
        $mock = new MockTransport(CastResponse::fromString(''));
        $this->client($mock)->withMiddleware(AuthMiddleware::bearer('x'))->get('a')->withBearerToken('own')->send();

        self::assertSame('Bearer own', $mock->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testBasicAndHeaderAuth(): void
    {
        $mock = new MockTransport(CastResponse::fromString(''));
        $this->client($mock)
            ->withMiddleware(AuthMiddleware::basic('u', 'p'), AuthMiddleware::header('X-Api-Key', 'k'))
            ->get('a')
            ->send();

        self::assertSame('Basic ' . base64_encode('u:p'), $mock->lastRequest()->getHeaderLine('Authorization'));
        self::assertSame('k', $mock->lastRequest()->getHeaderLine('X-Api-Key'));
    }

    public function testHeadersFromAFunction(): void
    {
        $mock = new MockTransport(CastResponse::fromString(''));
        $this->client($mock)
            ->withMiddleware(new HeadersMiddleware(fn(CastRequest $r) => ['X-Path' => $r->getUri()->getPath()]))
            ->get('users')
            ->send();

        self::assertSame('/users', $mock->lastRequest()->getHeaderLine('X-Path'));
    }

    public function testHeadersFromAMethodCallable(): void
    {
        $signer = new class {
            /** @return array<string, string> */
            public function sign(CastRequest $request): array
            {
                return ['X-Signature' => md5($request->getMethod())];
            }
        };
        $mock = new MockTransport(CastResponse::fromString(''));
        $this->client($mock)->withMiddleware(new HeadersMiddleware([$signer, 'sign']))->get('a')->send();

        self::assertSame(md5('GET'), $mock->lastRequest()->getHeaderLine('X-Signature'));
    }

    public function testRefreshOn401(): void
    {
        $mock = new MockTransport(CastResponse::fromString('', 401), CastResponse::fromString('ok'));
        $response = $this->client($mock)
            ->withBearerToken('old')
            ->withMiddleware(new RefreshTokenMiddleware(fn() => 'new'))
            ->post('a')
            ->withJsonBody(['x' => 1])
            ->send();

        self::assertSame('ok', $response->body());
        self::assertSame('Bearer new', $mock->requests()[1]->getHeaderLine('Authorization'));
        self::assertSame('{"x":1}', (string) $mock->requests()[1]->getBody());
    }

    public function testRefreshGivesUpWithoutAToken(): void
    {
        $mock = new MockTransport(CastResponse::fromString('', 401));
        $response = $this->client($mock)->withMiddleware(new RefreshTokenMiddleware(fn() => null))->get('a')->send();

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(1, $mock->requests());
    }
}
