<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 stream: the body of a request or a response, without holding it in memory when
 * it does not fit there.
 *
 * ```
 * CastStream::fromString('{"a":1}');               // small, in memory
 * CastStream::fromFile('/var/backups/db.sql');      // a file, read as it is sent
 * CastStream::temp();                               // memory up to 2 MiB, a temp file beyond
 * ```
 */
final class CastStream implements StreamInterface
{
    private const string READABLE = '/r|a\+|ab\+|w\+|wb\+|x\+|xb\+|c\+|cb\+/';
    private const string WRITABLE = '/a|w|r\+|rb\+|rw|x|c/';

    /** @var resource|null */
    private $resource;

    private ?int $size;
    private bool $seekable;
    private bool $readable;
    private bool $writable;
    private ?string $uri;

    /**
     * @param resource $resource an open stream resource; the stream takes ownership of it
     */
    private function __construct($resource)
    {
        $this->resource = $resource;
        $meta = stream_get_meta_data($resource);
        $this->seekable = $meta['seekable'];
        $this->readable = (bool) preg_match(self::READABLE, $meta['mode']);
        $this->writable = (bool) preg_match(self::WRITABLE, $meta['mode']);
        $this->uri = $meta['uri'] ?? null;
        $this->size = null;
    }

    /** A stream over a string, positioned at the start. */
    public static function fromString(string $content = ''): self
    {
        $resource = fopen('php://temp', 'r+');
        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new self($resource);
    }

    /**
     * A stream over a file.
     *
     * @throws InvalidArgumentException the mode is invalid
     * @throws \RuntimeException        the file cannot be opened
     */
    public static function fromFile(string $path, string $mode = 'r'): self
    {
        if ($mode === '' || !preg_match('/^[rwaxc][bt+]*$/', $mode)) {
            throw new InvalidArgumentException("Invalid file mode '{$mode}'");
        }
        $resource = @fopen($path, $mode);
        if ($resource === false) {
            throw new \RuntimeException("Cannot open '{$path}' with mode '{$mode}'");
        }

        return new self($resource);
    }

    /**
     * A stream over an open resource; the stream takes ownership of it.
     *
     * @param resource $resource
     *
     * @throws InvalidArgumentException not a stream resource
     */
    public static function fromResource($resource): self
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException('A stream resource is required');
        }

        return new self($resource);
    }

    /** An empty read-write stream: in memory up to `$memoryLimit` bytes, then a temp file. */
    public static function temp(int $memoryLimit = 2 * 1024 * 1024): self
    {
        return new self(fopen("php://temp/maxmemory:{$memoryLimit}", 'r+'));
    }

    /** The path of the file behind the stream, when there is one. */
    public function getPath(): ?string
    {
        if ($this->uri === null || str_starts_with($this->uri, 'php://')) {
            return null;
        }

        return $this->uri;
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }
        try {
            if ($this->seekable) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            $resource = $this->detach();
            fclose($resource);
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        if ($this->size !== null) {
            return $this->size;
        }
        if ($this->uri !== null && !str_starts_with($this->uri, 'php://')) {
            clearstatcache(true, $this->uri);
        }
        $stats = fstat($this->resource);

        return is_array($stats) && isset($stats['size']) ? $stats['size'] : null;
    }

    public function tell(): int
    {
        $this->assertAttached();
        $position = ftell($this->resource);
        if ($position === false) {
            throw new \RuntimeException('Unable to determine the stream position');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }
        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new \RuntimeException("Unable to seek to position {$offset}");
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        $this->assertAttached();
        if (!$this->writable) {
            throw new \RuntimeException('Stream is not writable');
        }
        $this->size = null;
        $written = fwrite($this->resource, $string);
        if ($written === false) {
            throw new \RuntimeException('Unable to write to the stream');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        $this->assertAttached();
        if (!$this->readable) {
            throw new \RuntimeException('Stream is not readable');
        }
        if ($length < 0) {
            throw new \RuntimeException('Length must not be negative');
        }
        if ($length === 0) {
            return '';
        }
        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read from the stream');
        }

        return $data;
    }

    public function getContents(): string
    {
        $this->assertAttached();
        if (!$this->readable) {
            throw new \RuntimeException('Stream is not readable');
        }
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the stream contents');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }
        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function assertAttached(): void
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }
    }
}
