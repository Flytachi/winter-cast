<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Tests\TestCase;

class CastHeaderTest extends TestCase
{
    public function testInstance(): void
    {
        $header = CastHeader::instance();

        $this->assertInstanceOf(CastHeader::class, $header);
    }

    public function testSetHeader(): void
    {
        $header = CastHeader::instance()
            ->set('X-Custom', 'value');

        $this->assertContains('X-Custom: value', $header->toArray());
    }

    public function testRemoveHeader(): void
    {
        $header = CastHeader::instance()
            ->set('X-Custom', 'value')
            ->remove('X-Custom');

        $this->assertNotContains('X-Custom: value', $header->toArray());
    }

    public function testAuthBearer(): void
    {
        $header = CastHeader::instance()
            ->authBearer('test-token');

        $this->assertContains('Authorization: Bearer test-token', $header->toArray());
    }

    public function testAuthBasic(): void
    {
        $header = CastHeader::instance()
            ->authBasic('user', 'pass');

        $expected = 'Authorization: Basic ' . base64_encode('user:pass');
        $this->assertContains($expected, $header->toArray());
    }

    public function testJson(): void
    {
        $header = CastHeader::instance()->json();

        $array = $header->toArray();
        $this->assertContains('Accept: application/json', $array);
        $this->assertContains('Content-Type: application/json', $array);
    }

    public function testUserAgent(): void
    {
        $header = CastHeader::instance()
            ->userAgent('TestApp/1.0');

        $this->assertContains('User-Agent: TestApp/1.0', $header->toArray());
    }

    public function testAcceptLanguage(): void
    {
        $header = CastHeader::instance()
            ->acceptLanguage('ru-RU');

        $this->assertContains('Accept-Language: ru-RU', $header->toArray());
    }

    public function testReferer(): void
    {
        $header = CastHeader::instance()
            ->referer('https://example.com');

        $this->assertContains('Referer: https://example.com', $header->toArray());
    }

    public function testContentType(): void
    {
        $header = CastHeader::instance()
            ->contentType('text/plain');

        $this->assertContains('Content-Type: text/plain', $header->toArray());
    }

    public function testMethodChaining(): void
    {
        $header = CastHeader::instance()
            ->json()
            ->authBearer('token')
            ->userAgent('App/1.0')
            ->set('X-Request-ID', '123');

        $array = $header->toArray();

        $this->assertCount(5, $array);
        $this->assertContains('Accept: application/json', $array);
        $this->assertContains('Content-Type: application/json', $array);
        $this->assertContains('Authorization: Bearer token', $array);
        $this->assertContains('User-Agent: App/1.0', $array);
        $this->assertContains('X-Request-ID: 123', $array);
    }

    public function testToArrayFormat(): void
    {
        $header = CastHeader::instance()
            ->set('X-Test', 'value');

        $array = $header->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(['X-Test: value'], $array);
    }
}
