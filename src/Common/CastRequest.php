<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use Flytachi\Winter\Cast\Cast;
use Flytachi\Winter\Cast\Exception\CastException;

/**
 * Fluent builder for constructing and configuring HTTP requests.
 *
 * CastRequest provides a chainable API for building HTTP requests with full control
 * over method, URL, headers, body, timeouts, retries, and error handling. Requests
 * are immutable once created and can be sent via a CastClient instance.
 *
 * The class uses static factory methods for each HTTP verb (get, post, put, patch,
 * delete, head), ensuring type-safe request creation. All configuration methods
 * return `$this` for method chaining.
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
 * ### Example 4: Retry on failure with custom timeouts
 *
 * ```
 * $response = CastRequest::get('https://unreliable-api.com/data')
 *     ->timeout(10)
 *     ->connectTimeout(3)
 *     ->retry(3, 1000) // 3 retries, 1 second delay
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
    private int $retryDelay = 500; // in milliseconds
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
        $this->headers = $headers;
        return $this;
    }

    public function withBody(mixed $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function withJsonBody(array|object $data): self
    {
        if ($this->headers === null) {
            $this->headers = new CastHeader();
        }
        $this->headers->set('Content-Type', 'application/json');
        $this->body = json_encode($data);
        return $this;
    }

    public function withFormParams(array $data): self
    {
        if ($this->headers === null) {
            $this->headers = new CastHeader();
        }
        $this->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $this->body = http_build_query($data);
        $this->isMultipart = false;
        return $this;
    }

    /**
     * Attaches a multipart/form-data body to the request.
     * This is used for file uploads.
     *
     * @param array $data Associative array of form fields.
     *                    To attach a file, use a CURLFile object as a value.
     *                    Example: ['field' => 'value', 'attachment' => new CURLFile('/path/to/file.jpg')]
     * @return $this
     */
    public function withMultipartBody(array $data): self
    {
        $this->body = $data;
        $this->isMultipart = true;
        $this->headers?->remove('Content-Type');
        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function connectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function retry(int $count, int $delayMs = 500): self
    {
        $this->retryCount = max(1, $count);
        $this->retryDelay = max(0, $delayMs);
        return $this;
    }

    /**
     * Set maximum response size in bytes.
     * Pass null to disable the limit (use with caution).
     *
     * @param int|null $bytes Maximum response size in bytes, or null to disable
     * @return $this
     */
    public function maxResponseSize(?int $bytes): self
    {
        $this->maxResponseSize = $bytes !== null ? max(0, $bytes) : null;
        return $this;
    }

    /**
     * Enable throwing exceptions on HTTP errors (4xx and 5xx responses).
     * By default, errors are returned as CastResponse objects without throwing.
     *
     * @param bool $throw Whether to throw exceptions on HTTP errors
     * @return $this
     */
    public function throwOnError(bool $throw = true): self
    {
        $this->throwOnError = $throw;
        return $this;
    }

    /**
     * Add a single query parameter.
     *
     * @param string $key Query parameter name
     * @param mixed $value Query parameter value
     * @return $this
     */
    public function withQueryParam(string $key, mixed $value): self
    {
        $this->queryParams[$key] = $value;
        return $this;
    }

    /**
     * Add multiple query parameters.
     *
     * @param array $params Associative array of query parameters
     * @return $this
     */
    public function withQueryParams(array $params): self
    {
        $this->queryParams = array_merge($this->queryParams, $params);
        return $this;
    }

    public function withOptions(array $options): self
    {
        $this->options = array_replace($this->options, $options);
        return $this;
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
