<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Integration;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Stereotype\BearerAuthMiddleware;
use Flytachi\Winter\Cast\Stereotype\HeadersMiddleware;
use Flytachi\Winter\Cast\Tests\TestCase;

/**
 * What actually goes over the wire. Unit tests look at the request object; these send it
 * to a local `php -S` that echoes the headers back — the check that would have caught
 * the middlewares wiping every header of a request.
 */
class SentHeadersTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $url = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $router = __DIR__ . '/fixtures/echo-headers.php';
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::$url = "http://127.0.0.1:{$port}/echo";

        // wait until it accepts connections
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        if (!is_resource(self::$server)) {
            $this->markTestSkipped('could not start php -S');
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }

    /** @return array<string, string> the headers the server received, lower-cased names */
    private function sent(CastRequest $request, ?CastClient $client = null): array
    {
        $body = $request->send($client ?? new CastClient())->json();
        $this->assertIsArray($body, 'the echo server did not answer with JSON');

        return $body['headers'];
    }

    private static function suffixPattern(): string
    {
        return 'WinterCast\/\S+ curl\/[0-9][0-9.]*';
    }

    // ── User-Agent ───────────────────────────────────────────────────────────

    public function testDefaultUserAgent(): void
    {
        $headers = $this->sent(CastRequest::get(self::$url));

        $this->assertMatchesRegularExpression('/^' . self::suffixPattern() . '$/', $headers['user-agent']);
    }

    public function testOwnUserAgentGetsTheSuffix(): void
    {
        $headers = $this->sent(CastRequest::get(self::$url)->withHeaders(CastHeader::instance()->userAgent('myapp/1.0')));

        $this->assertMatchesRegularExpression('/^myapp\/1\.0 ' . self::suffixPattern() . '$/', $headers['user-agent']);
    }

    public function testExactUserAgentIsSentAsIs(): void
    {
        $request = CastRequest::get(self::$url)->withHeaders(CastHeader::instance()->userAgent('myapp/1.0', exact: true));

        $this->assertSame('myapp/1.0', $this->sent($request)['user-agent']);
    }

    public function testSuffixIsNotDuplicated(): void
    {
        $request = CastRequest::get(self::$url)->withHeader('User-Agent', 'myapp/1.0 WinterCast/9.9.9 curl/1.0.0');

        $this->assertSame('myapp/1.0 WinterCast/9.9.9 curl/1.0.0', $this->sent($request)['user-agent']);
    }

    // ── Default headers ──────────────────────────────────────────────────────

    public function testDefaultHeadersAreSentAndRequestWinsByName(): void
    {
        $client = new CastClient(defaultHeaders: CastHeader::instance()
            ->set('Accept', 'application/json')
            ->set('X-Tenant', 'acme')
            ->userAgent('myapp/1.0'));

        $headers = $this->sent(CastRequest::get(self::$url)->withHeader('accept', 'text/csv'), $client);

        $this->assertSame('text/csv', $headers['accept']);   // the request's own wins
        $this->assertSame('acme', $headers['x-tenant']);     // the default stays
        $this->assertStringStartsWith('myapp/1.0 WinterCast/', $headers['user-agent']);
    }

    // ── Middlewares on the wire ──────────────────────────────────────────────

    public function testBearerMiddlewareKeepsJsonHeaders(): void
    {
        $client = (new CastClient())->addMiddleware(new BearerAuthMiddleware('tok'));
        $request = CastRequest::post(self::$url)
            ->withHeaders(CastHeader::instance()->set('Accept', 'application/json')->userAgent('myapp/1.0'))
            ->withJsonBody(['a' => 1]);

        $headers = $this->sent($request, $client);

        $this->assertSame('Bearer tok', $headers['authorization']);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame('application/json', $headers['accept']);
        $this->assertStringStartsWith('myapp/1.0 WinterCast/', $headers['user-agent']);
    }

    public function testHeadersMiddlewareKeepsRequestHeaders(): void
    {
        $client = (new CastClient())->addMiddleware(new HeadersMiddleware(['X-Api-Key' => 'k']));
        $request = CastRequest::post(self::$url)->withJsonBody(['a' => 1]);

        $headers = $this->sent($request, $client);

        $this->assertSame('k', $headers['x-api-key']);
        $this->assertSame('application/json', $headers['content-type']);
    }
}
