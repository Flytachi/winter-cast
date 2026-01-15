<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Tests\TestCase;

class CastRequestTest extends TestCase
{
    // =========================================================================
    // Factory Methods
    // =========================================================================

    public function testGet(): void
    {
        $request = CastRequest::get('https://example.com/api');

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('https://example.com/api', $request->getUrl());
    }

    public function testPost(): void
    {
        $request = CastRequest::post('https://example.com/api');

        $this->assertEquals('POST', $request->getMethod());
    }

    public function testPut(): void
    {
        $request = CastRequest::put('https://example.com/api');

        $this->assertEquals('PUT', $request->getMethod());
    }

    public function testPatch(): void
    {
        $request = CastRequest::patch('https://example.com/api');

        $this->assertEquals('PATCH', $request->getMethod());
    }

    public function testDelete(): void
    {
        $request = CastRequest::delete('https://example.com/api');

        $this->assertEquals('DELETE', $request->getMethod());
    }

    public function testHead(): void
    {
        $request = CastRequest::head('https://example.com/api');

        $this->assertEquals('HEAD', $request->getMethod());
    }

    // =========================================================================
    // URL Validation
    // =========================================================================

    public function testEmptyUrlThrowsException(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        CastRequest::get('');
    }

    public function testInvalidProtocolThrowsException(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessage('Only HTTP and HTTPS protocols are allowed');

        CastRequest::get('ftp://example.com');
    }

    public function testFileProtocolThrowsException(): void
    {
        $this->expectException(CastException::class);

        CastRequest::get('file:///etc/passwd');
    }

    public function testHttpsAllowed(): void
    {
        $request = CastRequest::get('https://example.com');

        $this->assertStringStartsWith('https://', $request->getUrl());
    }

    public function testHttpAllowed(): void
    {
        $request = CastRequest::get('http://example.com');

        $this->assertStringStartsWith('http://', $request->getUrl());
    }

    // =========================================================================
    // Query Parameters
    // =========================================================================

    public function testQueryParamsInFactory(): void
    {
        $request = CastRequest::get('https://example.com/api', ['page' => 1, 'limit' => 10]);

        $this->assertEquals('https://example.com/api?page=1&limit=10', $request->getUrl());
    }

    public function testWithQueryParam(): void
    {
        $request = CastRequest::get('https://example.com/api')
            ->withQueryParam('page', 1);

        $this->assertEquals('https://example.com/api?page=1', $request->getUrl());
    }

    public function testWithQueryParams(): void
    {
        $request = CastRequest::get('https://example.com/api')
            ->withQueryParams(['page' => 1, 'limit' => 10]);

        $this->assertStringContainsString('page=1', $request->getUrl());
        $this->assertStringContainsString('limit=10', $request->getUrl());
    }

    public function testQueryParamsAppendToExisting(): void
    {
        $request = CastRequest::get('https://example.com/api?existing=1')
            ->withQueryParam('new', 2);

        $this->assertStringContainsString('existing=1', $request->getUrl());
        $this->assertStringContainsString('new=2', $request->getUrl());
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    public function testWithHeadersIsImmutable(): void
    {
        $original = CastRequest::get('https://example.com');
        $headers = CastHeader::instance()->json();

        $modified = $original->withHeaders($headers);

        $this->assertNotSame($original, $modified);
    }

    public function testWithBodyIsImmutable(): void
    {
        $original = CastRequest::get('https://example.com');
        $modified = $original->withBody('test');

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->getBody());
        $this->assertEquals('test', $modified->getBody());
    }

