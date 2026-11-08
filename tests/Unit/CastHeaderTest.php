<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Exception\CastException;
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

    // =========================================================================
    // Case-insensitive names (1.4.0)
    // =========================================================================

    public function testSetReplacesSameNameInAnyCase(): void
    {
        $header = CastHeader::instance()
            ->set('Content-Type', 'text/plain')
            ->set('content-type', 'application/json');

        $this->assertSame(['content-type: application/json'], $header->toArray());
    }

    public function testHasAndGetIgnoreCase(): void
    {
        $header = CastHeader::instance()->set('X-Request-ID', '42');

        $this->assertTrue($header->has('x-request-id'));
        $this->assertTrue($header->has('X-REQUEST-ID'));
        $this->assertSame('42', $header->get('x-request-id'));
        $this->assertFalse($header->has('X-Other'));
        $this->assertNull($header->get('X-Other'));
    }

    public function testRemoveIgnoresCase(): void
    {
        $header = CastHeader::instance()->contentType('text/plain')->remove('CONTENT-TYPE');

        $this->assertFalse($header->has('Content-Type'));
        $this->assertSame([], $header->toArray());
    }

    public function testAllAndFrom(): void
    {
        $header = CastHeader::from(['Accept' => 'text/csv', 'X-Api-Key' => 'k']);

        $this->assertSame(['Accept' => 'text/csv', 'X-Api-Key' => 'k'], $header->all());
    }

    public function testMergeKeepsOthersAndOverridesSameName(): void
    {
        $header = CastHeader::instance()->json()->set('X-A', '1')
            ->merge(CastHeader::instance()->set('accept', 'text/csv')->set('X-B', '2'))
            ->merge(['X-C' => '3']);

        $this->assertSame(
            ['accept' => 'text/csv', 'Content-Type' => 'application/json', 'X-A' => '1', 'X-B' => '2', 'X-C' => '3'],
            $header->all(),
        );
    }

    // =========================================================================
    // Header injection guard (1.4.0)
    // =========================================================================

    public function testValueWithLineBreakIsRejected(): void
    {
        $this->expectException(CastException::class);

        CastHeader::instance()->set('X-Name', "value\r\nX-Injected: yes");
    }

    public function testValueWithNulIsRejected(): void
    {
        $this->expectException(CastException::class);

        CastHeader::instance()->set('X-Name', "a\0b");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'colon' => ['X-A: b'],
            'line break' => ["X-A\nX-B"],
            'space' => ['X A'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
    public function testInvalidNameIsRejected(string $name): void
    {
        $this->expectException(CastException::class);

        CastHeader::instance()->set($name, 'value');
    }

    public function testFromRejectsInvalidHeaders(): void
    {
        $this->expectException(CastException::class);

        CastHeader::from(['X-A' => "1\r\n"]);
    }

    // =========================================================================
    // User-Agent exact flag (1.4.0)
    // =========================================================================

    public function testUserAgentExactFlag(): void
    {
        $this->assertFalse(CastHeader::instance()->userAgent('App/1.0')->isUserAgentExact());
        $this->assertTrue(CastHeader::instance()->userAgent('App/1.0', exact: true)->isUserAgentExact());
    }

    public function testSettingUserAgentAgainResetsExact(): void
    {
        $header = CastHeader::instance()->userAgent('App/1.0', exact: true)->set('User-Agent', 'Other/2.0');

        $this->assertFalse($header->isUserAgentExact());
    }

    public function testMergeCarriesExactUserAgent(): void
    {
        $header = CastHeader::instance()->merge(CastHeader::instance()->userAgent('App/1.0', exact: true));

        $this->assertTrue($header->isUserAgentExact());
        $this->assertSame('App/1.0', $header->get('User-Agent'));
    }
}
