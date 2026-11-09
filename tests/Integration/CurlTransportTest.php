<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Integration;

use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Client\Timeouts;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Flytachi\Winter\Cast\Exception\TimeoutException;
use Flytachi\Winter\Cast\Exception\TooLargeException;
use Flytachi\Winter\Cast\Message\CastStream;
use Flytachi\Winter\Cast\Message\Multipart;
use Flytachi\Winter\Cast\Message\TransferProgress;
use Flytachi\Winter\Cast\Transport\CurlTransport;

final class CurlTransportTest extends IntegrationTestCase
{
    private function client(): CastClient
    {
        return CastClient::create()->withBaseUrl(self::url());
    }

    /** @return array<string, mixed> */
    private function echo(\Flytachi\Winter\Cast\Message\CastRequest $request): array
    {
        return $request->send()->jsonOrFail();
    }

    public function testUserAgent(): void
    {
        $suffix = CurlTransport::userAgentSuffix();
        self::assertMatchesRegularExpression('#^WinterCast/\S+ curl/\d+\.\d+#', $suffix);

        self::assertSame($suffix, $this->echo($this->client()->get('echo'))['headers']['user-agent']);
        self::assertSame(
            "app/1.0 {$suffix}",
            $this->echo($this->client()->withUserAgent('app/1.0')->get('echo'))['headers']['user-agent'],
        );
        self::assertSame(
            'app/1.0',
            $this->echo($this->client()->withUserAgent('app/1.0', exact: true)->get('echo'))['headers']['user-agent'],
        );
        self::assertSame(
            "req/2 {$suffix}",
            $this->echo($this->client()->withUserAgent('app/1.0', true)->get('echo')->withUserAgent('req/2'))['headers']['user-agent'],
            'the request decides about its own agent',
        );
    }

