<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 URI, plus joining a relative path onto a base URL.
 *
 * ```
 * $api = CastUri::parse('https://api.example.com/v1');
 * (string) $api->resolve('users/42?expand=1');   // https://api.example.com/v1/users/42?expand=1
 * ```
 */
final class CastUri implements UriInterface
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];
    private const string UNRESERVED = 'a-zA-Z0-9_\-\.~';
    private const string SUB_DELIMS = '!\$&\'\(\)\*\+,;=';
    private const string GEN_DELIMS = ':\/\?#\[\]@';

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    /**
     * @throws InvalidArgumentException the string cannot be parsed as a URI, or the port is out of range
     */
    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException("Unable to parse URI '{$uri}'");
        }

        $this->scheme = isset($parts['scheme']) ? self::filterScheme($parts['scheme']) : '';
        $this->userInfo = isset($parts['user']) ? self::filterUserInfo($parts['user'], $parts['pass'] ?? null) : '';
        $this->host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $this->port = isset($parts['port']) ? $this->filterPort($parts['port']) : null;
        $this->path = isset($parts['path']) ? self::filterPath($parts['path']) : '';
        $this->query = isset($parts['query']) ? self::filterQueryOrFragment($parts['query']) : '';
        $this->fragment = isset($parts['fragment']) ? self::filterQueryOrFragment($parts['fragment']) : '';
    }

    /** @throws InvalidArgumentException */
    public static function parse(string|UriInterface $uri): self
    {
        if ($uri instanceof self) {
            return $uri;
        }

        return new self((string) $uri);
    }

    /** Whether the URI carries a scheme and a host — can be sent as it is. */
    public function isAbsolute(): bool
    {
        return $this->scheme !== '' && $this->host !== '';
    }

    /**
     * Joins `$relative` onto this (base) URI by appending, not by RFC 3986 resolution:
     *
     * | base                       | relative        | result                                  |
     * |----------------------------|-----------------|-----------------------------------------|
     * | `https://api.com`          | `users/42`      | `https://api.com/users/42`              |
     * | `https://api.com/v1`       | `users`         | `https://api.com/v1/users`              |
     * | `https://api.com/v1/`      | `/users`        | `https://api.com/v1/users`              |
     * | `https://api.com/v1?key=1` | `users?page=2`  | `https://api.com/v1/users?key=1&page=2` |
     *
     * RFC 3986 would turn `https://api.com/v1` + `users` into `https://api.com/users` — the last
     * segment of the base silently dropped. An absolute `$relative` is returned as it is.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string|UriInterface $relative): self
    {
        $relative = self::parse($relative);
        if ($relative->isAbsolute()) {
            return $relative;
        }

        $resolved = clone $this;
        $tail = ltrim($relative->path, '/');
        if ($tail !== '') {
            $resolved->path = rtrim($this->path, '/') . '/' . $tail;
        }
        if ($relative->query !== '') {
            $resolved->query = $this->query === '' ? $relative->query : $this->query . '&' . $relative->query;
        }
        $resolved->fragment = $relative->fragment;

        return $resolved;
    }

    /**
     * Merges `$params` into the query: given keys replace existing ones, others stay.
     *
     * @param array<string, mixed> $params
     */
    public function withQueryParams(array $params): self
    {
        if ($params === []) {
            return $this;
        }
        // The existing query is kept byte for byte (no parse_str(): it renames `a.b` to `a_b`
        // and keeps one of repeated keys); only pairs of the given names are dropped.
        $kept = [];
        foreach ($this->query === '' ? [] : explode('&', $this->query) as $pair) {
            $name = rawurldecode(urldecode(explode('=', $pair, 2)[0]));
            $base = ($bracket = strpos($name, '[')) === false ? $name : substr($name, 0, $bracket);
            if (!array_key_exists($base, $params)) {
                $kept[] = $pair;
            }
        }
        $added = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($added !== '') {
            $kept[] = $added;
        }

        $new = clone $this;
        $new->query = implode('&', $kept);

        return $new;
    }

    /**
     * The URI for messages and logs: without the user info and the query, which may carry
     * credentials (`https://user:pass@…`, `?api_key=…`).
     */
    public function redacted(): string
    {
        return $this->scheme . ($this->scheme !== '' ? '://' : '') . $this->host
            . ($this->port !== null ? ':' . $this->port : '')
            . $this->getPath();
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        $path = $this->path;
        if ($path !== '' && $path[0] !== '/') {
            if ($this->host !== '') {
                $path = '/' . $path;
            }
        } elseif (isset($path[1]) && $path[1] === '/') {
            $path = '/' . ltrim($path, '/');
        }

        return $path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $scheme = self::filterScheme($scheme);
        if ($scheme === $this->scheme) {
            return $this;
        }
        $new = clone $this;
        $new->scheme = $scheme;
        $new->port = $new->port === null ? null : $new->filterPort($new->port);

        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $info = self::filterUserInfo($user, $password);
        if ($info === $this->userInfo) {
            return $this;
        }
        $new = clone $this;
        $new->userInfo = $info;

        return $new;
    }

    public function withHost(string $host): UriInterface
    {
        $host = strtolower($host);
        if ($host === $this->host) {
            return $this;
        }
        $new = clone $this;
        $new->host = $host;

        return $new;
    }

    public function withPort(?int $port): UriInterface
    {
        $port = $this->filterPort($port);
        if ($port === $this->port) {
            return $this;
        }
        $new = clone $this;
        $new->port = $port;

        return $new;
    }

    public function withPath(string $path): UriInterface
    {
        $path = self::filterPath($path);
        if ($path === $this->path) {
            return $this;
        }
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    public function withQuery(string $query): UriInterface
    {
        $query = self::filterQueryOrFragment($query);
        if ($query === $this->query) {
            return $this;
        }
        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $fragment = self::filterQueryOrFragment($fragment);
        if ($fragment === $this->fragment) {
            return $this;
        }
        $new = clone $this;
        $new->fragment = $fragment;

        return $new;
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }
        $path = $this->path;
        if ($path !== '') {
            if ($path[0] !== '/') {
                if ($authority !== '') {
                    $path = '/' . $path;
                }
            } elseif (isset($path[1]) && $path[1] === '/' && $authority === '') {
                $path = '/' . ltrim($path, '/');
            }
            $uri .= $path;
        }
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /** RFC 3986: a letter, then letters, digits, `+`, `-`, `.`; or empty. */
    private static function filterScheme(string $scheme): string
    {
        if ($scheme !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9+\-.]*$/D', $scheme)) {
            throw new InvalidArgumentException("Invalid URI scheme '{$scheme}'");
        }

        return strtolower($scheme);
    }

    /** @throws InvalidArgumentException */
    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }
        if ($port < 0 || $port > 0xFFFF) {
            throw new InvalidArgumentException("Invalid port {$port}: must be between 0 and 65535");
        }

        return (self::DEFAULT_PORTS[$this->scheme] ?? null) === $port ? null : $port;
    }

    private static function filterUserInfo(string $user, ?string $password): string
    {
        $pattern = '/[' . self::GEN_DELIMS . self::SUB_DELIMS . ']++/';
        $info = preg_replace_callback($pattern, self::encode(...), $user);
        if ($password !== null && $password !== '') {
            $info .= ':' . preg_replace_callback($pattern, self::encode(...), $password);
        }

        return $info;
    }

    private static function filterPath(string $path): string
    {
        return preg_replace_callback(
            '/(?:[^' . self::UNRESERVED . self::SUB_DELIMS . '%:@\/]++|%(?![A-Fa-f0-9]{2}))/',
            self::encode(...),
            $path,
        );
    }

    private static function filterQueryOrFragment(string $value): string
    {
        return preg_replace_callback(
            '/(?:[^' . self::UNRESERVED . self::SUB_DELIMS . '%:@\/\?]++|%(?![A-Fa-f0-9]{2}))/',
            self::encode(...),
            $value,
        );
    }

    /** @param array<int, string> $match */
    private static function encode(array $match): string
    {
        return rawurlencode($match[0]);
    }
}
