<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Message;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Exception\ClientErrorException;
use Flytachi\Winter\Cast\Exception\ServerErrorException;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Tests\TestCase;

final class CastResponseTest extends TestCase
{
    public function testJson(): void
    {
        $response = CastResponse::fromJson(['a' => 1], 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame(['a' => 1], $response->json());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertNull($response->header('X-Missing'));
    }

    public function testJsonOfNonJsonOrScalarIsNull(): void
    {
        self::assertNull(CastResponse::fromString('<html>')->json());
        self::assertNull(CastResponse::fromString('"text"')->json());
        self::assertNull(CastResponse::fromString('')->json());
    }

    public function testJsonOrFail(): void
    {
        $this->expectException(CastException::class);
        CastResponse::fromString('<html>', 502, ['Content-Type' => 'text/html'])->jsonOrFail();
    }

    public function testThrowReturnsItselfOnSuccess(): void
    {
        $response = CastResponse::fromJson([]);

        self::assertSame($response, $response->throw());
    }

    public function testThrowPicksTheClassByStatus(): void
    {
        try {
            CastResponse::fromJson(['error' => 'x'], 404)->throw();
            self::fail('No exception');
        } catch (ClientErrorException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame(['error' => 'x'], $e->getResponse()->json());
        }

        $this->expectException(ServerErrorException::class);
        CastResponse::fromString('', 503)->throw();
    }

    public function testHttpExceptionMessageHasNoCredentials(): void
    {
        $request = new \Flytachi\Winter\Cast\Message\CastRequest('GET', 'https://user:secret@api.test/x?api_key=K');
        $response = CastResponse::fromString('', 403)->withTransfer($request, new \Flytachi\Winter\Cast\Message\TransferInfo(), false);

        try {
            $response->throw();
            self::fail('No exception');
        } catch (ClientErrorException $e) {
            self::assertSame('HTTP 403 Forbidden: GET https://api.test/x', $e->getMessage());
        }
    }

    public function testStatusHelpers(): void
    {
        self::assertSame(HttpCode::NOT_FOUND, CastResponse::fromString('', 404)->status());
        self::assertTrue(CastResponse::fromString('', 204)->isSuccess());
        self::assertTrue(CastResponse::fromString('', 302)->isRedirect());
        self::assertTrue(CastResponse::fromString('', 422)->isClientError());
        self::assertTrue(CastResponse::fromString('', 500)->isServerError());
    }

    public function testInfoHasDefaults(): void
    {
        self::assertSame(1, CastResponse::fromString('')->info()->attempts);
        self::assertFalse(CastResponse::fromString('')->isSunk());
    }
}
