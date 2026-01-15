<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use Flytachi\Winter\Cast\Cast;
use Flytachi\Winter\Cast\Exception\CastException;

/**
 * Fluent builder for constructing and configuring HTTP requests.
 *
 * CastRequest provides a chainable API for building HTTP requests with full control
 * over method, URL, headers, body, timeouts, retries, and error handling.
 *
 * The class is fully immutable — all configuration methods return a new instance,
 * leaving the original unchanged. This enables safe request reuse and composition.
 *
 * The class uses static factory methods for each HTTP verb (get, post, put, patch,
 * delete, head), ensuring type-safe request creation.
 *
 * ---
 * ### Example 1: Simple GET request with query parameters
 *
 * ```
 * use Flytachi\Winter\Cast\Common\CastRequest;
 *
 * $response = CastRequest::get('https://api.com/users', ['page' => 1, 'limit' => 10])
 *     ->timeout(5)
 *     ->send();
 *
 * // Or add query params dynamically
 * $request = CastRequest::get('https://api.com/users')
 *     ->withQueryParam('page', 1)
 *     ->withQueryParam('limit', 10);
 * ```
 *
 * ---
 * ### Example 2: POST with JSON body and authentication
 *
 * ```
 * $response = CastRequest::post('https://api.com/users')
 *     ->withHeaders(
 *         CastHeader::instance()
 *             ->json()
 *             ->authBearer($token)
 *             ->userAgent('MyApp/1.0')
 *     )
 *     ->withJsonBody([
 *         'name' => 'John Doe',
 *         'email' => 'john@example.com'
 *     ])
 *     ->throwOnError()
 *     ->send();
 * ```
 *
 * ---
 * ### Example 3: File upload with multipart form data
 *
 * ```
 * $response = CastRequest::post('https://api.com/upload')
 *     ->withMultipartBody([
 *         'file' => new CURLFile('/path/to/file.jpg', 'image/jpeg'),
 *         'title' => 'My Photo',
 *         'description' => 'A beautiful sunset'
 *     ])
 *     ->maxResponseSize(50 * 1024 * 1024) // 50MB limit
 *     ->timeout(60)
 *     ->send();
 * ```
 *
 * ---
 * ### Example 4: Retry on failure with exponential backoff
 *
 * ```
 * // Exponential backoff (default): delays grow 500ms → 1000ms → 2000ms + jitter
 * $response = CastRequest::get('https://unreliable-api.com/data')
 *     ->timeout(10)
 *     ->connectTimeout(3)
 *     ->retry(3, 500) // 3 retries, 500ms base delay, exponential backoff enabled
 *     ->send();
 *
 * // Fixed delay (legacy): constant 1 second between retries
 * $response = CastRequest::get('https://api.com/data')
 *     ->retry(3, 1000, exponentialBackoff: false)
 *     ->send();
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Common
 * @author Flytachi
 *
 * @see CastClient
 * @see CastResponse
 * @see CastHeader
 */
class CastRequest
{
    private string $method;
    private string $url;
    private ?CastHeader $headers = null;
    private mixed $body = null;
    private bool $isMultipart = false;
    private ?int $timeout = null;
    private ?int $connectTimeout = null;
    private int $retryCount = 1;
    private int $retryDelay = 500; // base delay in milliseconds
    private bool $exponentialBackoff = true;
    private ?int $maxResponseSize = 10_485_760; // 10MB default
    private bool $throwOnError = false;
    private array $queryParams = [];
    private array $options = [];

    /**
     * Private constructor to force usage of static factory methods.
     * @throws CastException
     */
    private function __construct(string $method, string $url)
    {
        // Validate URL
        if (empty($url)) {
            throw new CastException("URL cannot be empty");
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false) {
            throw new CastException("Invalid URL format: missing or invalid protocol");
        }

        $scheme = strtolower($scheme);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new CastException("Only HTTP and HTTPS protocols are allowed, got: {$scheme}");
        }

