<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

/**
 * Thrown when a connection to the remote server cannot be established.
 *
 * This exception indicates a transport-level failure that occurs before any HTTP
 * communication takes place. Common causes include:
 * - DNS resolution failure (hostname not found)
 * - Connection refused (server not listening on port)
 * - Network unreachable
 * - Host unreachable
 *
 * Unlike RequestException, this means the request never reached the server,
 * so there is no HTTP response to inspect.
 *
 * ---
 * ### Example: Handling connection failures
 *
 * ```
 * use Flytachi\Winter\Cast\Cast;
 * use Flytachi\Winter\Cast\Exception\ConnectionException;
 *
 * try {
 *     $response = Cast::get('https://nonexistent-server.com')
 *         ->send();
 * } catch (ConnectionException $e) {
 *     // Connection failed - try backup server
 *     echo "Primary server unavailable: {$e->getMessage()}";
 *     $response = Cast::get('https://backup-server.com')->send();
 * }
 * ```
 * ---
 *
 * @package Flytachi\Winter\Cast\Exception
 * @version 1.0
 * @author Flytachi
 */
class ConnectionException extends CastException
{
}
