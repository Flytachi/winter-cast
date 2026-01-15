<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastMiddleware;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Tests\TestCase;

class CastClientTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $client = new CastClient();

        $this->assertInstanceOf(CastClient::class, $client);
        $this->assertEmpty($client->getMiddleware());
    }

    public function testConstructorWithCustomValues(): void
    {
        $client = new CastClient(
            defaultTimeout: 30,
            defaultConnectTimeout: 10,
            defaultOptions: [CURLOPT_SSL_VERIFYPEER => false]
        );

        $this->assertInstanceOf(CastClient::class, $client);
    }

    public function testAddMiddleware(): void
    {
        $client = new CastClient();
        $middleware = new class implements CastMiddleware {
            public function handle(CastRequest $request, callable $next): CastResponse
            {
                return $next($request);
            }
        };

        $result = $client->addMiddleware($middleware);

        $this->assertSame($client, $result);
        $this->assertCount(1, $client->getMiddleware());
        $this->assertSame($middleware, $client->getMiddleware()[0]);
    }

    public function testAddMultipleMiddleware(): void
    {
        $client = new CastClient();

        $middleware1 = new class implements CastMiddleware {
            public function handle(CastRequest $request, callable $next): CastResponse
            {
                return $next($request);
            }
        };

        $middleware2 = new class implements CastMiddleware {
            public function handle(CastRequest $request, callable $next): CastResponse
            {
                return $next($request);
            }
        };

        $client->addMiddleware($middleware1)->addMiddleware($middleware2);

        $this->assertCount(2, $client->getMiddleware());
    }

    public function testMiddlewareChaining(): void
    {
        $client = new CastClient();
        $order = [];

        $middleware1 = new class($order) implements CastMiddleware {
            public function __construct(private array &$order) {}

            public function handle(CastRequest $request, callable $next): CastResponse
            {
                $this->order[] = 'before1';
                $response = $next($request);
                $this->order[] = 'after1';
                return $response;
            }
        };

        $middleware2 = new class($order) implements CastMiddleware {
            public function __construct(private array &$order) {}

            public function handle(CastRequest $request, callable $next): CastResponse
            {
                $this->order[] = 'before2';
                $response = $next($request);
                $this->order[] = 'after2';
                return $response;
            }
        };

        $client->addMiddleware($middleware1)->addMiddleware($middleware2);

        $this->assertCount(2, $client->getMiddleware());
    }
}
