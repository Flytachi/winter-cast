<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Message;

use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\Multipart;
use Flytachi\Winter\Cast\Tests\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class CastRequestTest extends TestCase
{
    public function testFactoriesSetMethodAndQuery(): void
    {
        $request = CastRequest::get('https://a.com/users', ['page' => 2, 'q' => 'a b']);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('page=2&q=a%20b', $request->getUri()->getQuery());
        self::assertSame('a.com', $request->getHeaderLine('Host'));
        self::assertSame('Host', array_key_first($request->getHeaders()));
    }

    public function testHeadersAreCaseInsensitiveAndKeepTheLastSpelling(): void
    {
        $request = CastRequest::get('https://a.com')
            ->withHeader('x-token', 'a')
            ->withHeader('X-Token', 'b');

        self::assertSame(['b'], $request->getHeader('X-TOKEN'));
        self::assertArrayHasKey('X-Token', $request->getHeaders());
        self::assertArrayNotHasKey('x-token', $request->getHeaders());
    }

    public function testWithHeadersMergesAndReplaceHeadersReplaces(): void
    {
        $request = CastRequest::get('https://a.com')->withHeaders(['A' => '1', 'B' => '2'])->withHeaders(['b' => '3']);

        self::assertSame('1', $request->getHeaderLine('A'));
        self::assertSame('3', $request->getHeaderLine('B'));

        $replaced = $request->replaceHeaders(['C' => '4']);
        self::assertFalse($replaced->hasHeader('A'));
        self::assertSame('a.com', $replaced->getHeaderLine('Host'));
    }

    public function testHeaderInjectionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CastRequest::get('https://a.com')->withHeader('X-A', "a\r\nX-Injected: 1");
    }

    public function testInvalidHeaderNameIsRefused(): void
    {
        $this->expectException(ClientExceptionInterface::class);
        CastRequest::get('https://a.com')->withHeader('Bad Name', 'x');
    }

    public function testAuthAndAcceptShortcuts(): void
    {
        $request = CastRequest::get('https://a.com')->withBasicAuth('u', 'p')->withAccept('application/json', 'text/plain');

        self::assertSame('Basic ' . base64_encode('u:p'), $request->getHeaderLine('Authorization'));
        self::assertSame('application/json, text/plain', $request->getHeaderLine('Accept'));
        self::assertSame('Bearer t', $request->withBearerToken('t')->getHeaderLine('Authorization'));
    }

    public function testJsonBody(): void
    {
        $request = CastRequest::post('https://a.com')->withJsonBody(['a' => 'é']);

        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['a' => 'é'], json_decode((string) $request->getBody(), true));
    }

    public function testUnencodableJsonIsAnArgumentError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CastRequest::post('https://a.com')->withJsonBody(NAN);
    }

    public function testFormBody(): void
    {
        $request = CastRequest::post('https://a.com')->withFormBody(['a' => '1', 'b' => 'x y']);

        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('a=1&b=x+y', (string) $request->getBody());
    }

    public function testMultipartBodyRendersLazily(): void
    {
        $request = CastRequest::post('https://a.com')
            ->withMultipartBody(Multipart::create()->withField('a', '1')->withContent('f', 'hello', 'h.txt', 'text/plain'));

        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertNotNull($request->getMultipart());

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="a"' . "\r\n\r\n1\r\n", $body);
        self::assertStringContainsString('filename="h.txt"', $body);
        self::assertStringContainsString("hello\r\n", $body);
    }

    public function testWithBodyDropsTheMultipart(): void
    {
        $request = CastRequest::post('https://a.com')->withMultipartBody(['a' => '1'])->withStringBody('x');

        self::assertNull($request->getMultipart());
        self::assertSame('x', (string) $request->getBody());
    }

    public function testTransferOptionsHoldOnlyWhatWasSet(): void
    {
        $request = CastRequest::get('https://a.com')
            ->withTimeout(3)
            ->withRetry(RetryPolicy::exponential())
            ->withMaxResponseSize(null);

        $options = $request->transferOptions();
        self::assertSame(['total' => 3.0, 'connect' => null], $options['timeout']);
        self::assertArrayHasKey('maxResponseSize', $options);
        self::assertNull($options['maxResponseSize']);
        self::assertArrayNotHasKey('sink', $options);
    }

    public function testReservedCurlOptionsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('withMethod()');
        CastRequest::get('https://a.com')->withCurlOptions([CURLOPT_CUSTOMREQUEST => 'PUT']);
    }

    public function testOtherCurlOptionsAreAccepted(): void
    {
        $request = CastRequest::get('https://a.com')->withCurlOptions([CURLOPT_PROXY => 'http://proxy:3128']);

        self::assertSame([CURLOPT_PROXY => 'http://proxy:3128'], $request->transferOptions()['curl']);
    }

    public function testSinkToRefusesOtherTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CastRequest::get('https://a.com')->sinkTo(42);
    }

    public function testDebugInfoMasksSecrets(): void
    {
        $request = CastRequest::get('https://a.com')->withBearerToken('secret')->withHeader('X-Api-Key', 'k');
        $dump = print_r($request->__debugInfo(), true);

        self::assertStringNotContainsString('secret', $dump);
        self::assertStringNotContainsString("'k'", $dump);
        self::assertStringContainsString('***', $dump);
    }

    public function testInvalidMethodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CastRequest('GE T', 'https://a.com');
    }
}
