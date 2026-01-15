<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Stereotype;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Exception\RequestException;

/**
 * Abstract base class for building API service clients.
 *
 * Each ApiService subclass has its own CastClient instance, isolated from
 * the global Cast facade. Provides pre-configured base URL, headers, and
 * flexible request building with fluent API.
 *
 * ---
 * ### Example: Creating an API service
 *
 * ```
 * class PaymentApiService extends ApiService
 * {
 *     protected static function baseUrl(): string {
 *         return 'https://api.payment.com/v1';
 *     }
 *
 *     protected static function headers(): CastHeader {
 *         return CastHeader::instance()
 *             ->authBearer(env('PAYMENT_API_TOKEN'))
 *             ->json();
 *     }
 *
 *     // Simple GET request
 *     public static function getBalance(): array {
 *         $response = self::get('balance')->send(self::client());
 *         return self::tryResult($response);
 *     }
 *
 *     // POST with JSON body
 *     public static function createPayment(array $data): array {
 *         $response = self::post('payments')
 *             ->withJsonBody($data)
 *             ->send(self::client());
 *         return self::tryResult($response);
 *     }
 *
 *     // File upload with custom timeout
 *     public static function uploadFile(string $path): array {
 *         $response = self::post('files')
 *             ->withMultipartBody(['file' => new \CURLFile($path)])
 *             ->timeout(60)
 *             ->send(self::client());
 *         return self::tryResult($response);
 *     }
 *
 *     // GET with query params
 *     public static function search(string $query, int $page = 1): array {
 *         $response = self::get('search')
 *             ->withQueryParams(['q' => $query, 'page' => $page])
 *             ->send(self::client());
 *         return self::tryResult($response);
 *     }
 *
 *     // Custom client with middleware
 *     protected static function createClient(): CastClient {
 *         return (new CastClient(defaultTimeout: 30))
 *             ->addMiddleware(new LoggingMiddleware($logger));
 *     }
 * }
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Stereotype
 * @author Flytachi
 */
abstract class ApiService
{
    /** @var array<string, CastClient> Clients per service class */
    private static array $clients = [];

    /**
     * Returns the base URL for all API requests.
     *
     * @return string Base URL without a trailing slash (e.g., 'https://api.example.com/v1')
     */
    abstract protected static function baseUrl(): string;

    /**
     * Returns the default headers for all API requests.
     *
     * @return CastHeader Headers instance with authentication, content-type, etc.
     */
    abstract protected static function headers(): CastHeader;

    /**
     * Private constructor to enforce static-only usage.
     */
    private function __construct() {}

    // =========================================================================
    // Client Management
    // =========================================================================

    /**
     * Returns the CastClient for this service (lazy-loaded singleton per class).
     *
     * @return CastClient
     */
    protected static function client(): CastClient
    {
        $class = static::class;

        if (!isset(self::$clients[$class])) {
            self::$clients[$class] = static::createClient();
        }

        return self::$clients[$class];
    }

    /**
     * Creates a new CastClient for this service.
     *
     * Override to customize client settings (timeouts, middleware, etc.)
     *
     * @return CastClient
     */
    protected static function createClient(): CastClient
    {
        return new CastClient();
    }

    /**
     * Replaces the client for this service.
     *
     * Useful for testing or runtime configuration changes.
     *
     * @param CastClient $client
     */
    public static function setClient(CastClient $client): void
    {
        self::$clients[static::class] = $client;
    }

    // =========================================================================
    // HTTP Methods (return CastRequest for flexibility)
    // =========================================================================

    /**
     * Creates a GET request with pre-configured URL, headers and client.
     *
     * @param string $path Relative path (e.g., 'users' or 'users/123')
     * @return CastRequest
     */
    protected static function get(string $path): CastRequest
    {
        return static::request(CastRequest::get(static::url($path)));
    }

    /**
     * Creates a POST request with pre-configured URL, headers and client.
     *
     * @param string $path Relative path
     * @return CastRequest
     */
    protected static function post(string $path): CastRequest
    {
        return static::request(CastRequest::post(static::url($path)));
    }

    /**
     * Creates a PUT request with pre-configured URL, headers and client.
     *
     * @param string $path Relative path
     * @return CastRequest
     */
    protected static function put(string $path): CastRequest
    {
        return static::request(CastRequest::put(static::url($path)));
    }

    /**
     * Creates a PATCH request with pre-configured URL, headers and client.
     *
     * @param string $path Relative path
     * @return CastRequest
     */
    protected static function patch(string $path): CastRequest
    {
        return static::request(CastRequest::patch(static::url($path)));
    }

    /**
     * Creates a DELETE request with pre-configured URL, headers and client.
     *
     * @param string $path Relative path
     * @return CastRequest
     */
    protected static function delete(string $path): CastRequest
    {
        return static::request(CastRequest::delete(static::url($path)));
    }

    /**
     * Creates a HEAD request with pre-configured URL, headers and client.
     *
     * @param string $path Relative path
     * @return CastRequest
     */
    protected static function head(string $path): CastRequest
    {
        return static::request(CastRequest::head(static::url($path)));
    }

    /**
     * Configures a request with default headers.
     *
     * @param CastRequest $request
     * @return CastRequest
     */
    private static function request(CastRequest $request): CastRequest
    {
        return $request->withHeaders(static::headers());
    }

    // =========================================================================
    // Response Handling
    // =========================================================================

    /**
     * Processes the response and extracts the result data.
     *
     * On success, returns the 'data' field from JSON response (or full body if no 'data' field).
     * On error (4xx/5xx), throws RequestException with error message from response.
     *
     * Override this method to customize response parsing for specific APIs.
     *
     * @param CastResponse $response The HTTP response
     * @param string $dataKey The key to extract from JSON response (default: 'data')
     * @return mixed The extracted data
     * @throws RequestException If the response indicates an error
     */
    protected static function tryResult(CastResponse $response, string $dataKey = 'data'): mixed
    {
        $body = $response->json();

        if (!$response->isSuccess()) {
            $message = static::extractErrorMessage($body, $response->statusCode);
            throw new RequestException($response, $message);
        }

        // Return specific key if exists, otherwise return full body
        if ($body !== null && array_key_exists($dataKey, $body)) {
            return $body[$dataKey];
        }

        return $body;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Builds full URL from base URL and path.
     *
     * @param string $path Relative path
     * @return string Full URL
     */
    protected static function url(string $path): string
    {
        $base = rtrim(static::baseUrl(), '/');
        $path = ltrim($path, '/');

        return "{$base}/{$path}";
    }

    /**
     * Extracts error message from response body.
     *
     * Override to customize error message extraction for specific APIs.
     *
     * @param array|null $body Parsed JSON body
     * @param int $statusCode HTTP status code
     * @return string Error message
     */
    protected static function extractErrorMessage(?array $body, int $statusCode): string
    {
        if ($body !== null) {
            // Common error message fields
            foreach (['message', 'error', 'error_message', 'msg', 'detail'] as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    return $body[$key];
                }
            }

            // Nested error object
            if (isset($body['error']) && is_array($body['error'])) {
                if (isset($body['error']['message'])) {
                    return $body['error']['message'];
                }
            }
        }

        return "HTTP Error {$statusCode}";
    }
}
