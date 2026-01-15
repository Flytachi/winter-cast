<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Middleware;

use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Stereotype\LoggingMiddleware;
use Flytachi\Winter\Cast\Tests\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class LoggingMiddlewareTest extends TestCase
{
    private array $logs = [];

    private function createTestLogger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger {
            public function __construct(private array &$logs) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = [];
    }

    public function testLogsRequestAndResponse(): void
    {
        $logger = $this->createTestLogger();
        $middleware = new LoggingMiddleware($logger);

        $request = CastRequest::get('https://example.com/api');
        $response = new CastResponse(200, '{"data": "test"}', [], []);

        $next = fn(CastRequest $req) => $response;

        $result = $middleware->handle($request, $next);

        $this->assertSame($response, $result);
        $this->assertCount(2, $this->logs);

        // Request log
        $this->assertEquals(LogLevel::INFO, $this->logs[0]['level']);
        $this->assertStringContainsString('GET', $this->logs[0]['message']);
        $this->assertStringContainsString('https://example.com/api', $this->logs[0]['message']);

        // Response log
        $this->assertEquals(LogLevel::INFO, $this->logs[1]['level']);
        $this->assertStringContainsString('200', $this->logs[1]['message']);
    }

    public function testLogsErrorResponse(): void
    {
        $logger = $this->createTestLogger();
        $middleware = new LoggingMiddleware(
            $logger,
            errorLevel: LogLevel::ERROR
        );

        $request = CastRequest::get('https://example.com/api');
        $response = new CastResponse(500, 'Server Error', [], []);

        $next = fn(CastRequest $req) => $response;

        $middleware->handle($request, $next);

        // Response should be logged at error level
        $this->assertEquals(LogLevel::ERROR, $this->logs[1]['level']);
    }

    public function testLogsBodyWhenEnabled(): void
    {
        $logger = $this->createTestLogger();
        $middleware = new LoggingMiddleware($logger, logBody: true);

        $request = CastRequest::post('https://example.com/api')
            ->withJsonBody(['key' => 'value']);
        $response = new CastResponse(200, '{"result": "ok"}', [], []);

        $next = fn(CastRequest $req) => $response;

        $middleware->handle($request, $next);

        // Check request body in context
        $this->assertArrayHasKey('body', $this->logs[0]['context']);

        // Check response body in context
        $this->assertArrayHasKey('body', $this->logs[1]['context']);
    }

    public function testTruncatesLongBody(): void
    {
        $logger = $this->createTestLogger();
        $middleware = new LoggingMiddleware($logger, logBody: true, bodyMaxLength: 10);

        $request = CastRequest::get('https://example.com/api');
        $response = new CastResponse(200, 'This is a very long response body', [], []);

        $next = fn(CastRequest $req) => $response;

        $middleware->handle($request, $next);

        $body = $this->logs[1]['context']['body'];
        $this->assertStringContainsString('truncated', $body);
    }

    public function testIncludesDuration(): void
    {
        $logger = $this->createTestLogger();
        $middleware = new LoggingMiddleware($logger);

        $request = CastRequest::get('https://example.com/api');
        $response = new CastResponse(200, null, [], []);

        $next = fn(CastRequest $req) => $response;

        $middleware->handle($request, $next);

        $this->assertArrayHasKey('duration_ms', $this->logs[1]['context']);
    }
}