    public function testHeadersAndQuery(): void
    {
        $echo = $this->echo($this->client()->withHeader('X-Client', 'c')->get('echo', ['a' => '1 2'])
            ->withAddedHeader('X-Multi', 'a')->withAddedHeader('X-Multi', 'b'));

        self::assertSame('GET', $echo['method']);
        self::assertSame(['a' => '1 2'], $echo['query']);
        self::assertSame('c', $echo['headers']['x-client']);
        self::assertSame('a, b', $echo['headers']['x-multi']);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testHeadHasNoBody(): void
    {
        $response = $this->client()->head('bytes/1000')->send();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1000', $response->header('Content-Length'));
        self::assertSame('', $response->body());
    }

    public function testBodies(): void
    {
        $json = $this->echo($this->client()->post('echo')->withJsonBody(['a' => 1]));
        self::assertSame('{"a":1}', $json['body']);
        self::assertSame('application/json', $json['headers']['content-type']);

        $form = $this->echo($this->client()->post('echo')->withFormBody(['a' => 'x', 'b' => ['1', '2']]));
        self::assertSame(['a' => 'x', 'b' => ['1', '2']], $form['post']);

        $delete = $this->echo($this->client()->delete('echo'));
        self::assertSame('DELETE', $delete['method']);

        $empty = $this->echo($this->client()->post('echo'));
        self::assertSame('0', $empty['headers']['content-length']);
    }

    public function testLargeFileBodyIsStreamed(): void
    {
        $path = $this->tempFile(str_repeat('y', 3_000_000));
        $echo = $this->echo($this->client()->put('echo')->withFileBody($path));

        self::assertSame('PUT', $echo['method']);
        self::assertSame(3_000_000, $echo['bodyLength']);
        self::assertSame('3000000', $echo['headers']['content-length']);
    }

    public function testMultipart(): void
    {
        $path = $this->tempFile('file content');
        $echo = $this->echo($this->client()->post('echo')->withMultipartBody(
            Multipart::create()
                ->withField('name', 'n')
                ->withFile('file', $path, 'a.txt', 'text/plain')
                ->withContent('note', 'inline', 'note.txt')
                ->withStream('stream', CastStream::fromString('from stream'), 's.bin'),
        ));

        self::assertSame(['name' => 'n'], $echo['post']);
        self::assertSame(['name' => 'a.txt', 'type' => 'text/plain', 'size' => 12, 'sha1' => sha1('file content')], $echo['files']['file']);
        self::assertSame(sha1('inline'), $echo['files']['note']['sha1']);
        self::assertSame(sha1('from stream'), $echo['files']['stream']['sha1']);
    }

    public function testRepeatedMultipartNamesAreAllSent(): void
    {
        $echo = $this->echo($this->client()->post('echo')->withMultipartBody(
            Multipart::create()->withField('tag[]', 'a')->withField('tag[]', 'b')->withContent('f', 'x', 'f.txt'),
        ));

        self::assertSame(['tag' => ['a', 'b']], $echo['post']);
        self::assertSame(sha1('x'), $echo['files']['f']['sha1']);
    }

    public function testDecodedBodyDropsTheEncodingHeaders(): void
    {
        $response = $this->client()->get('gzip')->send();

        self::assertSame(str_repeat('compressible ', 1000), $response->body());
        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame('gzip', $response->header('X-Encoded-Content-Encoding'));
        self::assertNotNull($response->header('X-Encoded-Content-Length'));
    }

    public function testEmptyBodyStillCreatesTheSinkFile(): void
    {
        $path = sys_get_temp_dir() . '/cast-empty-' . bin2hex(random_bytes(4));
        try {
            $this->client()->get('bytes/0')->sinkTo($path)->send();
            self::assertSame(0, filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function testRedirects(): void
    {
        $response = $this->client()->post('redirect/302', ['to' => '/echo?step=2'])->withStringBody('abc')->send();
        self::assertSame('GET', $response->json()['method']);
        self::assertSame(['step' => '2'], $response->json()['query']);
        self::assertSame(1, $response->info()->redirects);

        $kept = $this->client()->put('redirect/307', ['to' => '/echo'])->withStringBody('abc')->send()->json();
        self::assertSame('PUT', $kept['method']);
        self::assertSame('abc', $kept['body']);
    }

    public function testCrossOriginRedirectDropsAuthorization(): void
    {
        $other = self::singleServers()[0] . '/echo';
        $echo = $this->client()->withBearerToken('secret')->get('redirect/302', ['to' => $other])->send()->json();

        self::assertArrayNotHasKey('authorization', $echo['headers']);
    }

    public function testErrorStatusIsAResponse(): void
    {
        $response = $this->client()->get('status/404')->send();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['status' => 404], $response->json());
    }

    public function testSinkToPath(): void
    {
        $path = $this->tempFile('old');
        $response = $this->client()->get('bytes/5000000')->sinkTo($path)->send();

        self::assertTrue($response->isSunk());
        self::assertSame(5_000_000, filesize($path));
        self::assertSame(5_000_000, $response->getBody()->getSize());
    }

    public function testSinkIsNotWrittenOnError(): void
    {
        $path = $this->tempFile('keep me');
        $response = $this->client()->get('status/404')->sinkTo($path)->send();

        self::assertFalse($response->isSunk());
        self::assertSame(['status' => 404], $response->json());
        self::assertSame('keep me', file_get_contents($path));
    }

    public function testSinkToResourceAndStream(): void
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, 'head:');
        $this->client()->get('bytes/10')->sinkTo($resource)->send();
        rewind($resource);
        self::assertSame('head:xxxxxxxxxx', stream_get_contents($resource));

        $stream = CastStream::temp();
        $response = $this->client()->get('stream/100000')->sinkTo($stream)->send();
        self::assertSame($stream, $response->getBody());
        self::assertSame(100_000, $stream->getSize());
    }

    public function testMaxResponseSize(): void
    {
        $client = $this->client()->withMaxResponseSize(1000);

        self::assertSame(1000, strlen($client->get('bytes/1000')->send()->body()));

        try {
            $client->get('bytes/1001')->send();
            self::fail('No exception by Content-Length');
        } catch (TooLargeException $e) {
            self::assertSame(1000, $e->getLimit());
        }

        $this->expectException(TooLargeException::class);
        $client->get('stream/50000')->send();
    }

    public function testSinkHasNoSizeLimit(): void
    {
        $path = $this->tempFile();
        $this->client()->withMaxResponseSize(10)->get('bytes/1000')->sinkTo($path)->send();

        self::assertSame(1000, filesize($path));
    }

    public function testProgress(): void
    {
        /** @var list<TransferProgress> $seen */
        $seen = [];
        $this->client()->get('bytes/3000000')->onProgress(function (TransferProgress $p) use (&$seen) {
            $seen[] = $p;
        })->send();

        self::assertNotEmpty($seen);
        $last = end($seen);
        self::assertSame(3_000_000, $last->downloaded);
        self::assertSame(3_000_000, $last->downloadTotal);
        self::assertSame(100.0, $last->downloadPercent());
    }

    public function testTimeout(): void
    {
        try {
            $this->client()->withTimeout(0.2)->get('slow/2000')->send();
            self::fail('No exception');
        } catch (TimeoutException $e) {
            self::assertFalse($e->isConnect());
            self::assertFalse($e->isDeadline());
            self::assertTrue($e->isTransient());
        }
    }

    public function testIdleTimeout(): void
    {
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('too slow');
        $this->client()->withTimeouts(Timeouts::forDownload(idle: 1, minBytesPerSecond: 1000))->get('stall')->send();
    }

    public function testConnectionRefused(): void
    {
        try {
            CastClient::create()->get('http://127.0.0.1:' . self::closedPort() . '/')->send();
            self::fail('No exception');
        } catch (NetworkException $e) {
            self::assertSame(CURLE_COULDNT_CONNECT, $e->getCurlErrno());
            self::assertTrue($e->isTransient());
        }
    }

    public function testRetriesOverTheNetwork(): void
    {
        $response = $this->client()
            ->withRetry(RetryPolicy::exponential(3, 10)->withStatuses(503))
            ->get('flaky/' . bin2hex(random_bytes(4)) . '/2')
            ->send();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $response->info()->attempts);
        self::assertSame(3, $response->json()['attempt']);
    }