    public function testWithJsonBodyIsImmutable(): void
    {
        $original = CastRequest::post('https://example.com');
        $modified = $original->withJsonBody(['key' => 'value']);

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->getBody());
    }

    public function testTimeoutIsImmutable(): void
    {
        $original = CastRequest::get('https://example.com');
        $modified = $original->timeout(30);

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->getTimeout());
        $this->assertEquals(30, $modified->getTimeout());
    }

    public function testRetryIsImmutable(): void
    {
        $original = CastRequest::get('https://example.com');
        $modified = $original->retry(3, 1000);

        $this->assertNotSame($original, $modified);
        $this->assertEquals(1, $original->getRetryCount());
        $this->assertEquals(3, $modified->getRetryCount());
    }

    // =========================================================================
    // Body Configuration
    // =========================================================================

    public function testWithJsonBody(): void
    {
        $request = CastRequest::post('https://example.com')
            ->withJsonBody(['name' => 'John', 'age' => 30]);

        $this->assertEquals('{"name":"John","age":30}', $request->getBody());
    }

    public function testWithFormParams(): void
    {
        $request = CastRequest::post('https://example.com')
            ->withFormParams(['name' => 'John', 'age' => 30]);

        $this->assertEquals('name=John&age=30', $request->getBody());
        $this->assertFalse($request->isMultipart());
    }

    public function testWithMultipartBody(): void
    {
        $data = ['field' => 'value'];
        $request = CastRequest::post('https://example.com')
            ->withMultipartBody($data);

        $this->assertEquals($data, $request->getBody());
        $this->assertTrue($request->isMultipart());
    }

    // =========================================================================
    // Timeouts and Retry
    // =========================================================================

    public function testTimeout(): void
    {
        $request = CastRequest::get('https://example.com')
            ->timeout(30);

        $this->assertEquals(30, $request->getTimeout());
    }

    public function testConnectTimeout(): void
    {
        $request = CastRequest::get('https://example.com')
            ->connectTimeout(5);

        $this->assertEquals(5, $request->getConnectTimeout());
    }

    public function testRetry(): void
    {
        $request = CastRequest::get('https://example.com')
            ->retry(3, 1000);

        $this->assertEquals(3, $request->getRetryCount());
        $this->assertEquals(1000, $request->getRetryDelay());
    }

    public function testRetryMinimumCount(): void
    {
        $request = CastRequest::get('https://example.com')
            ->retry(0);

        $this->assertEquals(1, $request->getRetryCount());
    }

    public function testExponentialBackoffDefault(): void
    {
        $request = CastRequest::get('https://example.com')
            ->retry(3);

        $this->assertTrue($request->useExponentialBackoff());
    }

    public function testExponentialBackoffDisabled(): void
    {
        $request = CastRequest::get('https://example.com')
            ->retry(3, 500, false);

        $this->assertFalse($request->useExponentialBackoff());
    }

    // =========================================================================
    // Other Configuration
    // =========================================================================

    public function testMaxResponseSize(): void
    {
        $request = CastRequest::get('https://example.com')
            ->maxResponseSize(1024 * 1024);

        $this->assertEquals(1024 * 1024, $request->getMaxResponseSize());
    }

    public function testMaxResponseSizeNull(): void
    {
        $request = CastRequest::get('https://example.com')
            ->maxResponseSize(null);

        $this->assertNull($request->getMaxResponseSize());
    }

    public function testThrowOnError(): void
    {
        $request = CastRequest::get('https://example.com')
            ->throwOnError();

        $this->assertTrue($request->shouldThrowOnError());
    }

    public function testThrowOnErrorFalse(): void
    {
        $request = CastRequest::get('https://example.com')
            ->throwOnError(false);

        $this->assertFalse($request->shouldThrowOnError());
    }

    public function testWithOptions(): void
    {
        $request = CastRequest::get('https://example.com')
            ->withOptions([CURLOPT_SSL_VERIFYPEER => false]);

        $options = $request->getOptions();
        $this->assertArrayHasKey(CURLOPT_SSL_VERIFYPEER, $options);
        $this->assertFalse($options[CURLOPT_SSL_VERIFYPEER]);
    }

    // =========================================================================
    // Method Chaining
    // =========================================================================

    public function testFullMethodChain(): void
    {
        $request = CastRequest::post('https://example.com/api')
            ->withHeaders(CastHeader::instance()->json())
            ->withJsonBody(['data' => 'value'])
            ->timeout(30)
            ->connectTimeout(5)
            ->retry(3, 500)
            ->maxResponseSize(10 * 1024 * 1024)
            ->throwOnError();

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals(30, $request->getTimeout());
        $this->assertEquals(5, $request->getConnectTimeout());
        $this->assertEquals(3, $request->getRetryCount());
        $this->assertTrue($request->shouldThrowOnError());
    }

    // =========================================================================
    // Default Values
    // =========================================================================

    public function testDefaultValues(): void
    {
        $request = CastRequest::get('https://example.com');

        $this->assertEquals(1, $request->getRetryCount());
        $this->assertEquals(500, $request->getRetryDelay());
        $this->assertTrue($request->useExponentialBackoff());
        $this->assertEquals(10_485_760, $request->getMaxResponseSize()); // 10MB
        $this->assertFalse($request->shouldThrowOnError());
        $this->assertFalse($request->isMultipart());
        $this->assertNull($request->getTimeout());
        $this->assertNull($request->getConnectTimeout());
        $this->assertNull($request->getBody());
    }
}
