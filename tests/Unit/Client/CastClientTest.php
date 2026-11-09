<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Client;

use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Cast\Cast;
use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Client\Redirects;
use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Client\Timeouts;
use Flytachi\Winter\Cast\Client\TransferOptions;
use Flytachi\Winter\Cast\Exception\BatchException;
use Flytachi\Winter\Cast\Exception\ClientErrorException;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Flytachi\Winter\Cast\Exception\RequestException;
use Flytachi\Winter\Cast\Exception\TimeoutException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Middleware\AuthMiddleware;
use Flytachi\Winter\Cast\Middleware\HeadersMiddleware;
use Flytachi\Winter\Cast\Tests\TestCase;
use Flytachi\Winter\Cast\Transport\MockTransport;
use Psr\Log\AbstractLogger;

final class CastClientTest extends TestCase
{
    private function client(MockTransport $mock): CastClient
    {
        return CastClient::create()->withBaseUrl('https://api.test/v1')->withTransport($mock);
    }

    public function testImmutable(): void
    {
        $client = CastClient::create();
        $other = $client->withHeader('X-A', '1');

        self::assertNotSame($client, $other);
        self::assertSame([], $client->getHeaders());
        self::assertSame(['X-A' => ['1']], $other->getHeaders());
    }

    public function testBaseUrlAndDefaultHeaders(): void
    {
        $mock = new MockTransport(CastResponse::fromJson(['ok' => true]));
        $client = $this->client($mock)->withHeaders(['X-Key' => 'k', 'Accept' => 'application/json']);

        $response = $client->get('users/42', ['expand' => 1])->withHeader('Accept', 'text/plain')->send();

        $sent = $mock->lastRequest();
        self::assertSame('https://api.test/v1/users/42?expand=1', (string) $sent->getUri());
        self::assertSame('api.test', $sent->getHeaderLine('Host'));
        self::assertSame('k', $sent->getHeaderLine('X-Key'));
        self::assertSame('text/plain', $sent->getHeaderLine('Accept'), 'the request wins');
        self::assertSame(['ok' => true], $response->json());
        self::assertSame($sent, $response->getRequest());
    }

    public function testRelativeUrlWithoutBaseIsARequestError(): void
    {
        $this->expectException(RequestException::class);
        CastClient::create()->withTransport(new MockTransport())->get('users')->send();
    }

