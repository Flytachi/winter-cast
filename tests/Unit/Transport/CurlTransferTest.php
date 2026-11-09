<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Transport;

use Flytachi\Winter\Cast\Client\Redirects;
use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Client\Timeouts;
use Flytachi\Winter\Cast\Client\TransferOptions;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Tests\TestCase;
use Flytachi\Winter\Cast\Transport\CurlTransfer;

/** What cURL's callbacks do with a sequence of responses on one transfer. */
final class CurlTransferTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'cast-sink-');
        file_put_contents($this->path, 'keep me');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function transfer(): CurlTransfer
    {
        return new CurlTransfer(
            CastRequest::get('https://a.test/f'),
            new TransferOptions(Timeouts::of(), RetryPolicy::none(), Redirects::follow(), 10, false, false, sink: $this->path),
        );
    }

    /** @param list<string> $lines */
    private static function headers(CurlTransfer $transfer, \CurlHandle $handle, array $lines): void
    {
        foreach ($lines as $line) {
            self::assertSame(strlen($line . "\r\n"), $transfer->onHeader($handle, $line . "\r\n"));
        }
    }

    public function testProxyConnectResponseDoesNotOpenTheSink(): void
    {
        $handle = curl_init();
        $transfer = $this->transfer();

        self::headers($transfer, $handle, ['HTTP/1.1 200 Connection established', '']);
        self::headers($transfer, $handle, ['HTTP/1.1 404 Not Found', 'Content-Length: 3', '']);
        $transfer->onWrite($handle, 'abc');
        $transfer->closeSink();

        self::assertFalse($transfer->sunk);
        self::assertSame(404, $transfer->status);
        self::assertSame('abc', (string) $transfer->responseBody());
        self::assertSame('keep me', file_get_contents($this->path));
    }

    public function testErrorBodyOverTheLimitAfterAProxyResponse(): void
    {
        $handle = curl_init();
        $transfer = $this->transfer();

        self::headers($transfer, $handle, ['HTTP/1.1 200 Connection established', '']);
        self::headers($transfer, $handle, ['HTTP/1.1 500 Internal Server Error', '']);

        self::assertSame(0, $transfer->onWrite($handle, str_repeat('x', 11)));
        self::assertTrue($transfer->tooLarge);
    }

    public function testFileIsOpenedOnlyWhenTheBodyArrives(): void
    {
        $handle = curl_init();
        $transfer = $this->transfer();

        self::headers($transfer, $handle, ['HTTP/1.1 200 OK', '']);
        self::assertSame('keep me', file_get_contents($this->path), 'not truncated before the body');

        $transfer->onWrite($handle, 'new');
        $transfer->closeSink();

        self::assertTrue($transfer->sunk);
        self::assertSame('new', file_get_contents($this->path));
    }

    public function testEmptySuccessfulBodyStillTruncatesTheFile(): void
    {
        $handle = curl_init();
        $transfer = $this->transfer();

        self::headers($transfer, $handle, ['HTTP/1.1 200 OK', 'Content-Length: 0', '']);
        self::assertNull($transfer->finishSink());
        $transfer->closeSink();

        self::assertSame('', file_get_contents($this->path));
    }
}
