<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Message;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Exception\HttpException;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 response, plus reading it in one call: `->json()`, `->throw()`, `->info()`.
 *
 * The body is a stream: in memory (a temp file beyond 2 MiB), or the file a request asked
 * for with sinkTo().
 *
 * ```
 * $user = $client->get('users/42')->send()->throw()->json();
 * ```
 */
final class CastResponse implements ResponseInterface
{
    use MessageTrait;

    private const array PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information',
        204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status',
        208 => 'Already Reported', 226 => 'IM Used',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
        411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Content Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed', 418 => "I'm a teapot", 421 => 'Misdirected Request',
        422 => 'Unprocessable Content', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Too Early',
        426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected',
        510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    private int $statusCode;
    private string $reasonPhrase;
    private ?CastRequest $request = null;
    private ?TransferInfo $info = null;
    private bool $sunk = false;

    /**
     * @param array<string, string|list<string>>  $headers
     * @param string|StreamInterface|resource|null $body
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        int $status = 200,
        array $headers = [],
        mixed $body = null,
        string $version = '1.1',
        ?string $reason = null,
    ) {
        $this->statusCode = self::filterStatus($status);
        $this->reasonPhrase = $reason ?? (self::PHRASES[$status] ?? '');
        $this->protocol = $version;
        $this->initHeaders($headers);
        if ($body !== null && $body !== '') {
            $this->stream = $body instanceof StreamInterface
                ? $body
                : (is_resource($body) ? CastStream::fromResource($body) : CastStream::fromString((string) $body));
        }
    }

    /**
     * A JSON response — for tests and mock transports.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function fromJson(mixed $data, int $status = 200, array $headers = []): self
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Cannot encode the JSON body: ' . $e->getMessage(), 0, $e);
        }

        return new self($status, ['Content-Type' => 'application/json'] + $headers, $json);
    }

    /**
     * A response with a string body — for tests and mock transports.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function fromString(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers, $body);
    }

    // ── PSR-7 ────────────────────────────────────────────────────────────────

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $new = clone $this;
        $new->statusCode = self::filterStatus($code);
        $new->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (self::PHRASES[$code] ?? '');

        return $new;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /** The status as winter-base's enum; null for a code it does not list. */
    public function status(): ?HttpCode
    {
        return HttpCode::tryFrom($this->statusCode);
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /** The whole body as a string. For a sunk response this reads the file — mind its size. */
    public function body(): string
    {
        return (string) $this->getBody();
    }

    /**
     * The body as a JSON object or array; null when it is empty, not JSON, or a JSON scalar.
     *
     * @return array<array-key, mixed>|null
     */
    public function json(): ?array
    {
        $body = $this->body();
        if ($body === '' || !json_validate($body)) {
            return null;
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Like json(), but a body that is not a JSON object or array is an error.
     *
     * @return array<array-key, mixed>
     *
     * @throws CastException
     */
    public function jsonOrFail(): array
    {
        return $this->json() ?? throw new CastException(sprintf(
            'Expected a JSON object or array in the response (HTTP %d, %s)',
            $this->statusCode,
            $this->getHeaderLine('Content-Type') ?: 'no Content-Type',
        ));
    }

    /** A header's value as one line; null when there is no such header. */
    public function header(string $name): ?string
    {
        return $this->hasHeader($name) ? $this->getHeaderLine($name) : null;
    }

    /**
     * Throws on a 4xx/5xx status, otherwise returns itself — for chains:
     * `->send()->throw()->json()`.
     *
     * @throws HttpException ClientErrorException (4xx) or ServerErrorException (5xx)
     */
    public function throw(): static
    {
        if ($this->statusCode >= 400) {
            throw HttpException::from($this, $this->request ?? new CastRequest('GET', '/'));
        }

        return $this;
    }

    /** What happened during the transfer: times, attempts, redirects, the final address. */
    public function info(): TransferInfo
    {
        return $this->info ?? new TransferInfo();
    }

    /** The request that got this response (its last attempt), when the library sent it. */
    public function getRequest(): ?CastRequest
    {
        return $this->request;
    }

    /** Whether the body went to a sinkTo() target rather than to memory. */
    public function isSunk(): bool
    {
        return $this->sunk;
    }

    /** @internal */
    public function withTransfer(CastRequest $request, TransferInfo $info, bool $sunk): self
    {
        $new = clone $this;
        $new->request = $request;
        $new->info = $info;
        $new->sunk = $sunk;

        return $new;
    }

    /** @internal */
    public function withInfo(TransferInfo $info): self
    {
        $new = clone $this;
        $new->info = $info;

        return $new;
    }

    private static function filterStatus(int $status): int
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException("Invalid HTTP status code {$status}: must be between 100 and 599");
        }

        return $status;
    }
}
