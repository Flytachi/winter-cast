<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

/**
 * Thrown when a connection to the server cannot be established.
 * This includes connection refused, host not found, etc.
 */
class ConnectionException extends CastException
{
}
