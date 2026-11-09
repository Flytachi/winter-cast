<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Transport;

use CurlHandle;
use Flytachi\Winter\Cast\Client\TransferOptions;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastStream;
use Flytachi\Winter\Cast\Message\TransferProgress;
use Psr\Http\Message\StreamInterface;

/**
 * The state of one exchange: what cURL's callbacks have collected so far.
 *
 * @internal
 */
final class CurlTransfer
{
    public int $status = 0;
    public string $reason = '';
    public string $protocol = '1.1';
    public bool $sunk = false;
    public bool $tooLarge = false;
    public ?string $sinkError = null;

    /** @var list<array{0: string, 1: string}> */
    private array $headerLines = [];
    private bool $headersDone = false;
    private int $received = 0;
    private ?CastStream $memory = null;

    /** @var resource|null a file this transfer opened for a path sink */
    private $sinkFile = null;
    private ?StreamInterface $sinkStream = null;
    /** @var resource|null a resource sink owned by the caller */
    private $sinkResource = null;

    private ?string $lastProgress = null;

    public function __construct(
        private readonly CastRequest $request,
        private readonly TransferOptions $options,
    ) {
    }

    public function onHeader(CurlHandle $handle, string $line): int
    {
        $length = strlen($line);
        $line = rtrim($line, "\r\n");

        if (preg_match('#^HTTP/(\d(?:\.\d)?)\s+(\d{3})(?:\s+(.*))?$#', $line, $m)) {
            // A new response begins (after a 100 Continue, a proxy's 200…): start over.
            $this->protocol = $m[1];
            $this->status = (int) $m[2];
            $this->reason = trim($m[3] ?? '');
            $this->headerLines = [];
            $this->headersDone = false;
            // What the previous response decided about its body does not hold for this one.
            $this->sunk = false;
            $this->sinkStream = null;
            $this->sinkResource = null;
            $this->received = 0;
            $this->memory = null;

            return $length;
        }

        if ($line === '') {
            if ($this->status >= 200) {
                $this->headersDone = true;

                return $this->beginBody() ? $length : -1;
            }

            return $length;
        }

        $colon = strpos($line, ':');
        if ($colon !== false) {
            $this->headerLines[] = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
        }

        return $length;
    }

    public function onWrite(CurlHandle $handle, string $data): int
    {
        $length = strlen($data);

        if ($this->sunk) {
            if ($this->sinkStream === null && $this->sinkResource === null && $this->sinkFile === null) {
                if (!$this->openSinkFile()) {
                    return 0;
                }
            }
            $written = $this->sinkStream !== null
                ? $this->sinkStream->write($data)
                : fwrite($this->sinkFile ?? $this->sinkResource, $data);
            if ($written !== $length) {
                $this->sinkError = 'Cannot write the response body to the sink (disk full or not writable?)';

                return 0;
            }

            return $length;
        }

        $max = $this->options->maxResponseSize;
        if ($max !== null && $this->received + $length > $max) {
            $this->tooLarge = true;

            return 0;
        }
        $this->received += $length;
        ($this->memory ??= CastStream::temp())->write($data);

        return $length;
    }

    public function onProgress(
        CurlHandle $handle,
        int $downloadTotal,
        int $downloaded,
        int $uploadTotal,
        int $uploaded,
    ): int {
        $key = "{$downloaded}/{$downloadTotal}/{$uploaded}/{$uploadTotal}";
        if ($key !== $this->lastProgress) {
            $this->lastProgress = $key;
            ($this->options->onProgress)(new TransferProgress(
                $downloaded,
                $downloadTotal > 0 ? $downloadTotal : null,
                $uploaded,
                $uploadTotal > 0 ? $uploadTotal : null,
            ));
        }

        return 0;
    }

    public function failed(): bool
    {
        return $this->tooLarge || $this->sinkError !== null;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headerLines as [$name, $value]) {
            if ($name === '' || !preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/D', $name)) {
                continue; // a malformed header from the server is dropped, not fatal
            }
            if (!preg_match('/^[ \t\x21-\x7E\x80-\xFF]*$/D', $value)) {
                continue;
            }
            $headers[$name][] = $value;
        }

        return $headers;
    }

    public function responseBody(): StreamInterface
    {
        if ($this->sunk) {
            if ($this->sinkStream !== null) {
                return $this->sinkStream;
            }
            $sink = $this->options->sink;
            if (is_string($sink)) {
                return CastStream::fromFile($sink, 'rb');
            }

            return CastStream::fromString();   // a caller-owned resource: the caller reads it
        }

        $body = $this->memory ?? CastStream::fromString();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body;
    }

    /**
     * After a successful transfer: a path sink of a 2xx with an empty body is still created
     * (truncated), as asked. Returns an error message, or null.
     */
    public function finishSink(): ?string
    {
        if ($this->sunk && $this->sinkStream === null && $this->sinkResource === null && $this->sinkFile === null) {
            if (!$this->openSinkFile()) {
                return $this->sinkError;
            }
        }

        return null;
    }

    public function closeSink(): void
    {
        if (is_resource($this->sinkFile)) {
            fclose($this->sinkFile);
        }
    }

    /**
     * After a failed transfer: a partly written file of a path sink is removed. A file this
     * transfer never wrote to is left as it was.
     */
    public function discardSink(): void
    {
        if ($this->sinkFile !== null && is_string($this->options->sink) && is_file($this->options->sink)) {
            @unlink($this->options->sink);
        }
    }

    /** The file is opened (and truncated) only when the body's first bytes arrive. */
    private function openSinkFile(): bool
    {
        $path = (string) $this->options->sink;
        $file = @fopen($path, 'wb');
        if ($file === false) {
            $this->sinkError = "Cannot open the sink file '{$path}' for writing";

            return false;
        }
        $this->sinkFile = $file;

        return true;
    }

    /** Headers of the final response are complete: decide where its body goes. */
    private function beginBody(): bool
    {
        $sink = $this->options->sink;

        if ($sink !== null && $this->status >= 200 && $this->status < 300) {
            if ($sink instanceof StreamInterface) {
                $this->sinkStream = $sink;
            } elseif (is_resource($sink)) {
                $this->sinkResource = $sink;
            }
            $this->sunk = true;   // a path is opened on the first bytes of the body

            return true;
        }

        $max = $this->options->maxResponseSize;
        if ($max !== null && $this->request->getMethod() !== 'HEAD') {
            foreach ($this->headerLines as [$name, $value]) {
                if (strtolower($name) === 'content-length' && ctype_digit($value) && (int) $value > $max) {
                    $this->tooLarge = true;

                    return false;
                }
            }
        }

        return true;
    }
}
