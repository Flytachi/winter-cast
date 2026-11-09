<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * A multipart/form-data body: text fields and files, files sent as streams.
 *
 * ```
 * Multipart::create()
 *     ->withField('user', '42')
 *     ->withFile('avatar', '/tmp/me.png', 'me.png', 'image/png');
 * ```
 */
final readonly class Multipart
{
    /**
     * @param list<array{kind: string, name: string, value?: string, path?: string,
     *     stream?: StreamInterface, filename?: string, type?: ?string}> $parts
     */
    private function __construct(
        private array $parts,
        public string $boundary,
    ) {
    }

    public static function create(): self
    {
        return new self([], 'cast-' . bin2hex(random_bytes(16)));
    }

    /**
     * From an array the way 1.x and PHP forms build it: strings are fields, CURLFile and
     * CURLStringFile are files.
     *
     * @param array<string, string|int|float|\CURLFile|\CURLStringFile> $fields
     */
    public static function fromArray(array $fields): self
    {
        $multipart = self::create();
        foreach ($fields as $name => $value) {
            $name = (string) $name;
            $multipart = match (true) {
                $value instanceof \CURLFile => $multipart->withFile(
                    $name,
                    $value->getFilename(),
                    $value->getPostFilename() ?: null,
                    $value->getMimeType() ?: null,
                ),
                $value instanceof \CURLStringFile => $multipart->withContent(
                    $name,
                    $value->data,
                    $value->postname,
                    $value->mime ?: null,
                ),
                default => $multipart->withField($name, (string) $value),
            };
        }

        return $multipart;
    }

    public function withField(string $name, string $value): self
    {
        return $this->with(['kind' => 'field', 'name' => $name, 'value' => $value]);
    }

    /** A file from disk, sent as it is read. */
    public function withFile(string $name, string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Cannot read the file '{$path}'");
        }

        return $this->with([
            'kind' => 'file',
            'name' => $name,
            'path' => $path,
            'filename' => $filename ?? basename($path),
            'type' => $contentType,
        ]);
    }

    /** A file whose bytes are in memory. */
    public function withContent(string $name, string $content, string $filename, ?string $contentType = null): self
    {
        return $this->with([
            'kind' => 'content',
            'name' => $name,
            'value' => $content,
            'filename' => $filename,
            'type' => $contentType,
        ]);
    }

    /** A file from a stream (a file-backed stream is sent from disk). */
    public function withStream(
        string $name,
        StreamInterface $stream,
        string $filename,
        ?string $contentType = null,
    ): self {
        return $this->with([
            'kind' => 'stream',
            'name' => $name,
            'stream' => $stream,
            'filename' => $filename,
            'type' => $contentType,
        ]);
    }

    /**
     * Whether two parts share a name (`files[]` twice).
     *
     * @internal
     */
    public function hasRepeatedNames(): bool
    {
        $names = array_column($this->parts, 'name');

        return count($names) !== count(array_unique($names));
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /**
     * The fields as cURL takes them: strings, CURLFile, CURLStringFile — cURL then streams
     * the files and writes its own boundary.
     *
     * @return array<string, string|\CURLFile|\CURLStringFile>
     *
     * @internal
     */
    public function toCurlFields(): array
    {
        $fields = [];
        foreach ($this->parts as $part) {
            $fields[$part['name']] = match ($part['kind']) {
                'field' => $part['value'],
                'file' => new \CURLFile($part['path'], (string) $part['type'], $part['filename']),
                'content' => new \CURLStringFile($part['value'], $part['filename'], self::type($part)),
                default => self::streamField($part),
            };
        }

        return $fields;
    }

    /**
     * The body rendered as RFC 7578 bytes — for any PSR-18 client, not only this library's
     * transport. Files are copied into a temp stream (memory up to 2 MiB).
     */
    public function toStream(): CastStream
    {
        $out = CastStream::temp();
        foreach ($this->parts as $part) {
            $out->write("--{$this->boundary}\r\n");
            $disposition = 'Content-Disposition: form-data; name="' . self::quote($part['name']) . '"';
            if ($part['kind'] === 'field') {
                $out->write("{$disposition}\r\n\r\n{$part['value']}\r\n");
                continue;
            }
            $out->write($disposition . '; filename="' . self::quote($part['filename']) . "\"\r\n");
            $out->write('Content-Type: ' . ($part['type'] ?? 'application/octet-stream') . "\r\n\r\n");
            match ($part['kind']) {
                'file' => self::copy(CastStream::fromFile($part['path']), $out),
                'content' => $out->write($part['value']),
                default => self::copy($part['stream'], $out),
            };
            $out->write("\r\n");
        }
        $out->write("--{$this->boundary}--\r\n");
        $out->rewind();

        return $out;
    }

    /** @param array<string, mixed> $part */
    private function with(array $part): self
    {
        return new self([...$this->parts, $part], $this->boundary);
    }

    /** @param array<string, mixed> $part */
    private static function streamField(array $part): \CURLFile|\CURLStringFile
    {
        $stream = $part['stream'];
        $path = $stream instanceof CastStream ? $stream->getPath() : null;
        if ($path !== null) {
            return new \CURLFile($path, (string) $part['type'], $part['filename']);
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return new \CURLStringFile($stream->getContents(), $part['filename'], self::type($part));
    }

    /** @param array<string, mixed> $part */
    private static function type(array $part): string
    {
        return (string) ($part['type'] ?? 'application/octet-stream');
    }

    private static function copy(StreamInterface $from, StreamInterface $to): void
    {
        if ($from->isSeekable()) {
            $from->rewind();
        }
        while (!$from->eof()) {
            $to->write($from->read(65536));
        }
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