    public function testDeadline(): void
    {
        $start = microtime(true);
        try {
            $this->client()
                ->withTimeouts(Timeouts::of(0.3)->withDeadline(0.5))
                ->withRetry(RetryPolicy::fixed(10, 10))
                ->get('slow/2000')
                ->send();
            self::fail('No exception');
        } catch (TimeoutException $e) {
            self::assertTrue($e->isDeadline());
        }
        self::assertLessThan(0.9, microtime(true) - $start);
    }

    public function testTransferInfo(): void
    {
        $info = $this->client()->get('echo')->send()->info();

        self::assertSame('1.1', $info->protocol);
        self::assertSame('127.0.0.1', $info->primaryIp);
        self::assertSame(self::url('echo'), $info->effectiveUri);
        self::assertGreaterThan(0, $info->totalTime);
    }

    public function testSendAllRunsConcurrently(): void
    {
        $client = CastClient::create();
        $requests = array_map(static fn(string $base) => $client->get($base . '/slow/400'), self::singleServers());

        $start = microtime(true);
        $responses = $client->sendAll($requests);
        $elapsed = microtime(true) - $start;

        self::assertCount(4, $responses);
        self::assertLessThan(0.75, $elapsed, sprintf('4 × 400 ms took %.2f s', $elapsed));
    }

    public function testSendAllRetriesDoNotBlockOthers(): void
    {
        $client = CastClient::create()->withRetry(RetryPolicy::fixed(2, 300)->withStatuses(503));
        $servers = self::singleServers();

        $start = microtime(true);
        $responses = $client->sendAll([
            'flaky' => $client->get($servers[0] . '/flaky/' . bin2hex(random_bytes(4)) . '/1'),
            'slow' => $client->get($servers[1] . '/slow/300'),
        ]);
        $elapsed = microtime(true) - $start;

        self::assertSame(200, $responses['flaky']->getStatusCode());
        self::assertSame(2, $responses['flaky']->info()->attempts);
        self::assertLessThan(0.55, $elapsed, sprintf('took %.2f s', $elapsed));
    }
}
