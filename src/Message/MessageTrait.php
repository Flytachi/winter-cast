<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * What a request and a response share (PSR-7 MessageInterface): the protocol version, the
 * headers and the body.
 *
 * Header names are case-insensitive: they are looked up in any case and sent in the case
 * last set. Names must be RFC 9110 tokens; values may not contain line breaks or NUL —
 * so a value taken from user input cannot inject headers of its own.
 *
 * @internal
 */
trait MessageTrait
{
    /** @var array<string, list<string>> name as last set => values */
    private array $headers = [];

    /** @var array<string, string> lower-cased name => name as last set */
    private array $headerNames = [];

    private string $protocol = '1.1';

    private ?StreamInterface $stream = null;

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        if (!preg_match('/^\d(\.\d)?$/', $version)) {
            throw new InvalidArgumentException("Invalid HTTP protocol version '{$version}'");
        }
        if ($version === $this->protocol) {
            return $this;
        }
        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        $original = $this->headerNames[strtolower($name)] ?? null;

        return $original === null ? [] : $this->headers[$original];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $values = self::validateHeader($name, $value);
        $new = clone $this;
        $new->removeHeader($name);
        $new->headerNames[strtolower($name)] = $name;
        $new->headers[$name] = $values;

        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $values = self::validateHeader($name, $value);
        $new = clone $this;
        $lower = strtolower($name);
        if (isset($new->headerNames[$lower])) {
            $original = $new->headerNames[$lower];
            $new->headers[$original] = [...$new->headers[$original], ...$values];
        } else {
            $new->headerNames[$lower] = $name;
            $new->headers[$name] = $values;
        }

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }
        $new = clone $this;
        $new->removeHeader($name);

        return $new;
    }

    /**
     * Sets several headers: each replaces one of the same name (in any case), the rest stay.
     *
     * @param array<string, string|int|float|list<string|int|float>> $headers
     *
     * @throws InvalidArgumentException a name or a value is not a valid header
     */
    public function withHeaders(array $headers): static
    {
        $new = clone $this;
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $values = self::validateHeader($name, $value);
            $new->removeHeader($name);
            $new->headerNames[strtolower($name)] = $name;
            $new->headers[$name] = $values;
        }

        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->stream ??= CastStream::fromString();
    }

    public function withBody(StreamInterface $body): static
    {
        if ($body === $this->stream) {
            return $this;
        }
        $new = clone $this;
        $new->stream = $body;

        return $new;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function initHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $values = self::validateHeader($name, $value);
            $lower = strtolower($name);
            if (isset($this->headerNames[$lower])) {
                $original = $this->headerNames[$lower];
                $this->headers[$original] = [...$this->headers[$original], ...$values];
            } else {
                $this->headerNames[$lower] = $name;
                $this->headers[$name] = $values;
            }
        }
    }

    private function removeHeader(string $name): void
    {
        $lower = strtolower($name);
        if (isset($this->headerNames[$lower])) {
            unset($this->headers[$this->headerNames[$lower]], $this->headerNames[$lower]);
        }
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function validateHeader(string $name, mixed $value): array
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/D', $name)) {
            throw new InvalidArgumentException(
                "Invalid header name '" . addcslashes($name, "\0..\37") . "': must be an RFC 9110 token",
            );
        }

        $values = is_array($value) ? array_values($value) : [$value];
        if ($values === []) {
            throw new InvalidArgumentException("Header '{$name}' needs at least one value");
        }

        $result = [];
        foreach ($values as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                throw new InvalidArgumentException(
                    "Header '{$name}' values must be strings or numbers, " . get_debug_type($item) . ' given',
                );
            }
            $item = (string) $item;
            if (!preg_match('/^[ \t\x21-\x7E\x80-\xFF]*$/D', $item)) {
                throw new InvalidArgumentException(
                    "Invalid value for header '{$name}': line breaks, NUL and control characters are not allowed",
                );
            }
            $result[] = trim($item, " \t");
        }

        return $result;
    }
}
