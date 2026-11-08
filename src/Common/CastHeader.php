<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use Flytachi\Winter\Cast\Exception\CastException;

/**
 * Fluent builder for constructing HTTP request headers.
 *
 * CastHeader provides a chainable, type-safe API for building HTTP headers
 * with convenient helper methods for common headers like Authorization,
 * Content-Type, Accept, and User-Agent.
 *
 * All methods return `$this`, enabling method chaining for a clean,
 * expressive syntax when building complex header sets.
 *
 * Header names are case-insensitive, as in HTTP: `set('content-type', …)` replaces a
 * `Content-Type` set before, and `has()`, `get()`, `remove()` find it in any case. The
 * spelling sent is the one set last.
 *
 * A request keeps its own copy of the headers it is given (see
 * {@see CastRequest::withHeaders()}), so changing a CastHeader after handing it to a
 * request does not change that request.
 *
 * ---
 * ### Example 1: JSON API request with authentication
 *
 * ```
 * use Flytachi\Winter\Cast\Common\CastHeader;
 *
 * $headers = CastHeader::instance()
 *     ->json()                    // Accept + Content-Type: application/json
 *     ->authBearer($token)        // Authorization: Bearer ...
 *     ->acceptLanguage('ru')      // Accept-Language: ru
 *     ->userAgent('MyApp/1.0');   // User-Agent: MyApp/1.0 WinterCast/x.y.z curl/x.y.z
 * ```
 *
 * ---
 * ### Example 2: Custom headers
 *
 * ```
 * $headers = CastHeader::instance()
 *     ->set('X-API-Key', 'secret-key')
 *     ->set('X-Request-ID', uniqid())
 *     ->referer('https://example.com');
 *
 * $headers = CastHeader::from(['X-API-Key' => 'secret-key', 'Accept' => 'text/csv']);
 * ```
 *
 * ---
 * ### Example 3: Basic authentication
 *
 * ```
 * $headers = CastHeader::instance()
 *     ->authBasic('username', 'password')
 *     ->contentType('application/xml');
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Common
 * @author Flytachi
 */
class CastHeader
{
    /**
     * Lower-cased name => [name as last written, value].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $headers = [];

    /** Whether the User-Agent must be sent exactly as set, without the WinterCast/curl suffix. */
    private bool $exactUserAgent = false;

    public static function instance(): self
    {
        return (new self());
    }

    /**
     * Builds headers from a `name => value` map.
     *
     * @param array<string, string> $headers
     *
     * @throws CastException a name or a value is not a valid header (see {@see set()})
     */
    public static function from(array $headers): self
    {
        return self::instance()->merge($headers);
    }

    /**
     * Sets a header, replacing one of the same name in any letter case.
     *
     * @throws CastException the name is empty or contains `:`, or the name or the value
     *                       contains a line break or NUL — which would let a value inject
     *                       headers of its own into the request
     */
    public function set(string $key, string $value): self
    {
        if ($key === '' || strpbrk($key, ":\r\n\0 \t") !== false) {
            throw new CastException("Invalid header name: '" . addcslashes($key, "\r\n\0") . "'");
        }
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new CastException("Invalid value for header '{$key}': line breaks and NUL are not allowed");
        }

        $lower = strtolower($key);
        $this->headers[$lower] = [$key, $value];
        if ($lower === 'user-agent') {
            $this->exactUserAgent = false;
        }

        return $this;
    }

    /** Removes a header, in any letter case. */
    public function remove(string $key): self
    {
        $lower = strtolower($key);
        unset($this->headers[$lower]);
        if ($lower === 'user-agent') {
            $this->exactUserAgent = false;
        }

        return $this;
    }

    /** Whether a header is set, in any letter case. */
    public function has(string $key): bool
    {
        return isset($this->headers[strtolower($key)]);
    }

    /** A header's value, in any letter case; null when it is not set. */
    public function get(string $key): ?string
    {
        return $this->headers[strtolower($key)][1] ?? null;
    }

    /**
     * Every header as `name => value`, names as last written.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->headers as [$name, $value]) {
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Sets every header of `$other` on top of these; headers `$other` does not have are kept.
     *
     * @param CastHeader|array<string, string> $other
     *
     * @throws CastException a name or a value is not a valid header (see {@see set()})
     */
    public function merge(CastHeader|array $other): self
    {
        if ($other instanceof self) {
            foreach ($other->headers as $lower => $entry) {
                $this->headers[$lower] = $entry;
            }
            if ($other->has('User-Agent')) {
                $this->exactUserAgent = $other->exactUserAgent;
            }

            return $this;
        }

        foreach ($other as $name => $value) {
            $this->set((string) $name, (string) $value);
        }

        return $this;
    }

    public function auth(string $token): self
    {
        return $this->set('Authorization', $token);
    }

    public function authBearer(string $token): self
    {
        return $this->set('Authorization', 'Bearer ' . $token);
    }

    public function authBasic(string $username, string $password): self
    {
        return $this->set(
            'Authorization',
            'Basic ' . base64_encode($username . ':' . $password)
        );
    }

    public function json(): self
    {
        return $this->set('Accept', 'application/json')
            ->set('Content-Type', 'application/json');
    }

    /**
     * Sets the User-Agent.
     *
     * When sent, the client appends its own product tokens — `MyApp/1.0` goes out as
     * `MyApp/1.0 WinterCast/1.4.0 curl/8.11.0`: the application first (the most
     * significant, RFC 9110 §10.1.5), then what it runs on. With `$exact` the value is
     * sent exactly as given.
     *
     * @param bool $exact send the value as it is, without the WinterCast/curl suffix
     */
    public function userAgent(string $agent, bool $exact = false): self
    {
        $this->set('User-Agent', $agent);
        $this->exactUserAgent = $exact;

        return $this;
    }

    /** Whether the User-Agent was set with `$exact` — to be sent without the client's suffix. */
    public function isUserAgentExact(): bool
    {
        return $this->exactUserAgent;
    }

    public function acceptLanguage(string $language): self
    {
        return $this->set('Accept-Language', $language);
    }

    public function referer(string $url): self
    {
        return $this->set('Referer', $url);
    }

    public function contentType(string $type): self
    {
        return $this->set('Content-Type', $type);
    }

    /**
     * The headers as cURL wants them: `["Name: value", …]`.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->headers as [$name, $value]) {
            $result[] = "$name: $value";
        }
        return $result;
    }
}
