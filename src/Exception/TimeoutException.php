<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

/**
 * Thrown when a request exceeds the configured timeout limit.
 *
 * This exception can indicate two types of timeouts:
 * - **Connection timeout**: Failed to establish connection within the specified time
 * - **Request timeout**: Request took longer than the total timeout limit
 *
 * Timeouts can be configured using the `timeout()` and `connectTimeout()` methods
 * on CastRequest. Default values are set in CastClient (10s request, 5s connect).
 *
 * ---
 * ### Example: Handling timeouts with retry
 *
 * ```
 * use Flytachi\Winter\Cast\Cast;
 * use Flytachi\Winter\Cast\Exception\TimeoutException;
 *
 * try {
 *     $response = Cast::get('https://slow-api.com/data')
 *         ->timeout(5)
 *         ->send();
 * } catch (TimeoutException $e) {
 *     // Retry with longer timeout
 *     echo "Request timed out, retrying with 30s timeout...";
 *     $response = Cast::get('https://slow-api.com/data')
 *         ->timeout(30)
 *         ->send();
 * }
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Exception
 * @version 1.0
 * @author Flytachi
 * @see \Flytachi\Winter\Cast\Common\CastRequest::timeout()
 * @see \Flytachi\Winter\Cast\Common\CastRequest::connectTimeout()
 */
class TimeoutException extends CastException
{
}
