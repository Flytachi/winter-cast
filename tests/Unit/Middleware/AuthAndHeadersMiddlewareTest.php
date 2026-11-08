<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Middleware;

use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Stereotype\BasicAuthMiddleware;
use Flytachi\Winter\Cast\Stereotype\BearerAuthMiddleware;
use Flytachi\Winter\Cast\Stereotype\HeadersMiddleware;
use Flytachi\Winter\Cast\Stereotype\RetryOnUnauthorizedMiddleware;
use Flytachi\Winter\Cast\Tests\TestCase;

/**
 * The middlewares used to replace every header of the request with their own
 * (a JSON POST went out without Content-Type and Accept). They must only add.
 */
class AuthAndHeadersMiddlewareTest extends TestCase
{
    /** @var list<CastRequest> */
    private array $seen = [];

    private function request(): CastRequest
    {
        return CastRequest::post('https://example.com/api')
            ->withHeaders(CastHeader::instance()->set('Accept', 'application/json')->userAgent('App/1.0'))
            ->withJsonBody(['a' => 1]);
    }

    /** A terminal handler that records the request it got and answers with $status. */
    private function next(int $status = 200): callable
    {
        return function (CastRequest $request) use ($status): CastResponse {
            $this->seen[] = $request;
            return new CastResponse($status, '', [], []);
        };
    }

    private function assertOriginalHeadersKept(CastHeader $headers): void
    {
        $this->assertSame('application/json', $headers->get('Accept'));
        $this->assertSame('application/json', $headers->get('Content-Type'));
        $this->assertSame('App/1.0', $headers->get('User-Agent'));
    }

    public function testBearerAuthKeepsOtherHeaders(): void
    {
        (new BearerAuthMiddleware('tok'))->handle($this->request(), $this->next());

        $headers = $this->seen[0]->getHeaders();
        $this->assertSame('Bearer tok', $headers->get('Authorization'));
        $this->assertOriginalHeadersKept($headers);
    }

    public function testBearerAuthWithCallableToken(): void
    {
        (new BearerAuthMiddleware(fn() => 'fresh'))->handle($this->request(), $this->next());

        $this->assertSame('Bearer fresh', $this->seen[0]->getHeaders()->get('Authorization'));
    }

    public function testBasicAuthKeepsOtherHeaders(): void
    {
        (new BasicAuthMiddleware('user', 'pass'))->handle($this->request(), $this->next());

        $headers = $this->seen[0]->getHeaders();
        $this->assertSame('Basic ' . base64_encode('user:pass'), $headers->get('Authorization'));
        $this->assertOriginalHeadersKept($headers);
    }

    public function testHeadersMiddlewareAddsOnTop(): void
    {
        (new HeadersMiddleware(['X-Api-Key' => 'k', 'accept' => 'text/csv']))->handle($this->request(), $this->next());

        $headers = $this->seen[0]->getHeaders();
        $this->assertSame('k', $headers->get('X-Api-Key'));
        $this->assertSame('text/csv', $headers->get('Accept'));                // same name: the middleware wins
        $this->assertSame('application/json', $headers->get('Content-Type'));  // the rest stays
        $this->assertSame('App/1.0', $headers->get('User-Agent'));
    }

    public function testHeadersMiddlewareDoesNotShareItsCastHeaderBetweenRequests(): void
    {
        $shared = CastHeader::instance()->set('X-A', '1');
        $middleware = new HeadersMiddleware($shared);

        $middleware->handle($this->request(), $this->next());
        $this->seen[0]->getHeaders()->set('X-A', 'changed');
        $middleware->handle($this->request(), $this->next());

        $this->assertSame('1', $shared->get('X-A'));
        $this->assertSame('1', $this->seen[1]->getHeaders()->get('X-A'));
    }

    public function testRetryOnUnauthorizedKeepsOtherHeaders(): void
    {
        $middleware = new RetryOnUnauthorizedMiddleware(fn() => 'new-token');
        $statuses = [401, 200];
        $next = function (CastRequest $request) use (&$statuses): CastResponse {
            $this->seen[] = $request;
            return new CastResponse(array_shift($statuses), '', [], []);
        };

        $response = $middleware->handle($this->request(), $next);

        $this->assertSame(200, $response->statusCode);
        $retried = $this->seen[1]->getHeaders();
        $this->assertSame('Bearer new-token', $retried->get('Authorization'));
        $this->assertOriginalHeadersKept($retried);
    }
}