        $this->method = $method;
        $this->url = $url;
    }

    // --- Static Factory Methods ---

    /**
     * @throws CastException
     */
    public static function get(string $url, ?array $queryParams = null): self
    {
        return new self('GET', self::buildUrl($url, $queryParams));
    }

    /**
     * @throws CastException
     */
    public static function post(string $url, ?array $queryParams = null): self
    {
        return new self('POST', self::buildUrl($url, $queryParams));
    }

    /**
     * @throws CastException
     */
    public static function put(string $url, ?array $queryParams = null): self
    {
        return new self('PUT', self::buildUrl($url, $queryParams));
    }

    /**
     * @throws CastException
     */
    public static function patch(string $url, ?array $queryParams = null): self
    {
        return new self('PATCH', self::buildUrl($url, $queryParams));
    }

    /**
     * @throws CastException
     */
    public static function delete(string $url, ?array $queryParams = null): self
    {
        return new self('DELETE', self::buildUrl($url, $queryParams));
    }

    /**
     * @throws CastException
     */
    public static function head(string $url, ?array $queryParams = null): self
    {
        return new self('HEAD', self::buildUrl($url, $queryParams));
    }

    // --- Configuration Methods ---

    public function withHeaders(CastHeader $headers): self
    {
        $clone = clone $this;
        $clone->headers = $headers;
        return $clone;
    }

    public function withBody(mixed $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function withJsonBody(array|object $data): self
    {
        $clone = clone $this;
        $clone->headers = $clone->headers ? clone $clone->headers : new CastHeader();
        $clone->headers->set('Content-Type', 'application/json');
        $clone->body = json_encode($data);
        return $clone;
    }

    public function withFormParams(array $data): self
    {
        $clone = clone $this;
        $clone->headers = $clone->headers ? clone $clone->headers : new CastHeader();
        $clone->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $clone->body = http_build_query($data);
        $clone->isMultipart = false;
        return $clone;
    }

    /**
     * Attaches a multipart/form-data body to the request.
     * This is used for file uploads.
     *
     * @param array $data Associative array of form fields.
     *                    To attach a file, use a CURLFile object as a value.
     *                    Example: ['field' => 'value', 'attachment' => new CURLFile('/path/to/file.jpg')]
     * @return self
     */
    public function withMultipartBody(array $data): self
    {
        $clone = clone $this;
        $clone->body = $data;
        $clone->isMultipart = true;
        if ($clone->headers) {
            $clone->headers = clone $clone->headers;
            $clone->headers->remove('Content-Type');
        }
        return $clone;
    }

    public function timeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = $seconds;
        return $clone;
    }

    public function connectTimeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->connectTimeout = $seconds;
        return $clone;
    }

    /**
     * Configure retry behavior for failed requests.
     *
     * @param int $count Number of retry attempts (minimum 1)
     * @param int $delayMs Base delay between retries in milliseconds
     * @param bool $exponentialBackoff When true, delay doubles with each attempt + random jitter.
     *                                  Example with 500ms base: 500ms → 1000ms → 2000ms (±30% jitter)
     * @return self
     */
    public function retry(int $count, int $delayMs = 500, bool $exponentialBackoff = true): self
    {
        $clone = clone $this;
        $clone->retryCount = max(1, $count);
        $clone->retryDelay = max(0, $delayMs);
        $clone->exponentialBackoff = $exponentialBackoff;
        return $clone;
    }

    /**
     * Set maximum response size in bytes.
     * Pass null to disable the limit (use with caution).
     *
     * @param int|null $bytes Maximum response size in bytes, or null to disable
     * @return self
     */
    public function maxResponseSize(?int $bytes): self
    {
        $clone = clone $this;
        $clone->maxResponseSize = $bytes !== null ? max(0, $bytes) : null;
        return $clone;
    }

    /**
     * Enable throwing exceptions on HTTP errors (4xx and 5xx responses).
     * By default, errors are returned as CastResponse objects without throwing.
     *
     * @param bool $throw Whether to throw exceptions on HTTP errors
     * @return self
     */
    public function throwOnError(bool $throw = true): self
    {
        $clone = clone $this;
        $clone->throwOnError = $throw;
        return $clone;
    }

    /**
     * Add a single query parameter.
     *
     * @param string $key Query parameter name
     * @param mixed $value Query parameter value
     * @return self
     */
    public function withQueryParam(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->queryParams[$key] = $value;
        return $clone;
    }

    /**
     * Add multiple query parameters.
     *
     * @param array $params Associative array of query parameters
     * @return self
     */
    public function withQueryParams(array $params): self
    {
        $clone = clone $this;
        $clone->queryParams = array_merge($clone->queryParams, $params);
        return $clone;
    }

    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->options = array_replace($clone->options, $options);
        return $clone;
    }

    // --- Getters for the Client ---

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        // Build URL with dynamic query parameters if any
        return self::buildUrl($this->url, $this->queryParams);
    }

    public function getHeaders(): CastHeader
    {
        return $this->headers ?? new CastHeader();
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    public function getConnectTimeout(): ?int
    {
        return $this->connectTimeout;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function useExponentialBackoff(): bool
    {
        return $this->exponentialBackoff;
    }

    public function getMaxResponseSize(): ?int
    {
        return $this->maxResponseSize;
    }

    public function shouldThrowOnError(): bool
    {
        return $this->throwOnError;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Checks if the request body should be treated as multipart/form-data.
     */
    public function isMultipart(): bool
    {
        return $this->isMultipart;
    }

    // --- Execution Method ---

    /**
     * Sends the request using the provided client.
     *
     * @param CastClient|null $client The client that will execute the request.
     * @return CastResponse
     * @throws CastException
     */
    public function send(?CastClient $client = null): CastResponse
    {
        $clientToUse = $client ?? Cast::getGlobalClient();
        return $clientToUse->send($this);
    }

    // --- Private Helpers ---

    private static function buildUrl(string $url, ?array $queryParams): string
    {
        if (empty($queryParams)) {
            return $url;
        }
        $queryString = http_build_query($queryParams);
        return str_contains($url, '?')
            ? "{$url}&{$queryString}"
            : "{$url}?{$queryString}";
    }
}
