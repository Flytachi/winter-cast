<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Exception;

/**
 * Thrown when a request times out.
 * This includes both connection timeout and total request timeout.
 */
class TimeoutException extends CastException
{
}
