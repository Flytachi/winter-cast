<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use Flytachi\Winter\Cast\Cast;

/**
 * Class CastRequest
 *
 * A fluent interface for building an HTTP request. This class allows you to
 * chain methods to configure every aspect of a request, such as the HTTP method,
 * URL, headers, body, and timeouts. Once configured, the request can be
 * sent via a CastClient.
 *
 * @version 1.0
 * @author Flytachi
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
    private array $options = [];

    /**
     * Private constructor to force usage of static factory methods.
     */
    private function __construct(string $method, string $url)
    {
        $this->method = $method;
        $this->url = $url;
    }

    // --- Static Factory Methods ---

    public static function get(string $url, ?array $queryParams = null): self
    {
        return new self('GET', self::buildUrl($url, $queryParams));
    }

    public static function post(string $url, ?array $queryParams = null): self
    {
        return new self('POST', self::buildUrl($url, $queryParams));
    }

    public static function put(string $url, ?array $queryParams = null): self
    {
        return new self('PUT', self::buildUrl($url, $queryParams));
    }

    public static function patch(string $url, ?array $queryParams = null): self
    {
        return new self('PATCH', self::buildUrl($url, $queryParams));
    }

    public static function delete(string $url, ?array $queryParams = null): self
    {
        return new self('DELETE', self::buildUrl($url, $queryParams));
    }

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
        // For multipart, the body IS the array. cURL handles the encoding.
        $this->body = $data;
        $this->isMultipart = true;

        // When using multipart/form-data, cURL sets the Content-Type header automatically,
        // including the correct boundary. We must NOT set it manually.
        if ($this->headers !== null) {
            $this->headers->remove('Content-Type');
        }

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
        return $this->url;
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
        // Check if the URL already has a query string
        return str_contains($url, '?') ? "{$url}&{$queryString}" : "{$url}?{$queryString}";
    }
}
