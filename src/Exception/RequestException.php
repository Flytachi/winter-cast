<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

use Flytachi\Winter\Cast\Common\CastResponse;

/**
 * Thrown when an HTTP request completes but returns a non-successful status code.
 *
 * This exception is raised when `throwOnError()` is enabled and the server responds
 * with a status code outside the 2xx range (e.g., 4xx client errors, 5xx server errors).
 *
 * Unlike transport-level exceptions (ConnectionException, TimeoutException), this means
 * the request successfully reached the server and received a response, but the response
 * indicates a failure.
 *
 * The exception contains the full CastResponse object, allowing you to inspect
 * the status code, headers, and body of the failed response.
 *
 * ---
 * ### Example: Handling HTTP errors
 *
 * ```
 * use Flytachi\Winter\Cast\Cast;
 * use Flytachi\Winter\Cast\Exception\RequestException;
 *
 * try {
 *     $user = Cast::get('https://api.com/user/999')
 *         ->throwOnError()
 *         ->send()
 *         ->json();
 * } catch (RequestException $e) {
 *     // Access the response object
 *     if ($e->response->statusCode === 404) {
 *         echo "User not found";
 *     } elseif ($e->response->statusCode === 403) {
 *         echo "Access denied";
 *     } else {
 *         echo "HTTP Error {$e->response->statusCode}: {$e->response->body()}";
 *     }
 * }
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Exception
 * @version 1.0
 * @author Flytachi
 * @see \Flytachi\Winter\Cast\Common\CastResponse
 */
class RequestException extends CastException
{
    public function __construct(
        public readonly CastResponse $response,
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
