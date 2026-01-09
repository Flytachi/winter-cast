<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

use Flytachi\Winter\Base\HttpCode;

/**
 * Class CastResponse
 *
 * Represents the result of an executed HTTP request.
 * This is an immutable Data Transfer Object (DTO) that encapsulates all
 * information about the response, including the status code, headers, body,
 * and technical details from the cURL transfer.
 *
 * @author Flytachi
 */
readonly class CastResponse
{
    /**
     * @param int     $statusCode   The HTTP status code of the response. Is 0 in case of a connection error.
     * @param ?string $body         The response body as a string. Null if the body is empty or an error occurred.
     * @param array   $headers      An associative array of the response headers.
     * @param array   $info         An array containing detailed information from the cURL transfer (from curl_getinfo).
     */
    public function __construct(
        public int $statusCode,
        public ?string $body,
        public array $headers,
        public array $info
    ) {
    }

    /**
     * Returns the HTTP status as a typed HttpCode enum case.
     *
     * @return HttpCode|null Returns null if the status code is non-standard (e.g., 0).
     */
    public function status(): ?HttpCode
    {
        return HttpCode::tryFrom($this->statusCode);
    }

    /**
     * Returns the raw response body as a string.
     */
    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * Attempts to decode the JSON response body into an associative array.
     *
     * @return array|null Returns null if the body is empty or not valid JSON.
     */
    public function json(): ?array
    {
        if (empty($this->body)) {
            return null;
        }

        // Use json_validate() from PHP 8.3 for a fast check before decoding.
        if (function_exists('json_validate') && !json_validate($this->body)) {
            return null;
        }

        return json_decode($this->body, true);
    }

    /**
     * Returns the response headers as an associative array.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Returns the value of a specific header. The search is case-insensitive.
     *
     * @param string $name The name of the header (e.g., 'Content-Type').
     * @return string|null The header value or null if not found.
     */
    public function header(string $name): ?string
    {
        $lowerName = strtolower($name);
        // In a real-world scenario, response headers might not be pre-processed.
        // This assumes headers are already in a key-value format.
        // If headers come from curl's HEADERFUNCTION, they need parsing first.
        // For simplicity, we'll assume a pre-parsed associative array.
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $lowerName) {
                return is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return null;
    }

    /**
     * Returns the complete technical information array from the cURL transfer.
     */
    public function info(): array
    {
        return $this->info;
    }

    /**
     * Returns a specific value from the transfer's technical information.
     * For example, 'total_time', 'primary_ip'.
     *
     * @param int|string $key The curl_getinfo() constant (e.g., CURLINFO_TOTAL_TIME) or its string name.
     * @return mixed The requested value or null if not found.
     */
    public function infoKey(int|string $key): mixed
    {
        return $this->info[$key] ?? null;
    }

    /**
     * Checks if the request was successful (2xx status code).
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Checks if the response is a redirection (3xx status code).
     */
    public function isRedirection(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Checks if the response indicates a client-side error (4xx status code).
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Checks if the response indicates a server-side error (5xx status code).
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Checks if a connection error occurred (transport-level error).
     * This is our case where the status code is 0.
     */
    public function isConnectionError(): bool
    {
        return $this->statusCode === 0;
    }
}