    public function testBaseUrlMustBeAbsoluteHttp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CastClient::create()->withBaseUrl('ftp://a.com');
    }

    public function testOnlyHttpSchemesAreSent(): void
    {
        $this->expectException(RequestException::class);
        CastClient::create()->withTransport(new MockTransport())->get('file:///etc/passwd')->send();
    }

    public function testRequestSettingsOverrideTheClient(): void
    {
        $seen = null;
        $mock = new MockTransport(function (CastRequest $r, TransferOptions $o) use (&$seen) {
            $seen = $o;
            return CastResponse::fromString('');
        });
        $client = $this->client($mock)->withTimeout(30, 5)->withMaxResponseSize(100)->withCurlOptions([CURLOPT_PROXY => 'a']);

        $client->get('x')->withTimeout(2)->withMaxResponseSize(null)->withCurlOptions([CURLOPT_PROXY => 'b'])->send();

        self::assertSame(2.0, $seen->timeouts->total);
        self::assertNull($seen->maxResponseSize);
        self::assertSame('b', $seen->curl[CURLOPT_PROXY]);
    }

    public function testRequestTimeoutKeepsTheClientsOtherTimeouts(): void
    {
        $seen = null;
        $mock = new MockTransport(function (CastRequest $r, TransferOptions $o) use (&$seen) {
            $seen = $o;
            return CastResponse::fromString('');
        });
        $client = $this->client($mock)->withTimeouts(Timeouts::forDownload(idle: 20)->withConnect(2)->withDeadline(45));

        $client->get('x')->withTimeout(10)->send();

        self::assertSame(10.0, $seen->timeouts->total);
        self::assertSame(2.0, $seen->timeouts->connect);
        self::assertSame(20.0, $seen->timeouts->idle);
        self::assertSame(45.0, $seen->timeouts->deadline);
    }

    public function testRequestMethodBuilder(): void
    {
        $mock = new MockTransport(CastResponse::fromString(''));
        $this->client($mock)->request(Method::PATCH, 'x')->send();

        self::assertSame('PATCH', $mock->lastRequest()->getMethod());
    }

    public function testErrorStatusesAreResponses(): void
    {
        $mock = new MockTransport(CastResponse::fromJson(['e' => 1], 404));

        self::assertSame(404, $this->client($mock)->get('x')->send()->getStatusCode());
    }

    public function testThrowOnError(): void
    {
        $mock = new MockTransport(CastResponse::fromJson([], 422), CastResponse::fromJson([], 422));
        $client = $this->client($mock)->withThrowOnError();

        self::assertSame(422, $client->sendRequest(CastRequest::get('x'))->getStatusCode(), 'PSR-18 never throws');

        $this->expectException(ClientErrorException::class);
        $client->get('x')->send();
    }

    public function testMiddlewareRunsInOrderAndOnce(): void
    {
        $log = [];
        $mock = new MockTransport(
            MockTransport::fail(),
            CastResponse::fromString('ok'),
        );
        $client = $this->client($mock)
            ->withRetry(RetryPolicy::fixed(2, 0))
            ->withMiddleware(
                function (CastRequest $r, callable $next) use (&$log) {
                    $log[] = 'a>';
                    $response = $next($r->withHeader('X-A', '1'));
                    $log[] = '<a';
                    return $response;
                },
                function (CastRequest $r, callable $next) use (&$log) {
                    $log[] = 'b>' . $r->getHeaderLine('X-A');
                    return $next($r);
                },
            );

        $response = $client->get('x')->send();

        self::assertSame(['a>', 'b>1', '<a'], $log);
        self::assertSame(2, $response->info()->attempts);
        self::assertCount(2, $mock->requests());
    }

    public function testWithoutMiddleware(): void
    {
        $client = CastClient::create()->withMiddleware(new HeadersMiddleware(['X' => '1']));

        self::assertCount(1, $client->getMiddleware());
        self::assertCount(0, $client->withoutMiddleware(HeadersMiddleware::class)->getMiddleware());
    }

    public function testRetriesStatusesAndCountsAttempts(): void
    {
        $mock = new MockTransport(
            CastResponse::fromString('', 503),
            CastResponse::fromString('', 503),
            CastResponse::fromString('done'),
        );
        $response = $this->client($mock)
            ->withRetry(RetryPolicy::fixed(3, 0)->withStatuses(503))
            ->get('x')
            ->send();

        self::assertSame('done', $response->body());
        self::assertSame(3, $response->info()->attempts);
    }

    public function testLastStatusIsReturnedWhenAttemptsRunOut(): void
    {
        $mock = new MockTransport(CastResponse::fromString('', 503), CastResponse::fromString('', 503));
        $response = $this->client($mock)->withRetry(RetryPolicy::fixed(2, 0)->withStatuses(503))->get('x')->send();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(0, $mock->remaining());
    }

    public function testPostIsNotRetriedByDefault(): void
    {
        $mock = new MockTransport(MockTransport::fail(), CastResponse::fromString(''));

        try {
            $this->client($mock)->withRetry(RetryPolicy::fixed(3, 0))->post('x')->send();
            self::fail('No exception');
        } catch (NetworkException) {
            self::assertCount(1, $mock->requests());
        }
    }

    public function testNonSeekableBodyIsNotRetried(): void
    {
        $mock = new MockTransport(MockTransport::fail(), CastResponse::fromString(''));
        $body = new class implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string
            {
                return '';
            }
            public function close(): void
            {
            }
            public function detach()
            {
                return null;
            }
            public function getSize(): ?int
            {
                return null;
            }
            public function tell(): int
            {
                return 0;
            }
            public function eof(): bool
            {
                return true;
            }
            public function isSeekable(): bool
            {
                return false;
            }
            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }
            public function rewind(): void
            {
            }
            public function isWritable(): bool
            {
                return false;
            }
            public function write(string $string): int
            {
                return 0;
            }
            public function isReadable(): bool
            {
                return true;
            }
            public function read(int $length): string
            {
                return '';
            }
            public function getContents(): string
            {
                return '';
            }
            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };

        $this->expectException(NetworkException::class);
        try {
            $this->client($mock)->withRetry(RetryPolicy::fixed(3, 0))->put('x')->withBody($body)->send();
        } finally {
            self::assertCount(1, $mock->requests());
        }
    }

    public function testDeadlineStopsRetries(): void
    {
        $mock = new MockTransport(MockTransport::fail(), MockTransport::fail(), MockTransport::fail());

        try {
            $this->client($mock)
                ->withTimeouts(Timeouts::of()->withDeadline(0.05))
                ->withRetry(RetryPolicy::fixed(3, 200))
                ->get('x')
                ->send();
            self::fail('No exception');
        } catch (TimeoutException $e) {
            self::assertTrue($e->isDeadline());
            self::assertInstanceOf(NetworkException::class, $e->getPrevious());
            self::assertCount(1, $mock->requests());
        }
    }

    public function testRedirectRewritesPostToGetAndDropsTheBody(): void
    {
        $mock = new MockTransport(
            CastResponse::fromString('', 302, ['Location' => '/v1/done?x=1']),
            CastResponse::fromString('ok'),
        );
        $response = $this->client($mock)->post('x')->withJsonBody(['a' => 1])->send();

        $second = $mock->requests()[1];
        self::assertSame('GET', $second->getMethod());
        self::assertSame('https://api.test/v1/done?x=1', (string) $second->getUri());
        self::assertSame('', (string) $second->getBody());
        self::assertFalse($second->hasHeader('Content-Type'));
        self::assertSame(1, $response->info()->redirects);
        self::assertSame('https://api.test/v1/done?x=1', $response->info()->effectiveUri);
    }

    public function testRedirect307KeepsMethodAndBody(): void
    {
        $mock = new MockTransport(
            CastResponse::fromString('', 307, ['Location' => 'other']),
            CastResponse::fromString('ok'),
        );
        $this->client($mock)->put('dir/x')->withStringBody('payload')->send();

        $second = $mock->requests()[1];
        self::assertSame('PUT', $second->getMethod());
        self::assertSame('payload', (string) $second->getBody());
        self::assertSame('https://api.test/v1/dir/other', (string) $second->getUri(), 'RFC 3986 relative resolution');
    }

    public function testCrossHostRedirectDropsCredentials(): void
    {
        $mock = new MockTransport(
            CastResponse::fromString('', 302, ['Location' => 'https://cdn.test/file']),
            CastResponse::fromString('ok'),
        );
        $this->client($mock)->withBearerToken('t')->withHeader('X-Keep', '1')->get('x')->send();

        $second = $mock->requests()[1];
        self::assertFalse($second->hasHeader('Authorization'));
        self::assertSame('1', $second->getHeaderLine('X-Keep'));
        self::assertSame('cdn.test', $second->getHeaderLine('Host'));
    }

    public function testCrossHostRedirectDropsAnAuthMiddlewareHeader(): void
    {
        $mock = new MockTransport(
            CastResponse::fromString('', 302, ['Location' => 'https://cdn.test/file']),
            CastResponse::fromString('ok'),
        );
        $this->client($mock)->withMiddleware(AuthMiddleware::header('X-Token', 'k'))->get('x')->send();

        self::assertSame('k', $mock->requests()[0]->getHeaderLine('X-Token'));
        self::assertFalse($mock->requests()[1]->hasHeader('X-Token'));
    }

    public function testSameHostRedirectKeepsCredentials(): void
    {
        $mock = new MockTransport(
            CastResponse::fromString('', 301, ['Location' => '/v2/x']),
            CastResponse::fromString('ok'),
        );
        $this->client($mock)->withBearerToken('t')->get('x')->send();

        self::assertSame('Bearer t', $mock->requests()[1]->getHeaderLine('Authorization'));
    }

    public function testDowngradeIsNotFollowed(): void
    {
        $mock = new MockTransport(CastResponse::fromString('', 302, ['Location' => 'http://api.test/x']));
        $response = $this->client($mock)->get('x')->send();

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $mock->requests());
    }

    public function testRedirectsCanBeOff(): void
    {
        $mock = new MockTransport(CastResponse::fromString('', 302, ['Location' => '/y']));

        self::assertSame(302, $this->client($mock)->withRedirects(Redirects::none())->get('x')->send()->getStatusCode());
    }

    public function testTooManyRedirects(): void
    {
        $loop = static fn() => CastResponse::fromString('', 302, ['Location' => '/v1/x']);
        $mock = new MockTransport($loop, $loop, $loop);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Too many redirects');
        $this->client($mock)->withRedirects(Redirects::follow(2))->get('x')->send();
    }

    public function testSendAllKeepsKeysAndOrder(): void
    {
        $mock = new MockTransport(static fn(CastRequest $r) => CastResponse::fromString($r->getUri()->getPath(), 200));
        $mock->append(...array_fill(0, 2, static fn(CastRequest $r) => CastResponse::fromString($r->getUri()->getPath(), 404)));
        $client = $this->client($mock);

        $responses = $client->sendAll(['b' => $client->get('one'), 'a' => $client->get('two'), 7 => $client->get('three')]);

        self::assertSame(['b', 'a', 7], array_keys($responses));
        self::assertSame('/v1/one', $responses['b']->body());
        self::assertSame(404, $responses[7]->getStatusCode(), '4xx is a response');
    }

    public function testSendAllReportsFailuresAfterAll(): void
    {
        $mock = new MockTransport(CastResponse::fromString('1'), MockTransport::fail(), CastResponse::fromString('3'));
        $client = $this->client($mock);

        try {
            $client->sendAll([$client->get('1'), $client->get('2'), $client->get('3')]);
            self::fail('No exception');
        } catch (BatchException $e) {
            self::assertSame([0, 2], array_keys($e->responses()));
            self::assertSame([1], array_keys($e->failures()));
            self::assertInstanceOf(NetworkException::class, $e->failures()[1]);
        }
    }

    public function testSendAllReadsAGenerator(): void
    {
        $mock = new MockTransport(...array_fill(0, 5, CastResponse::fromString('')));
        $client = $this->client($mock);
        $requests = (static function () use ($client) {
            for ($i = 0; $i < 5; $i++) {
                yield "r{$i}" => $client->get("x/{$i}");
            }
        })();

        self::assertCount(5, $client->sendAll($requests, 2));
    }

    public function testLoggerWritesOneLinePerRequestAndWarnsOnRetry(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message];
            }
        };
        $mock = new MockTransport(MockTransport::fail(), CastResponse::fromString('', 201));

        $this->client($mock)
            ->withLogger($logger)
            ->withRetry(RetryPolicy::fixed(2, 0))
            ->get('users?token=secret')
            ->withBearerToken('secret')
            ->send();

        self::assertCount(2, $logger->lines);
        self::assertSame('warning', $logger->lines[0][0]);
        self::assertStringContainsString('attempt 1 failed', $logger->lines[0][1]);
        self::assertSame('debug', $logger->lines[1][0]);
        self::assertMatchesRegularExpression('#^GET https://api\.test/v1/users → 201 \(\d+ ms, 2 attempts\)$#u', $logger->lines[1][1]);
        self::assertStringNotContainsString('secret', implode("\n", array_column($logger->lines, 1)));
    }

    public function testLoggerErrorAfterTheLastAttempt(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        try {
            $this->client(new MockTransport(MockTransport::fail()))->withLogger($logger)->get('x')->send();
        } catch (NetworkException) {
        }

        self::assertSame(['error'], $logger->levels);
    }

    public function testFacadeUsesTheDefaultClient(): void
    {
        $mock = new MockTransport(CastResponse::fromString('ok'));
        Cast::setClient(CastClient::create()->withTransport($mock));
        try {
            self::assertSame('ok', Cast::get('https://a.test/x')->send()->body());
            self::assertSame('https://a.test/x', (string) $mock->lastRequest()->getUri());
        } finally {
            Cast::setClient(null);
        }
    }

    public function testDebugInfoMasksTheToken(): void
    {
        self::assertStringNotContainsString('secret', print_r(CastClient::create()->withBearerToken('secret')->__debugInfo(), true));
    }
}
