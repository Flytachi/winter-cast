<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit;

use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Tests\TestCase;

class CastResponseTest extends TestCase
{
    public function testConstructor(): void
    {
        $response = new CastResponse(
            statusCode: 200,
            body: '{"data": "test"}',
            headers: ['Content-Type' => 'application/json'],
            info: ['total_time' => 0.5]
        );

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('{"data": "test"}', $response->body);
        $this->assertEquals(['Content-Type' => 'application/json'], $response->headers);
        $this->assertEquals(['total_time' => 0.5], $response->info);
    }

    public function testBody(): void
    {
        $response = new CastResponse(200, 'test body', [], []);

        $this->assertEquals('test body', $response->body());
    }

    public function testJsonValid(): void
    {
        $response = new CastResponse(200, '{"key": "value", "num": 123}', [], []);

        $json = $response->json();

        $this->assertIsArray($json);
        $this->assertEquals('value', $json['key']);
        $this->assertEquals(123, $json['num']);
    }

    public function testJsonInvalid(): void
    {
        $response = new CastResponse(200, 'not json', [], []);

        $this->assertNull($response->json());
    }

    public function testJsonEmpty(): void
    {
        $response = new CastResponse(200, '', [], []);

        $this->assertNull($response->json());
    }

    public function testJsonNull(): void
    {
        $response = new CastResponse(200, null, [], []);

        $this->assertNull($response->json());
    }

    public function testHeaders(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Request-ID' => '123'
        ];
        $response = new CastResponse(200, null, $headers, []);

        $this->assertEquals($headers, $response->headers());
    }

    public function testHeaderCaseInsensitive(): void
    {
        $response = new CastResponse(200, null, ['Content-Type' => 'application/json'], []);

        $this->assertEquals('application/json', $response->header('content-type'));
        $this->assertEquals('application/json', $response->header('CONTENT-TYPE'));
        $this->assertEquals('application/json', $response->header('Content-Type'));
    }

    public function testHeaderNotFound(): void
    {
        $response = new CastResponse(200, null, [], []);

        $this->assertNull($response->header('X-Not-Exists'));
    }

    public function testHeaderArray(): void
    {
        $response = new CastResponse(200, null, ['Set-Cookie' => ['a=1', 'b=2']], []);

        $this->assertEquals('a=1, b=2', $response->header('Set-Cookie'));
    }

    public function testInfo(): void
    {
        $info = ['total_time' => 1.5, 'http_code' => 200];
        $response = new CastResponse(200, null, [], $info);

        $this->assertEquals($info, $response->info());
    }

    public function testInfoKey(): void
    {
        $response = new CastResponse(200, null, [], ['total_time' => 1.5]);

        $this->assertEquals(1.5, $response->infoKey('total_time'));
        $this->assertNull($response->infoKey('not_exists'));
    }

    public function testIsSuccess(): void
    {
        $this->assertTrue((new CastResponse(200, null, [], []))->isSuccess());
        $this->assertTrue((new CastResponse(201, null, [], []))->isSuccess());
        $this->assertTrue((new CastResponse(204, null, [], []))->isSuccess());
        $this->assertTrue((new CastResponse(299, null, [], []))->isSuccess());

        $this->assertFalse((new CastResponse(199, null, [], []))->isSuccess());
        $this->assertFalse((new CastResponse(300, null, [], []))->isSuccess());
        $this->assertFalse((new CastResponse(400, null, [], []))->isSuccess());
        $this->assertFalse((new CastResponse(500, null, [], []))->isSuccess());
    }

    public function testIsRedirection(): void
    {
        $this->assertTrue((new CastResponse(301, null, [], []))->isRedirection());
        $this->assertTrue((new CastResponse(302, null, [], []))->isRedirection());
        $this->assertTrue((new CastResponse(304, null, [], []))->isRedirection());

        $this->assertFalse((new CastResponse(200, null, [], []))->isRedirection());
        $this->assertFalse((new CastResponse(400, null, [], []))->isRedirection());
    }

    public function testIsClientError(): void
    {
        $this->assertTrue((new CastResponse(400, null, [], []))->isClientError());
        $this->assertTrue((new CastResponse(401, null, [], []))->isClientError());
        $this->assertTrue((new CastResponse(404, null, [], []))->isClientError());
        $this->assertTrue((new CastResponse(499, null, [], []))->isClientError());

        $this->assertFalse((new CastResponse(200, null, [], []))->isClientError());
        $this->assertFalse((new CastResponse(500, null, [], []))->isClientError());
    }

    public function testIsServerError(): void
    {
        $this->assertTrue((new CastResponse(500, null, [], []))->isServerError());
        $this->assertTrue((new CastResponse(502, null, [], []))->isServerError());
        $this->assertTrue((new CastResponse(503, null, [], []))->isServerError());

        $this->assertFalse((new CastResponse(200, null, [], []))->isServerError());
        $this->assertFalse((new CastResponse(400, null, [], []))->isServerError());
    }

    public function testIsConnectionError(): void
    {
        $this->assertTrue((new CastResponse(0, null, [], []))->isConnectionError());

        $this->assertFalse((new CastResponse(200, null, [], []))->isConnectionError());
        $this->assertFalse((new CastResponse(500, null, [], []))->isConnectionError());
    }
}
