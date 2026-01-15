<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

/**
 * Interface for HTTP request/response middleware.
 *
 * Middleware allows you to intercept and modify requests before they are sent,
 * and responses after they are received. This is useful for cross-cutting concerns
 * like logging, authentication, caching, and error handling.
 *
 * Middleware is executed in the order it was added, forming a chain:
 * Request → Middleware1 → Middleware2 → ... → CastClient → Server
 * Response ← Middleware1 ← Middleware2 ← ... ← CastClient ← Server
 *
 * ---
 * ### Example 1: Logging middleware
 *
 * ```
 * class LoggingMiddleware implements CastMiddleware
 * {
 *     public function __construct(private LoggerInterface $logger) {}
 *
 *     public function handle(CastRequest $request, callable $next): CastResponse
 *     {
 *         $start = microtime(true);
 *         $this->logger->info("→ {$request->getMethod()} {$request->getUrl()}");
 *
 *         $response = $next($request);
 *
 *         $duration = round((microtime(true) - $start) * 1000, 2);
 *         $this->logger->info("← {$response->statusCode} ({$duration}ms)");
 *
 *         return $response;
 *     }
 * }
 * ```
 *
 * ---
 * ### Example 2: Authentication middleware
 *
 * ```
 * class BearerAuthMiddleware implements CastMiddleware
 * {
 *     public function __construct(private string $token) {}
 *
 *     public function handle(CastRequest $request, callable $next): CastResponse
 *     {
 *         $authenticatedRequest = $request->withHeaders(
 *             CastHeader::instance()->authBearer($this->token)
 *         );
 *
 *         return $next($authenticatedRequest);
 *     }
 * }
 * ```
 *
 * ---
 * ### Example 3: Retry on 401 with token refresh
 *
 * ```
 * class TokenRefreshMiddleware implements CastMiddleware
 * {
 *     public function __construct(
 *         private TokenService $tokenService
 *     ) {}
 *
 *     public function handle(CastRequest $request, callable $next): CastResponse
 *     {
 *         $response = $next($request);
 *
 *         if ($response->statusCode === 401) {
 *             $newToken = $this->tokenService->refresh();
 *             $retryRequest = $request->withHeaders(
 *                 CastHeader::instance()->authBearer($newToken)
 *             );
 *             return $next($retryRequest);
 *         }
 *
 *         return $response;
 *     }
 * }
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Common
 * @author Flytachi
 */
interface CastMiddleware
{
    /**
     * Handle the request and return a response.
     *
     * The middleware can:
     * - Modify the request before passing it to $next
     * - Modify the response after receiving it from $next
     * - Short-circuit the chain by returning a response without calling $next
     * - Perform actions before and/or after the request (logging, timing, etc.)
     *
     * @param CastRequest $request The HTTP request
     * @param callable(CastRequest): CastResponse $next The next handler in the chain
     * @return CastResponse The HTTP response
     */
    public function handle(CastRequest $request, callable $next): CastResponse;
}
