<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Stereotype;

use Flytachi\Winter\Cast\Common\CastMiddleware;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Middleware that logs HTTP requests and responses.
 *
 * Automatically logs request details before sending and response details
 * after receiving, including timing information.
 *
 * ---
 * ### Example usage
 *
 * ```
 * use Flytachi\Winter\Cast\Stereotype\LoggingMiddleware;
 *
 * $client = new CastClient();
 * $client->addMiddleware(new LoggingMiddleware($logger));
 *
 * // Logs:
 * // [INFO] HTTP Request: GET https://api.com/users
 * // [INFO] HTTP Response: 200 OK (125.4ms)
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Stereotype
 * @author Flytachi
 */
class LoggingMiddleware implements CastMiddleware
{
    /**
     * @param LoggerInterface $logger PSR-3 logger instance
     * @param string $requestLevel Log level for requests (default: INFO)
     * @param string $responseLevel Log level for successful responses (default: INFO)
     * @param string $errorLevel Log level for error responses 4xx/5xx (default: WARNING)
     * @param bool $logBody Whether to log request/response body (default: false)
     * @param int $bodyMaxLength Maximum body length to log (default: 500)
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $requestLevel = LogLevel::INFO,
        private readonly string $responseLevel = LogLevel::INFO,
        private readonly string $errorLevel = LogLevel::WARNING,
        private readonly bool $logBody = false,
        private readonly int $bodyMaxLength = 500
    ) {}

    public function handle(CastRequest $request, callable $next): CastResponse
    {
        $startTime = microtime(true);

        // Log request
        $context = [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
        ];

        if ($this->logBody && $request->getBody() !== null) {
            $body = is_string($request->getBody()) ? $request->getBody() : json_encode($request->getBody());
            $context['body'] = $this->truncate($body, $this->bodyMaxLength);
        }

        $this->logger->log(
            $this->requestLevel,
            "HTTP Request: {$request->getMethod()} {$request->getUrl()}",
            $context
        );

        // Execute request
        $response = $next($request);

        // Log response
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $level = $response->isSuccess() || $response->isRedirection()
            ? $this->responseLevel
            : $this->errorLevel;

        $responseContext = [
            'status' => $response->statusCode,
            'duration_ms' => $duration,
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
        ];

        if ($this->logBody && $response->body() !== null) {
            $responseContext['body'] = $this->truncate($response->body(), $this->bodyMaxLength);
        }

        $this->logger->log(
            $level,
            "HTTP Response: {$response->statusCode} ({$duration}ms)",
            $responseContext
        );

        return $response;
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength) . '... [truncated]';
    }
}
