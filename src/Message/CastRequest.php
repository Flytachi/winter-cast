<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

use Flytachi\Winter\Cast\Cast;
use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\Cast\Client\Redirects;
use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Client\Timeouts;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Transport\CurlTransport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 request, plus what makes building one short and what only this library does
 * with it: per-request timeouts, retries, streaming the response into a file, progress.
 *
 * Immutable: every `with*()` returns a copy.
 *
 * ```
 * $client->post('orders')
 *     ->withBearerToken($token)
 *     ->withJsonBody(['sku' => 'A-1'])
 *     ->withTimeout(5)
 *     ->send();
 * ```
 */
final class CastRequest implements RequestInterface
{
    use MessageTrait;

    private string $method;
    private CastUri $uri;
    private ?string $requestTarget = null;
    private ?CastClient $client = null;
    private ?Multipart $multipart = null;

    /** Headers that carry credentials: masked in dumps, dropped on a cross-origin redirect. */
    public const array SECRET_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'x-api-key'];

    /** @var array<string, true> more secret headers (lowercase), marked by AuthMiddleware::header() */
    private array $secret = [];

    /**
     * Settings of this send that override the client's; see transferOptions().
     *
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @param array<string, string|list<string>>          $headers
     * @param string|StreamInterface|resource|null         $body
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $method,
        string|UriInterface $uri,
        array $headers = [],
        mixed $body = null,
        string $version = '1.1',
    ) {
        $this->method = self::filterMethod($method);
        $this->uri = CastUri::parse($uri);
        $this->protocol = $version;
        $this->initHeaders($headers);
        if (!$this->hasHeader('Host')) {
            $this->updateHostFromUri();
        }
        if ($body !== null && $body !== '') {
            $this->stream = $body instanceof StreamInterface
                ? $body
                : (is_resource($body) ? CastStream::fromResource($body) : CastStream::fromString((string) $body));
        }
    }

    // ── Factories ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $query */
    public static function get(string|UriInterface $uri, array $query = []): self
    {
        return (new self('GET', $uri))->withQuery($query);
    }

    /** @param array<string, mixed> $query */
    public static function post(string|UriInterface $uri, array $query = []): self
    {
        return (new self('POST', $uri))->withQuery($query);
    }

    /** @param array<string, mixed> $query */
    public static function put(string|UriInterface $uri, array $query = []): self
    {
        return (new self('PUT', $uri))->withQuery($query);
    }

    /** @param array<string, mixed> $query */
    public static function patch(string|UriInterface $uri, array $query = []): self
    {
        return (new self('PATCH', $uri))->withQuery($query);
    }

    /** @param array<string, mixed> $query */
    public static function delete(string|UriInterface $uri, array $query = []): self
    {
        return (new self('DELETE', $uri))->withQuery($query);
    }

    /** @param array<string, mixed> $query */
    public static function head(string|UriInterface $uri, array $query = []): self
    {
        return (new self('HEAD', $uri))->withQuery($query);
    }

    /** @param array<string, mixed> $query */
    public static function options(string|UriInterface $uri, array $query = []): self
    {
        return (new self('OPTIONS', $uri))->withQuery($query);
    }

    /** Any PSR-7 request as a CastRequest — how the library accepts foreign requests. */
    public static function from(RequestInterface $request): self
    {
        if ($request instanceof self) {
            return $request;
        }
        $new = new self(
            $request->getMethod(),
            $request->getUri(),
            $request->getHeaders(),
            $request->getBody(),
            $request->getProtocolVersion(),
        );
        if ($request->getRequestTarget() !== $new->getRequestTarget()) {
            $new->requestTarget = $request->getRequestTarget();
        }

        return $new;
    }

    // ── PSR-7 ────────────────────────────────────────────────────────────────

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match('/\s/', $requestTarget)) {
            throw new InvalidArgumentException('A request target cannot contain whitespace');
        }
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        $new = clone $this;
        $new->method = self::filterMethod($method);

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        if ($uri === $this->uri) {
            return $this;
        }
        $new = clone $this;
        $new->uri = CastUri::parse($uri);
        if (!$preserveHost || !$this->hasHeader('Host')) {
            $new->updateHostFromUri();
        }

        return $new;
    }

    // ── Headers ──────────────────────────────────────────────────────────────

    /**
     * Replaces every header with `$headers` (Host is then taken from the URI again).
     *
     * @param array<string, string|list<string>> $headers
     */
    public function replaceHeaders(array $headers): self
    {
        $new = clone $this;
        $new->headers = [];
        $new->headerNames = [];
        $new->initHeaders($headers);
        if (!$new->hasHeader('Host')) {
            $new->updateHostFromUri();
        }

        return $new;
    }

    public function withBearerToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function withBasicAuth(string $user, string $password): self
    {
        return $this->withHeader('Authorization', 'Basic ' . base64_encode($user . ':' . $password));
    }

    public function withAccept(string ...$types): self
    {
        return $this->withHeader('Accept', implode(', ', $types));
    }

    /**
     * The User-Agent of this request. When sent, `WinterCast/{version} curl/{version}` is
     * appended — `myapp/1.0 WinterCast/2.0.0 curl/8.11.0` — unless `$exact`.
     */
    public function withUserAgent(string $agent, bool $exact = false): self
    {
        $new = $this->withHeader('User-Agent', $agent);
        $new->options['userAgentExact'] = $exact;

        return $new;
    }

    // ── Address ──────────────────────────────────────────────────────────────

    /**
     * Merges query parameters: given keys replace existing ones, others stay.
     *
     * @param array<string, mixed> $params
     */
    public function withQuery(array $params): self
    {
        if ($params === []) {
            return $this;
        }
        $new = clone $this;
        $new->uri = $this->uri->withQueryParams($params);

        return $new;
    }

    public function withQueryParam(string $name, mixed $value): self
    {
        return $this->withQuery([$name => $value]);
    }

    // ── Body ─────────────────────────────────────────────────────────────────

    /**
     * A JSON body and `Content-Type: application/json`.
     *
     * @throws InvalidArgumentException `$data` cannot be encoded
     */
    public function withJsonBody(mixed $data, int $flags = 0): self
    {
        try {
            $json = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Cannot encode the JSON body: ' . $e->getMessage(), 0, $e);
        }

        return $this->withStringBody($json, 'application/json');
    }

    /**
     * A form body (`a=1&b=2`) and `Content-Type: application/x-www-form-urlencoded`.
     *
     * @param array<string, mixed> $fields
     */
    public function withFormBody(array $fields): self
    {
        return $this->withStringBody(
            http_build_query($fields, '', '&', PHP_QUERY_RFC1738),
            'application/x-www-form-urlencoded',
        );
    }

    /**
     * A multipart/form-data body; files are sent as streams.
     *
     * @param Multipart|array<string, string|int|float|\CURLFile|\CURLStringFile> $parts
     */
    public function withMultipartBody(Multipart|array $parts): self
    {
        $multipart = $parts instanceof Multipart ? $parts : Multipart::fromArray($parts);
        $new = $this->withHeader('Content-Type', $multipart->contentType());
        $new->multipart = $multipart;
        $new->stream = null;

        return $new;
    }

    /** A body from a string; sets Content-Type when given. */
    public function withStringBody(string $body, ?string $contentType = null): self
    {
        $new = $contentType === null ? clone $this : $this->withHeader('Content-Type', $contentType);
        $new->stream = CastStream::fromString($body);
        $new->multipart = null;

        return $new;
    }

    /** A file as the body, sent as it is read — never loaded into memory. */
    public function withFileBody(string $path, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Cannot read the file '{$path}'");
        }
        $new = $this->withHeader('Content-Type', $contentType ?? 'application/octet-stream');
        $new->stream = CastStream::fromFile($path);
        $new->multipart = null;

        return $new;
    }

    public function getBody(): StreamInterface
    {
        if ($this->stream === null && $this->multipart !== null) {
            $this->stream = $this->multipart->toStream();
        }

        return $this->stream ??= CastStream::fromString();
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->stream = $body;
        $new->multipart = null;

        return $new;
    }

    // ── Settings of this send ───────────────────────────────────────────────

    /**
     * Seconds for one attempt and, when given, for connecting. The client's other timeouts
     * (idle, deadline) stay as they are.
     */
    public function withTimeout(float $total, ?float $connect = null): self
    {
        if (($this->options['timeouts'] ?? null) instanceof Timeouts) {
            $timeouts = $this->options['timeouts']->withTotal($total);

            return $this->withOption('timeouts', $connect === null ? $timeouts : $timeouts->withConnect($connect));
        }
        // Validated now, applied over the client's timeouts when sent.
        $connect === null ? Timeouts::of($total) : Timeouts::of($total, $connect);

        return $this->withOption('timeout', ['total' => $total, 'connect' => $connect]);
    }

    /** All timeouts of this request, replacing the client's. */
    public function withTimeouts(Timeouts $timeouts): self
    {
        $new = $this->withOption('timeouts', $timeouts);
        unset($new->options['timeout']);

        return $new;
    }

    public function withRetry(RetryPolicy $policy): self
    {
        return $this->withOption('retry', $policy);
    }

    /** No retries for this request, whatever the client's policy. */
    public function withoutRetry(): self
    {
        return $this->withOption('retry', RetryPolicy::none());
    }

    public function withRedirects(Redirects $redirects): self
    {
        return $this->withOption('redirects', $redirects);
    }

    /** The largest body kept in memory; null — no limit. */
    public function withMaxResponseSize(?int $bytes): self
    {
        if ($bytes !== null && $bytes < 0) {
            throw new InvalidArgumentException('maxResponseSize must not be negative');
        }

        return $this->withOption('maxResponseSize', $bytes);
    }

    /** Throw ClientErrorException / ServerErrorException on a 4xx / 5xx response. */
    public function withThrowOnError(bool $throw = true): self
    {
        return $this->withOption('throwOnError', $throw);
    }

    /**
     * Writes a successful (2xx) response body to `$target` as it arrives, not to memory.
     * An error response is kept in memory and the target is left alone.
     *
     * @param string|resource|StreamInterface $target a path (created or truncated), an open
     *        resource or a stream (written from its current position — for resuming)
     */
    public function sinkTo(mixed $target): self
    {
        if (!is_string($target) && !is_resource($target) && !$target instanceof StreamInterface) {
            throw new InvalidArgumentException('sinkTo() takes a path, an open resource or a stream');
        }

        return $this->withOption('sink', $target);
    }

    /** @param callable(TransferProgress): void $callback */
    public function onProgress(callable $callback): self
    {
        return $this->withOption('onProgress', $callback(...));
    }

    /**
     * cURL options of this request (a proxy, TLS settings…). Options the library itself
     * relies on are refused — see CurlTransport::RESERVED.
     *
     * @param array<int, mixed> $options
     */
    public function withCurlOptions(array $options): self
    {
        CurlTransport::assertNotReserved($options);

        return $this->withOption('curl', array_replace($this->options['curl'] ?? [], $options));
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /** Sends through the client this request came from, or the facade's default client. */
    public function send(): CastResponse
    {
        return ($this->client ?? Cast::client())->send($this);
    }

    /** Sends through any PSR-18 client. */
    public function sendVia(ClientInterface $client): ResponseInterface
    {
        return $client->sendRequest($this);
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /** @internal */
    public function bindTo(?CastClient $client): self
    {
        $new = clone $this;
        $new->client = $client;

        return $new;
    }

    /**
     * The settings this request overrides: timeouts, retry, redirects, maxResponseSize,
     * throwOnError, sink, onProgress, curl, userAgentExact — only the keys that were set.
     *
     * @return array<string, mixed>
     *
     * @internal
     */
    public function transferOptions(): array
    {
        return $this->options;
    }

    /**
     * Whether the body can be sent again (a retry, a 307, a new token): it is empty, seekable,
     * or a multipart that cURL reads anew. Rewinds it when it can.
     *
     * @internal
     */
    public function rewindBody(): bool
    {
        if ($this->multipart !== null && $this->stream === null) {
            return true;
        }
        $body = $this->getBody();
        if ($body->isSeekable()) {
            $body->rewind();

            return true;
        }

        return $body->getSize() === 0;
    }

    /**
     * Treats a header as a credential, like Authorization.
     *
     * @internal
     */
    public function markSecret(string $name): self
    {
        $new = clone $this;
        $new->secret[strtolower($name)] = true;

        return $new;
    }

    /**
     * Names of the headers of this request that carry credentials.
     *
     * @return list<string>
     *
     * @internal
     */
    public function secretHeaders(): array
    {
        $secret = array_fill_keys(self::SECRET_HEADERS, true) + $this->secret;

        return array_values(array_filter(
            array_keys($this->headers),
            static fn(string $name): bool => isset($secret[strtolower($name)]),
        ));
    }

    /** @internal */
    public function getMultipart(): ?Multipart
    {
        return $this->multipart;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        foreach ($this->secretHeaders() as $name) {
            $headers[$name] = ['***'];
        }

        return [
            'method' => $this->method,
            'uri' => $this->uri->redacted(),
            'headers' => $headers,
            'options' => array_keys($this->options),
        ];
    }

    private function withOption(string $name, mixed $value): self
    {
        $new = clone $this;
        $new->options[$name] = $value;

        return $new;
    }

    private function updateHostFromUri(): void
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return;
        }
        if ($this->uri->getPort() !== null) {
            $host .= ':' . $this->uri->getPort();
        }
        $lower = 'host';
        if (isset($this->headerNames[$lower])) {
            unset($this->headers[$this->headerNames[$lower]]);
        }
        $this->headerNames[$lower] = 'Host';
        // Host goes first, as RFC 9110 recommends.
        $this->headers = ['Host' => [$host]] + $this->headers;
    }

    private static function filterMethod(string $method): string
    {
        if ($method === '' || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $method)) {
            throw new InvalidArgumentException("Invalid HTTP method '{$method}'");
        }

        return $method;
    }
}
